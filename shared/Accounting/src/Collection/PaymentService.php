<?php

declare(strict_types=1);

namespace School\Accounting\Collection;

use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Billing\InvoiceService;
use School\Accounting\Billing\StudentDirectory;
use School\Accounting\Ledger\PostingService;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Fee collection. A payment is split over the student's outstanding lines
 * (oldest due first, or only the invoices the collector picked); any extra is
 * kept as advance and applied to later invoices. Receipts are never deleted:
 * a wrong one is reversed with opposite allocation rows.
 */
final class PaymentService
{
    public const CONFIRMED = 'confirmed';
    public const REVERSED = 'reversed';

    public const METHOD_CASH = 'cash';

    public const CUSTODY_TEACHER = 'teacher';
    public const CUSTODY_OFFICE = 'office';

    public const KIND_FEE = 'fee';
    public const KIND_ADVANCE = 'advance';
    public const KIND_ADVANCE_APPLIED = 'advance_applied';
    public const KIND_REVERSAL = 'reversal';
    public const KIND_REFUND = 'refund';

    public const STATUS_LABELS = [self::CONFIRMED => 'বৈধ', self::REVERSED => 'রিভার্সড'];
    public const METHOD_LABELS = ['cash' => 'নগদ', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'bank' => 'ব্যাংক'];
    public const CUSTODY_LABELS = [self::CUSTODY_TEACHER => 'শিক্ষকের কাছে', self::CUSTODY_OFFICE => 'অফিসে', 'bkash' => 'bKash হিসাবে', 'nagad' => 'Nagad হিসাবে'];

    public function __construct(
        private PDO $db,
        private InvoiceService $invoices,
        private StudentDirectory $students,
        private ?PostingService $posting = null
    )
    {
    }

    public static function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Outstanding lines of live invoices, oldest due first; a fine follows its fee.
     *
     * @return list<array<string, mixed>>
     */
    public function outstandingLines(int $studentId, bool $lock = false): array
    {
        return \fetchRows(
            $this->db,
            "SELECT i.id, i.invoice_id, i.description, i.line_kind, i.net, i.paid, i.due_date,
                    inv.invoice_no, inv.billing_label, inv.due_date AS invoice_due
             FROM acc_invoice_items i
             JOIN acc_invoices inv ON inv.id = i.invoice_id
             WHERE i.student_id = :student AND inv.status <> 'cancelled' AND i.net > i.paid
             ORDER BY inv.due_date, inv.id, CASE WHEN i.line_kind = 'fee' THEN 0 ELSE 1 END, i.id"
            . ($lock ? ' FOR UPDATE' : ''),
            ['student' => $studentId]
        );
    }

    /** Unused advance in paisa. */
    public function advanceBalance(int $studentId): int
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT COALESCE(SUM(amount), 0) AS balance FROM acc_payment_allocations WHERE student_id = :student AND invoice_item_id IS NULL',
            ['student' => $studentId]
        );

        return Money::fromDb($rows[0]['balance'] ?? '0');
    }

    /**
     * @param list<int> $studentIds
     * @return array<int, int> student_id => unused advance in paisa (only non-zero)
     */
    public function advanceMap(array $studentIds): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0));
        if ($studentIds === []) {
            return [];
        }
        $map = [];
        foreach (\fetchRows(
            $this->db,
            'SELECT student_id, SUM(amount) AS balance FROM acc_payment_allocations
             WHERE student_id IN (' . implode(',', $studentIds) . ') AND invoice_item_id IS NULL
             GROUP BY student_id HAVING SUM(amount) <> 0'
        ) as $row) {
            $map[(int) $row['student_id']] = Money::fromDb($row['balance']);
        }

        return $map;
    }

    /**
     * @param list<int> $invoiceIds only settle these invoices; empty = all outstanding
     * @return array{payment_id: int, duplicate: bool}
     */
    public function collect(
        AccessContext $actor,
        AuditTrail $audit,
        int $studentId,
        string $amountInput,
        string $idempotencyKey,
        array $invoiceIds,
        bool $allowAdvance,
        string $payerName,
        string $note
    ): array {
        $student = $this->students->requireInScope($actor, Capability::FEES_COLLECT, $studentId);
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        $amount = Money::parse($amountInput, 'আদায়ের পরিমাণ');
        if ($amount < 1) {
            throw new RuntimeException('আদায়ের পরিমাণ শূন্যের বেশি হতে হবে।');
        }
        $payerName = FeeCatalog::text($payerName, 120);
        $note = FeeCatalog::text($note, 255);
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));

        $existing = $this->byIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->duplicateOf($existing, $studentId);
        }

        $this->db->beginTransaction();
        try {
            $lines = $this->outstandingLines($studentId, true);
            if ($invoiceIds !== []) {
                $lines = array_values(array_filter($lines, static fn (array $line): bool => in_array((int) $line['invoice_id'], $invoiceIds, true)));
            }
            $split = Allocator::allocate(
                array_map(static fn (array $line): array => [
                    'id' => (int) $line['id'],
                    'outstanding' => Money::fromDb($line['net']) - Money::fromDb($line['paid']),
                ], $lines),
                $amount
            );
            if ($split['remainder'] > 0 && !$allowAdvance) {
                $due = $amount - $split['remainder'];
                throw new RuntimeException('পরিমাণটি নির্বাচিত বকেয়ার (' . Money::format($due) . ') চেয়ে বেশি। অতিরিক্ত টাকা অগ্রিম হিসেবে রাখতে চাইলে "অগ্রিম" টিক দিন।');
            }

            $now = AuditTrail::now();
            $receiptNo = Sequence::format('RCT', $now->format('Y'), Sequence::next($this->db, 'RCT', $now->format('Y')));
            $custody = $actor->can(Capability::HANDOVER_RECEIVE) ? self::CUSTODY_OFFICE : self::CUSTODY_TEACHER;
            $this->db->prepare(
                'INSERT INTO acc_payments
                    (receipt_no, idempotency_key, student_id, session_year, class_id, section_id, roll_snapshot, name_snapshot,
                     amount, method, status, custody, collected_custody, collected_by, collected_at, payer_name, note)
                 VALUES (:receipt, :key, :student, :session, :class, :section, :roll, :name,
                         :amount, :method, :status, :custody, :custody2, :actor, :now, :payer, :note)'
            )->execute([
                'receipt' => $receiptNo,
                'key' => $idempotencyKey,
                'student' => $studentId,
                'session' => (string) $student['session_year'],
                'class' => (int) $student['cls'],
                'section' => StudentDirectory::sectionIdOf($student),
                'roll' => mb_substr((string) $student['cls_roll'], 0, 20),
                'name' => mb_substr(StudentDirectory::displayName($student), 0, 255),
                'amount' => Money::toDb($amount),
                'method' => self::METHOD_CASH,
                'status' => self::CONFIRMED,
                'custody' => $custody,
                'custody2' => $custody,
                'actor' => $actor->teacherId(),
                'now' => $now->format('Y-m-d H:i:s'),
                'payer' => $payerName,
                'note' => $note,
            ]);
            $paymentId = (int) $this->db->lastInsertId();

            $byId = [];
            foreach ($lines as $line) {
                $byId[(int) $line['id']] = $line;
            }
            $touched = [];
            foreach ($split['lines'] as $itemId => $paisa) {
                $this->allocate($paymentId, $studentId, $itemId, (int) $byId[$itemId]['invoice_id'], $paisa, self::KIND_FEE, $actor);
                $touched[(int) $byId[$itemId]['invoice_id']] = true;
            }
            if ($split['remainder'] > 0) {
                $this->allocate($paymentId, $studentId, null, null, $split['remainder'], self::KIND_ADVANCE, $actor);
            }
            foreach (array_keys($touched) as $invoiceId) {
                $this->invoices->recompute($invoiceId);
            }
            $this->posting?->postPayment($paymentId);
            $audit->record(
                'payment.collect',
                'payment',
                $paymentId,
                null,
                [
                    'receipt_no' => $receiptNo,
                    'student_id' => $studentId,
                    'amount' => Money::toDb($amount),
                    'custody' => $custody,
                    'lines' => array_map(static fn (int $paisa): string => Money::toDb($paisa), $split['lines']),
                    'advance' => Money::toDb($split['remainder']),
                ],
                'ফি আদায় ' . $receiptNo . ': ' . StudentDirectory::displayName($student) . ' — ' . Money::format($amount)
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            $raced = $exception->getCode() === '23000' ? $this->byIdempotencyKey($idempotencyKey) : null;
            if ($raced !== null) {
                return $this->duplicateOf($raced, $studentId);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['payment_id' => $paymentId, 'duplicate' => false];
    }

    /**
     * Uses the student's advance on outstanding lines, oldest advance first.
     *
     * @return int paisa applied
     */
    public function applyAdvance(AccessContext $actor, AuditTrail $audit, int $studentId): int
    {
        $this->students->requireInScope($actor, Capability::FEES_COLLECT, $studentId);
        $this->db->beginTransaction();
        try {
            \fetchRows(
                $this->db,
                'SELECT id FROM acc_payment_allocations WHERE student_id = :student AND invoice_item_id IS NULL FOR UPDATE',
                ['student' => $studentId]
            );
            $credits = \fetchRows(
                $this->db,
                'SELECT payment_id, SUM(amount) AS balance FROM acc_payment_allocations
                 WHERE student_id = :student AND invoice_item_id IS NULL
                 GROUP BY payment_id HAVING SUM(amount) > 0 ORDER BY payment_id',
                ['student' => $studentId]
            );
            if ($credits === []) {
                $this->db->commit();

                return 0;
            }
            $lines = $this->outstandingLines($studentId, true);
            $remaining = array_map(static fn (array $line): array => [
                'id' => (int) $line['id'],
                'invoice_id' => (int) $line['invoice_id'],
                'outstanding' => Money::fromDb($line['net']) - Money::fromDb($line['paid']),
            ], $lines);
            $applied = 0;
            $touched = [];
            $creditedPayments = [];
            foreach ($credits as $credit) {
                $paymentId = (int) $credit['payment_id'];
                $split = Allocator::allocate($remaining, Money::fromDb($credit['balance']));
                foreach ($split['lines'] as $itemId => $paisa) {
                    foreach ($remaining as &$line) {
                        if ($line['id'] === $itemId) {
                            $line['outstanding'] -= $paisa;
                            $this->allocate($paymentId, $studentId, $itemId, $line['invoice_id'], $paisa, self::KIND_ADVANCE_APPLIED, $actor);
                            $this->allocate($paymentId, $studentId, null, null, -$paisa, self::KIND_ADVANCE_APPLIED, $actor);
                            $touched[$line['invoice_id']] = true;
                            $creditedPayments[$paymentId] = true;
                        }
                    }
                    unset($line);
                    $applied += $paisa;
                }
            }
            foreach (array_keys($touched) as $invoiceId) {
                $this->invoices->recompute($invoiceId);
            }
            foreach (array_keys($creditedPayments) as $creditedPayment) {
                $this->posting?->postPayment($creditedPayment);
            }
            if ($applied > 0) {
                $audit->record(
                    'payment.advance_apply',
                    'student',
                    $studentId,
                    null,
                    ['applied' => Money::toDb($applied)],
                    'অগ্রিম থেকে সমন্বয়: ' . Money::format($applied)
                );
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $applied;
    }

    public function reverse(AccessContext $actor, AuditTrail $audit, int $paymentId, string $reason): void
    {
        if (!$actor->can(Capability::PAYMENT_REVERSE)) {
            throw AccessDeniedException::forCapability(Capability::PAYMENT_REVERSE);
        }
        $reason = FeeCatalog::text($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 3) {
            throw new RuntimeException('রিভার্সের কারণ লিখুন।');
        }
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT * FROM acc_payments WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $paymentId]);
            $payment = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new RuntimeException('রসিদটি পাওয়া যায়নি।');
            }
            if ($payment['status'] !== self::CONFIRMED) {
                throw new RuntimeException('রসিদটি আগেই রিভার্স করা হয়েছে।');
            }
            if ($payment['method'] !== self::METHOD_CASH) {
                throw new RuntimeException('অনলাইন পেমেন্ট রিভার্স করা যায় না; টাকা ফেরত দিতে রিফান্ড অনুরোধ করুন।');
            }
            if ($payment['handover_id'] !== null) {
                $handover = \fetchRows($this->db, 'SELECT status FROM acc_handovers WHERE id = :id', ['id' => (int) $payment['handover_id']]);
                if (($handover[0]['status'] ?? '') === HandoverService::SUBMITTED) {
                    throw new RuntimeException('রসিদটি একটি অনিষ্পন্ন হস্তান্তরে আছে; আগে হস্তান্তরটি গ্রহণ বা ফেরত দিন।');
                }
            }
            $studentId = (int) $payment['student_id'];
            $net = \fetchRows(
                $this->db,
                'SELECT invoice_item_id, MAX(invoice_id) AS invoice_id, SUM(amount) AS amount
                 FROM acc_payment_allocations WHERE payment_id = :id GROUP BY invoice_item_id',
                ['id' => $paymentId]
            );
            $advanceLeft = 0;
            foreach ($net as $row) {
                if ($row['invoice_item_id'] === null) {
                    $advanceLeft = Money::fromDb($row['amount']);
                }
            }
            $touched = [];
            foreach ($net as $row) {
                $paisa = Money::fromDb($row['amount']);
                if ($paisa === 0) {
                    continue;
                }
                $itemId = $row['invoice_item_id'] === null ? null : (int) $row['invoice_item_id'];
                $invoiceId = $row['invoice_id'] === null ? null : (int) $row['invoice_id'];
                $this->allocate($paymentId, $studentId, $itemId, $invoiceId, -$paisa, self::KIND_REVERSAL, $actor);
                if ($invoiceId !== null) {
                    $touched[$invoiceId] = true;
                }
            }
            foreach (array_keys($touched) as $invoiceId) {
                $this->invoices->recompute($invoiceId);
            }
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            $this->db->prepare(
                'UPDATE acc_payments SET status = :status, reversed_by = :actor, reversed_at = :now, reversal_reason = :reason WHERE id = :id'
            )->execute(['status' => self::REVERSED, 'actor' => $actor->teacherId(), 'now' => $now, 'reason' => $reason, 'id' => $paymentId]);
            $this->posting?->postPayment($paymentId);
            $audit->record(
                'payment.reverse',
                'payment',
                $paymentId,
                ['status' => self::CONFIRMED, 'amount' => $payment['amount'], 'custody' => $payment['custody']],
                ['status' => self::REVERSED, 'reason' => $reason, 'advance_reversed' => Money::toDb($advanceLeft)],
                'রসিদ রিভার্স: ' . $payment['receipt_no']
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /**
     * Records a gateway payment that the gateway itself confirmed. Runs inside
     * the caller's transaction (which holds the gateway transaction row lock).
     * Money already received is never rejected: whatever the selected invoices
     * no longer need is kept as advance.
     *
     * @param array<string, mixed> $txn acc_gateway_transactions row
     */
    public function recordOnline(AccessContext $system, AuditTrail $audit, array $txn, string $gatewayLabel): int
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Online payments must be recorded inside a transaction.');
        }
        $studentId = (int) $txn['student_id'];
        $student = $this->students->find($studentId);
        if ($student === null) {
            throw new RuntimeException('শিক্ষার্থী পাওয়া যায়নি।');
        }
        $amount = Money::fromDb($txn['fee_amount']);
        $invoiceIds = array_values(array_filter(array_map('intval', explode(',', (string) $txn['invoice_ids']))));
        $lines = $this->outstandingLines($studentId, true);
        if ($invoiceIds !== []) {
            $lines = array_values(array_filter($lines, static fn (array $line): bool => in_array((int) $line['invoice_id'], $invoiceIds, true)));
        }
        $split = Allocator::allocate(
            array_map(static fn (array $line): array => [
                'id' => (int) $line['id'],
                'outstanding' => Money::fromDb($line['net']) - Money::fromDb($line['paid']),
            ], $lines),
            $amount
        );
        $now = AuditTrail::now();
        $receiptNo = Sequence::format('RCT', $now->format('Y'), Sequence::next($this->db, 'RCT', $now->format('Y')));
        $method = (string) $txn['gateway'];
        $this->db->prepare(
            'INSERT INTO acc_payments
                (receipt_no, idempotency_key, student_id, session_year, class_id, section_id, roll_snapshot, name_snapshot,
                 amount, method, status, custody, collected_custody, collected_by, collected_at, payer_name, note, gateway_txn_id)
             VALUES (:receipt, :key, :student, :session, :class, :section, :roll, :name,
                     :amount, :method, :status, :custody, :custody2, 0, :now, :payer, :note, :txn)'
        )->execute([
            'receipt' => $receiptNo,
            'key' => (string) $txn['idempotency_key'],
            'student' => $studentId,
            'session' => (string) $student['session_year'],
            'class' => (int) $student['cls'],
            'section' => StudentDirectory::sectionIdOf($student),
            'roll' => mb_substr((string) $student['cls_roll'], 0, 20),
            'name' => mb_substr(StudentDirectory::displayName($student), 0, 255),
            'amount' => Money::toDb($amount),
            'method' => $method,
            'status' => self::CONFIRMED,
            'custody' => $method,
            'custody2' => $method,
            'now' => $now->format('Y-m-d H:i:s'),
            'payer' => 'অনলাইন — ' . $gatewayLabel,
            'note' => mb_substr($gatewayLabel . ' TrxID ' . $txn['trx_id'] . ', ইনভয়েস ' . $txn['merchant_invoice_no'], 0, 255),
            'txn' => (int) $txn['id'],
        ]);
        $paymentId = (int) $this->db->lastInsertId();
        $byId = [];
        foreach ($lines as $line) {
            $byId[(int) $line['id']] = $line;
        }
        $touched = [];
        foreach ($split['lines'] as $itemId => $paisa) {
            $this->allocate($paymentId, $studentId, $itemId, (int) $byId[$itemId]['invoice_id'], $paisa, self::KIND_FEE, $system);
            $touched[(int) $byId[$itemId]['invoice_id']] = true;
        }
        if ($split['remainder'] > 0) {
            $this->allocate($paymentId, $studentId, null, null, $split['remainder'], self::KIND_ADVANCE, $system);
        }
        foreach (array_keys($touched) as $invoiceId) {
            $this->invoices->recompute($invoiceId);
        }
        $this->posting?->postPayment($paymentId);
        $audit->record(
            'payment.online',
            'payment',
            $paymentId,
            null,
            [
                'receipt_no' => $receiptNo,
                'student_id' => $studentId,
                'amount' => Money::toDb($amount),
                'gateway' => $method,
                'trx_id' => (string) $txn['trx_id'],
                'lines' => array_map(static fn (int $paisa): string => Money::toDb($paisa), $split['lines']),
                'advance' => Money::toDb($split['remainder']),
            ],
            'অনলাইন ফি ' . $receiptNo . ' (' . $gatewayLabel . '): ' . StudentDirectory::displayName($student) . ' — ' . Money::format($amount)
        );

        return $paymentId;
    }

    /** Amount of this receipt still sitting on fee lines or advance (what a refund can take back). */
    public function refundable(int $paymentId): int
    {
        $total = 0;
        foreach (\fetchRows(
            $this->db,
            'SELECT invoice_item_id, SUM(amount) AS amount FROM acc_payment_allocations WHERE payment_id = :id GROUP BY invoice_item_id',
            ['id' => $paymentId]
        ) as $row) {
            $total += max(0, Money::fromDb($row['amount']));
        }

        return $total;
    }

    /**
     * Takes $amount back from a receipt for a completed refund: unused advance
     * first, then the most recently paid fee lines. Runs inside the caller's transaction.
     */
    public function refundAllocations(AccessContext $actor, int $paymentId, int $amount): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Refund allocations must run inside a transaction.');
        }
        $payment = \fetchRows($this->db, 'SELECT student_id FROM acc_payments WHERE id = :id FOR UPDATE', ['id' => $paymentId])[0] ?? null;
        if ($payment === null) {
            throw new RuntimeException('রসিদটি পাওয়া যায়নি।');
        }
        $net = \fetchRows(
            $this->db,
            'SELECT invoice_item_id, MAX(invoice_id) AS invoice_id, SUM(amount) AS amount, MAX(id) AS last_id
             FROM acc_payment_allocations WHERE payment_id = :id GROUP BY invoice_item_id
             ORDER BY invoice_item_id IS NOT NULL, last_id DESC',
            ['id' => $paymentId]
        );
        $left = $amount;
        $touched = [];
        foreach ($net as $row) {
            $available = Money::fromDb($row['amount']);
            if ($left <= 0 || $available <= 0) {
                continue;
            }
            $take = min($left, $available);
            $itemId = $row['invoice_item_id'] === null ? null : (int) $row['invoice_item_id'];
            $invoiceId = $row['invoice_id'] === null ? null : (int) $row['invoice_id'];
            $this->allocate($paymentId, (int) $payment['student_id'], $itemId, $invoiceId, -$take, self::KIND_REFUND, $actor);
            if ($invoiceId !== null) {
                $touched[$invoiceId] = true;
            }
            $left -= $take;
        }
        if ($left > 0) {
            throw new RuntimeException('রিফান্ডের পরিমাণ রসিদের অবশিষ্ট টাকার চেয়ে বেশি।');
        }
        foreach (array_keys($touched) as $invoiceId) {
            $this->invoices->recompute($invoiceId);
        }
        $this->posting?->postPayment($paymentId);
    }

    public function setSmsStatus(int $paymentId, string $status): void
    {
        $this->db->prepare('UPDATE acc_payments SET sms_status = :status WHERE id = :id')
            ->execute(['status' => mb_substr($status, 0, 20), 'id' => $paymentId]);
    }

    /** @return array<string, mixed>|null */
    public function payment(int $paymentId): ?array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT p.*, c.class_name_ban, sec.section_name_ban, t.teacher_name AS collected_by_name, r.teacher_name AS reversed_by_name
             FROM acc_payments p
             LEFT JOIN cls c ON c.id = p.class_id
             LEFT JOIN sections sec ON sec.id = p.section_id
             LEFT JOIN teacher_info t ON t.id = p.collected_by
             LEFT JOIN teacher_info r ON r.id = p.reversed_by
             WHERE p.id = :id',
            ['id' => $paymentId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Lines this receipt originally paid (reversal rows excluded) and the advance it created.
     *
     * @return array{lines: list<array<string, mixed>>, advance: int}
     */
    public function receiptLines(int $paymentId): array
    {
        $rows = \fetchRows(
            $this->db,
            "SELECT a.invoice_item_id, SUM(a.amount) AS amount, i.description, inv.invoice_no, inv.billing_label
             FROM acc_payment_allocations a
             LEFT JOIN acc_invoice_items i ON i.id = a.invoice_item_id
             LEFT JOIN acc_invoices inv ON inv.id = a.invoice_id
             WHERE a.payment_id = :id AND a.kind IN ('fee', 'advance')
             GROUP BY a.invoice_item_id, i.description, inv.invoice_no, inv.billing_label
             ORDER BY MIN(a.id)",
            ['id' => $paymentId]
        );
        $lines = [];
        $advance = 0;
        foreach ($rows as $row) {
            if ($row['invoice_item_id'] === null) {
                $advance += Money::fromDb($row['amount']);
                continue;
            }
            $lines[] = $row;
        }

        return ['lines' => $lines, 'advance' => $advance];
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(int $studentId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT p.*, t.teacher_name AS collected_by_name FROM acc_payments p
             LEFT JOIN teacher_info t ON t.id = p.collected_by
             WHERE p.student_id = :student ORDER BY p.collected_at DESC, p.id DESC',
            ['student' => $studentId]
        );
    }

    /**
     * @param array{from: string, to: string, collector?: int, class_id?: int, section_id?: int, status?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function search(array $filter, int $limit = 1000): array
    {
        $sql = 'SELECT p.*, t.teacher_name AS collected_by_name, c.class_name_ban, sec.section_name_ban, h.handover_no, h.status AS handover_status
                FROM acc_payments p
                LEFT JOIN teacher_info t ON t.id = p.collected_by
                LEFT JOIN cls c ON c.id = p.class_id
                LEFT JOIN sections sec ON sec.id = p.section_id
                LEFT JOIN acc_handovers h ON h.id = p.handover_id
                WHERE p.collected_at >= :from AND p.collected_at < :to';
        $params = ['from' => $filter['from'] . ' 00:00:00', 'to' => (new \DateTimeImmutable($filter['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
        if (($filter['collector'] ?? 0) > 0) {
            $sql .= ' AND p.collected_by = :collector';
            $params['collector'] = $filter['collector'];
        }
        if (($filter['class_id'] ?? 0) > 0) {
            $sql .= ' AND p.class_id = :class';
            $params['class'] = $filter['class_id'];
            if (($filter['section_id'] ?? 0) > 0) {
                $sql .= ' AND p.section_id = :section';
                $params['section'] = $filter['section_id'];
            }
        }
        if (isset(self::STATUS_LABELS[$filter['status'] ?? ''])) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $filter['status'];
        }
        $sql .= ' ORDER BY p.collected_at DESC, p.id DESC LIMIT ' . max(1, min(5000, $limit));

        return \fetchRows($this->db, $sql, $params);
    }

    /** @return list<array<string, mixed>> teachers who collected and have not handed over */
    public function teacherCustody(): array
    {
        return \fetchRows(
            $this->db,
            "SELECT p.collected_by, t.teacher_name, COUNT(*) AS receipts, SUM(p.amount) AS total, MIN(p.collected_at) AS oldest
             FROM acc_payments p
             LEFT JOIN teacher_info t ON t.id = p.collected_by
             WHERE p.status = 'confirmed' AND p.custody = 'teacher' AND p.handover_id IS NULL
             GROUP BY p.collected_by, t.teacher_name ORDER BY oldest"
        );
    }

    private function allocate(int $paymentId, int $studentId, ?int $itemId, ?int $invoiceId, int $paisa, string $kind, AccessContext $actor): void
    {
        $this->db->prepare(
            'INSERT INTO acc_payment_allocations (payment_id, student_id, invoice_item_id, invoice_id, amount, kind, created_by, created_at)
             VALUES (:payment, :student, :item, :invoice, :amount, :kind, :actor, :now)'
        )->execute([
            'payment' => $paymentId,
            'student' => $studentId,
            'item' => $itemId,
            'invoice' => $invoiceId,
            'amount' => Money::toDb($paisa),
            'kind' => $kind,
            'actor' => $actor->teacherId(),
            'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
        ]);
        if ($itemId !== null) {
            $this->db->prepare('UPDATE acc_invoice_items SET paid = paid + :amount WHERE id = :id')
                ->execute(['amount' => Money::toDb($paisa), 'id' => $itemId]);
        }
    }

    /** @return array<string, mixed>|null */
    private function byIdempotencyKey(string $key): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT id, student_id FROM acc_payments WHERE idempotency_key = :key', ['key' => $key]);

        return $rows[0] ?? null;
    }

    /** @return array{payment_id: int, duplicate: bool} */
    private function duplicateOf(array $existing, int $studentId): array
    {
        if ((int) $existing['student_id'] !== $studentId) {
            throw new RuntimeException('ফর্মটি অন্য শিক্ষার্থীর; পাতাটি আবার খুলে চেষ্টা করুন।');
        }

        return ['payment_id' => (int) $existing['id'], 'duplicate' => true];
    }
}
