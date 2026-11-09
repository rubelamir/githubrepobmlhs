<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Invoice generation, totals and cancellation. Late fines are not charged.
 * Invoices are never deleted; a cancelled invoice keeps its number and lines.
 */
final class InvoiceService
{
    public const UNPAID = 'unpaid';
    public const PARTIAL = 'partial';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';

    public const LINE_FEE = 'fee';
    public const LINE_LATE_FINE = 'late_fine';

    public const STATUS_LABELS = [
        self::UNPAID => 'অপরিশোধিত',
        self::PARTIAL => 'আংশিক পরিশোধিত',
        self::PAID => 'পরিশোধিত',
        self::CANCELLED => 'বাতিল',
    ];

    public const STATUS_BADGES = [
        self::UNPAID => 'text-bg-danger',
        self::PARTIAL => 'text-bg-warning',
        self::PAID => 'text-bg-success',
        self::CANCELLED => 'text-bg-secondary',
    ];

    public function __construct(
        private PDO $db,
        private FeeCatalog $catalog,
        private FeeStructureRepository $structures,
        private StudentDirectory $students,
        private StudentCategoryService $categories,
        private ConcessionService $concessions
    ) {
    }

    /**
     * Bills every admitted student of a class/section for the selected fee heads.
     * Safe to run again: lines that already exist are skipped, so only students
     * or heads not yet billed for this period get a (new) invoice.
     *
     * @param list<int> $headIds empty = every active head of the period's kind
     * @return array{students: int, created: int, already: int, no_rate: int, zero: int}
     */
    public function generate(
        AccessContext $actor,
        AuditTrail $audit,
        string $sessionYear,
        int $classId,
        int $sectionId,
        BillingPeriod $period,
        array $headIds,
        DateTimeImmutable $issueDate
    ): array {
        if (!$actor->can(Capability::INVOICE_MANAGE)) {
            throw AccessDeniedException::forCapability(Capability::INVOICE_MANAGE);
        }
        FeeStructureRepository::assertSession($sessionYear);
        if ($period->kind() !== BillingPeriod::EXAM && $period->referenceDate()->format('Y') !== $sessionYear) {
            throw new RuntimeException('বিলের মাস/বছর নির্বাচিত সেশনের (' . \bnDigits($sessionYear) . ') মধ্যে হতে হবে।');
        }
        $sections = \schoolSections($this->db, $classId);
        if ($sections !== [] && $sectionId < 1) {
            throw new RuntimeException('এই শ্রেণীতে শাখা আছে—একটি শাখা নির্বাচন করুন।');
        }

        $heads = array_values(array_filter(
            $this->catalog->heads(true),
            static fn (array $head): bool => $head['billing_kind'] === $period->kind()
                && ($headIds === [] || in_array((int) $head['id'], $headIds, true))
        ));
        if ($heads === []) {
            throw new RuntimeException('এই ধরনের কোনো সক্রিয় ফি হেড নির্বাচন করা হয়নি।');
        }

        $students = $this->students->forClass($sessionYear, $classId, $sectionId);
        $result = ['students' => count($students), 'created' => 0, 'already' => 0, 'no_rate' => 0, 'zero' => 0];
        if ($students === []) {
            return $result;
        }

        $studentIds = array_map(static fn (array $student): int => (int) $student['id'], $students);
        $referenceDate = $period->referenceDate()->format('Y-m-d');
        $rates = $this->structures->rowsForClass($sessionYear, $classId, $sectionId);
        $categoryMap = $this->categories->currentMap($studentIds, $sessionYear, $referenceDate);
        $concessionMap = $this->concessions->activeMap($studentIds, $sessionYear, $referenceDate);
        $generalId = $this->catalog->defaultCategoryId();
        $existing = $this->existingKeys($studentIds, $period->key());
        $now = AuditTrail::now()->format('Y-m-d H:i:s');

        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $studentSection = StudentDirectory::sectionIdOf($student);
            $categoryId = isset($categoryMap[$studentId]) ? (int) $categoryMap[$studentId]['fee_category_id'] : $generalId;
            $lines = [];
            $hadExisting = false;
            $hadRate = false;
            foreach ($heads as $head) {
                $headId = (int) $head['id'];
                $rate = FeeStructureRepository::resolve($rates, $headId, $studentSection, $categoryId, $generalId, $referenceDate);
                if ($rate === null) {
                    continue;
                }
                $hadRate = true;
                $uniqKey = self::uniqKey($studentId, $headId, $period->key(), self::LINE_FEE);
                if (isset($existing[$uniqKey])) {
                    $hadExisting = true;
                    continue;
                }
                $concession = $concessionMap[$studentId][$headId] ?? null;
                $discount = $concession !== null ? ConcessionService::discountFor($concession, $rate['amount']) : 0;
                $lines[] = [
                    'head' => $head,
                    'uniq_key' => $uniqKey,
                    'rate' => $rate,
                    'discount' => $discount,
                    'concession_id' => $concession !== null ? (int) $concession['id'] : null,
                    'due_date' => $period->dueDate($rate['due_day'], $issueDate),
                ];
            }
            if ($lines === []) {
                if ($hadExisting) {
                    $result['already']++;
                } elseif (!$hadRate) {
                    $result['no_rate']++;
                }
                continue;
            }

            try {
                $invoiceId = $this->createInvoice($actor, $audit, $student, $sessionYear, $classId, $period, $issueDate, $lines, $now);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $result['already']++;
                    continue;
                }
                throw $exception;
            }
            $result['created']++;
            if (Money::fromDb($this->invoice($invoiceId)['net'] ?? '0') === 0) {
                $result['zero']++;
            }
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $lines */
    private function createInvoice(
        AccessContext $actor,
        AuditTrail $audit,
        array $student,
        string $sessionYear,
        int $classId,
        BillingPeriod $period,
        DateTimeImmutable $issueDate,
        array $lines,
        string $now
    ): int {
        $dueDate = min(array_map(static fn (array $line): DateTimeImmutable => $line['due_date'], $lines));
        $this->db->beginTransaction();
        try {
            $number = Sequence::format('INV', $sessionYear, Sequence::next($this->db, 'INV', $sessionYear));
            $this->db->prepare(
                'INSERT INTO acc_invoices
                    (invoice_no, student_id, session_year, class_id, section_id, roll_snapshot, name_snapshot,
                     billing_key, billing_label, issue_date, due_date, status, generated_by, created_at)
                 VALUES (:no, :student, :session, :class, :section, :roll, :name,
                         :billing_key, :billing_label, :issue, :due, :status, :actor, :now)'
            )->execute([
                'no' => $number,
                'student' => (int) $student['id'],
                'session' => $sessionYear,
                'class' => $classId,
                'section' => StudentDirectory::sectionIdOf($student),
                'roll' => mb_substr((string) ($student['cls_roll'] ?? ''), 0, 20),
                'name' => mb_substr(StudentDirectory::displayName($student), 0, 255),
                'billing_key' => $period->key(),
                'billing_label' => mb_substr($period->label(), 0, 120),
                'issue' => $issueDate->format('Y-m-d'),
                'due' => $dueDate->format('Y-m-d'),
                'status' => self::UNPAID,
                'actor' => $actor->teacherId(),
                'now' => $now,
            ]);
            $invoiceId = (int) $this->db->lastInsertId();
            $insertItem = $this->db->prepare(
                'INSERT INTO acc_invoice_items
                    (invoice_id, student_id, billing_key, fee_head_id, line_kind, uniq_key, description, fee_category_id,
                     standard_amount, category_concession, amount, discount, concession_id, net, due_date,
                     late_fine_amount, grace_days, created_at)
                 VALUES (:invoice, :student, :billing_key, :head, :kind, :uniq, :description, :category,
                         :standard, :category_concession, :amount, :discount, :concession, :net, :due,
                         :late_fine, :grace, :now)'
            );
            foreach ($lines as $line) {
                $rate = $line['rate'];
                $insertItem->execute([
                    'invoice' => $invoiceId,
                    'student' => (int) $student['id'],
                    'billing_key' => $period->key(),
                    'head' => (int) $line['head']['id'],
                    'kind' => self::LINE_FEE,
                    'uniq' => $line['uniq_key'],
                    'description' => mb_substr((string) $line['head']['name_bn'], 0, 160),
                    'category' => $rate['category_id'],
                    'standard' => Money::toDb($rate['standard']),
                    'category_concession' => Money::toDb($rate['standard'] - $rate['amount']),
                    'amount' => Money::toDb($rate['amount']),
                    'discount' => Money::toDb($line['discount']),
                    'concession' => $line['concession_id'],
                    'net' => Money::toDb($rate['amount'] - $line['discount']),
                    'due' => $line['due_date']->format('Y-m-d'),
                    'late_fine' => Money::toDb(0),
                    'grace' => 0,
                    'now' => $now,
                ]);
            }
            $totals = $this->recompute($invoiceId);
            $audit->record(
                'invoice.create',
                'invoice',
                $invoiceId,
                null,
                ['invoice_no' => $number, 'student_id' => (int) $student['id'], 'billing_key' => $period->key(), 'lines' => count($lines)] + $totals,
                'ইনভয়েস ' . $number . ': ' . StudentDirectory::displayName($student)
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $invoiceId;
    }

    /**
     * Re-derives invoice totals and status from its lines (caller holds the transaction).
     *
     * @return array<string, string>
     */
    public function recompute(int $invoiceId): array
    {
        $statement = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN line_kind = 'fee' THEN standard_amount ELSE 0 END), 0) AS standard_total,
                COALESCE(SUM(CASE WHEN line_kind = 'fee' THEN category_concession ELSE 0 END), 0) AS category_concession,
                COALESCE(SUM(CASE WHEN line_kind = 'fee' THEN amount ELSE 0 END), 0) AS gross,
                COALESCE(SUM(CASE WHEN line_kind = 'late_fine' THEN amount ELSE 0 END), 0) AS fine,
                COALESCE(SUM(discount), 0) AS discount,
                COALESCE(SUM(waived), 0) AS waived,
                COALESCE(SUM(net), 0) AS net,
                COALESCE(SUM(paid), 0) AS paid
             FROM acc_invoice_items WHERE invoice_id = :id"
        );
        $statement->execute(['id' => $invoiceId]);
        $sums = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $totals = [];
        foreach (['standard_total', 'category_concession', 'gross', 'fine', 'discount', 'waived', 'net', 'paid'] as $column) {
            $totals[$column] = Money::toDb(Money::fromDb($sums[$column] ?? '0'));
        }
        $current = $this->invoice($invoiceId);
        $status = ($current['status'] ?? '') === self::CANCELLED
            ? self::CANCELLED
            : self::statusFor(Money::fromDb($totals['net']), Money::fromDb($totals['paid']));
        $this->db->prepare(
            'UPDATE acc_invoices SET standard_total = :standard_total, category_concession = :category_concession, gross = :gross,
                fine = :fine, discount = :discount, waived = :waived, net = :net, paid = :paid, status = :status
             WHERE id = :id'
        )->execute($totals + ['status' => $status, 'id' => $invoiceId]);

        return $totals + ['status' => $status];
    }

    public static function statusFor(int $net, int $paid): string
    {
        if ($net - $paid <= 0) {
            return self::PAID;
        }

        return $paid > 0 ? self::PARTIAL : self::UNPAID;
    }

    public static function uniqKey(int $studentId, int $headId, string $billingKey, string $lineKind): string
    {
        return $studentId . ':' . $headId . ':' . $billingKey . ':' . $lineKind;
    }

    public function cancel(AccessContext $actor, AuditTrail $audit, int $invoiceId, string $reason): void
    {
        if (!$actor->can(Capability::INVOICE_CANCEL)) {
            throw AccessDeniedException::forCapability(Capability::INVOICE_CANCEL);
        }
        $reason = FeeCatalog::text($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 3) {
            throw new RuntimeException('বাতিলের কারণ লিখুন।');
        }
        $this->db->beginTransaction();
        try {
            $invoice = $this->lockInvoice($invoiceId);
            if ($invoice['status'] === self::CANCELLED) {
                throw new RuntimeException('ইনভয়েসটি আগেই বাতিল করা হয়েছে।');
            }
            if (Money::fromDb($invoice['paid']) > 0) {
                throw new RuntimeException('এই ইনভয়েসে পরিশোধ আছে; আগে পরিশোধ রিভার্স করতে হবে, তারপর বাতিল।');
            }
            $pendingWaivers = \fetchRows(
                $this->db,
                'SELECT id FROM acc_waivers WHERE invoice_id = :id AND status = :status LIMIT 1',
                ['id' => $invoiceId, 'status' => ApprovalStatus::PENDING]
            );
            if ($pendingWaivers !== []) {
                throw new RuntimeException('এই ইনভয়েসে মওকুফের আবেদন অনিষ্পন্ন আছে; আগে সেটি নিষ্পত্তি করুন।');
            }
            $this->db->prepare('UPDATE acc_invoice_items SET uniq_key = NULL WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            $this->db->prepare(
                'UPDATE acc_invoices SET status = :status, cancelled_by = :actor, cancelled_at = :now, cancel_reason = :reason WHERE id = :id'
            )->execute(['status' => self::CANCELLED, 'actor' => $actor->teacherId(), 'now' => $now, 'reason' => $reason, 'id' => $invoiceId]);
            $audit->record(
                'invoice.cancel',
                'invoice',
                $invoiceId,
                ['status' => $invoice['status'], 'net' => $invoice['net']],
                ['status' => self::CANCELLED, 'reason' => $reason],
                'ইনভয়েস বাতিল: ' . $invoice['invoice_no']
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function invoice(int $invoiceId): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_invoices WHERE id = :id', ['id' => $invoiceId]);

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function items(int $invoiceId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT i.*, c.name_bn AS category_name FROM acc_invoice_items i
             LEFT JOIN acc_fee_categories c ON c.id = i.fee_category_id
             WHERE i.invoice_id = :id ORDER BY i.line_kind, i.id',
            ['id' => $invoiceId]
        );
    }

    /**
     * @param array{session_year: string, class_id: int, section_id: int, billing_key?: string, status?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function listForClass(array $filter, int $limit = 500): array
    {
        $sql = 'SELECT * FROM acc_invoices WHERE session_year = :session AND class_id = :class';
        $params = ['session' => $filter['session_year'], 'class' => $filter['class_id']];
        if ($filter['section_id'] > 0) {
            $sql .= ' AND section_id = :section';
            $params['section'] = $filter['section_id'];
        }
        if (($filter['billing_key'] ?? '') !== '') {
            $sql .= ' AND billing_key = :billing_key';
            $params['billing_key'] = $filter['billing_key'];
        }
        if (($filter['status'] ?? '') !== '') {
            $sql .= ' AND status = :status';
            $params['status'] = $filter['status'];
        }
        $sql .= ' ORDER BY CAST(roll_snapshot AS UNSIGNED), id LIMIT ' . max(1, min(2000, $limit));

        return \fetchRows($this->db, $sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(int $studentId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT * FROM acc_invoices WHERE student_id = :student ORDER BY issue_date DESC, id DESC',
            ['student' => $studentId]
        );
    }

    /** @return array<string, string> billing_key => label */
    public function billingKeys(string $sessionYear, int $classId, int $sectionId): array
    {
        $sql = 'SELECT billing_key, MAX(billing_label) AS label FROM acc_invoices WHERE session_year = :session AND class_id = :class';
        $params = ['session' => $sessionYear, 'class' => $classId];
        if ($sectionId > 0) {
            $sql .= ' AND section_id = :section';
            $params['section'] = $sectionId;
        }
        $sql .= ' GROUP BY billing_key ORDER BY billing_key';
        $keys = [];
        foreach (\fetchRows($this->db, $sql, $params) as $row) {
            $keys[(string) $row['billing_key']] = (string) $row['label'];
        }

        return $keys;
    }

    /**
     * Outstanding dues per student (cancelled invoices excluded).
     *
     * @param list<int> $studentIds
     * @return array<int, array{invoices: int, net: int, paid: int, due: int, overdue: int}>
     */
    public function duesByStudent(array $studentIds, string $sessionYear, DateTimeImmutable $today): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0));
        if ($studentIds === []) {
            return [];
        }
        $rows = \fetchRows(
            $this->db,
            "SELECT student_id, COUNT(*) AS invoices, SUM(net) AS net, SUM(paid) AS paid,
                    SUM(CASE WHEN due_date < :today AND net > paid THEN net - paid ELSE 0 END) AS overdue
             FROM acc_invoices
             WHERE student_id IN (" . implode(',', $studentIds) . ") AND session_year = :session AND status <> 'cancelled'
             GROUP BY student_id",
            ['today' => $today->format('Y-m-d'), 'session' => $sessionYear]
        );
        $map = [];
        foreach ($rows as $row) {
            $net = Money::fromDb($row['net']);
            $paid = Money::fromDb($row['paid']);
            $map[(int) $row['student_id']] = [
                'invoices' => (int) $row['invoices'],
                'net' => $net,
                'paid' => $paid,
                'due' => max(0, $net - $paid),
                'overdue' => Money::fromDb($row['overdue']),
            ];
        }

        return $map;
    }

    /** @return array<string, true> */
    private function existingKeys(array $studentIds, string $billingKey): array
    {
        $keys = [];
        foreach (\fetchRows(
            $this->db,
            'SELECT uniq_key FROM acc_invoice_items
             WHERE student_id IN (' . implode(',', array_map('intval', $studentIds)) . ') AND billing_key = :billing_key AND uniq_key IS NOT NULL',
            ['billing_key' => $billingKey]
        ) as $row) {
            $keys[(string) $row['uniq_key']] = true;
        }

        return $keys;
    }

    /** @return array<string, mixed> */
    private function lockInvoice(int $invoiceId): array
    {
        $statement = $this->db->prepare('SELECT * FROM acc_invoices WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('ইনভয়েস পাওয়া যায়নি।');
        }

        return $row;
    }
}
