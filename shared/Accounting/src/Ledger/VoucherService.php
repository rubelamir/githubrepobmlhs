<?php

declare(strict_types=1);

namespace School\Accounting\Ledger;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Double-entry vouchers. Manual vouchers go draft → submitted → approved (or
 * rejected back to the maker); system vouchers from collections, handovers and
 * bank deposits are written approved inside the caller's transaction. Approved
 * vouchers are immutable and corrected only by a reversal voucher.
 */
final class VoucherService
{
    public const RECEIPT = 'receipt';
    public const PAYMENT = 'payment';
    public const JOURNAL = 'journal';
    public const CONTRA = 'contra';

    public const TYPES = [
        self::RECEIPT => 'প্রাপ্তি ভাউচার (আয়)',
        self::PAYMENT => 'প্রদান ভাউচার (ব্যয়)',
        self::CONTRA => 'কন্ট্রা ভাউচার (নগদ ↔ ব্যাংক)',
        self::JOURNAL => 'জার্নাল ভাউচার (সমন্বয়)',
    ];
    public const PREFIXES = [self::RECEIPT => 'RV', self::PAYMENT => 'PV', self::CONTRA => 'CV', self::JOURNAL => 'JV'];

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::DRAFT => 'খসড়া',
        self::SUBMITTED => 'অনুমোদনের অপেক্ষায়',
        self::APPROVED => 'অনুমোদিত',
        self::REJECTED => 'ফেরত',
        self::CANCELLED => 'বাতিল',
    ];
    public const STATUS_BADGES = [
        self::DRAFT => 'text-bg-secondary',
        self::SUBMITTED => 'text-bg-warning',
        self::APPROVED => 'text-bg-success',
        self::REJECTED => 'text-bg-danger',
        self::CANCELLED => 'text-bg-light',
    ];

    public const MAX_LINES = 30;

    public function __construct(private PDO $db, private ChartOfAccounts $chart, private PeriodClosing $periods)
    {
    }

    /**
     * Creates or edits a draft. $input: type, voucher_date, narration, lines[{account_id, debit, credit, memo}].
     *
     * @param array<string, mixed> $input
     * @return array{voucher_id: int, duplicate: bool}
     */
    public function saveDraft(AccessContext $actor, AuditTrail $audit, ?int $id, array $input, string $idempotencyKey = ''): array
    {
        if (!$actor->can(Capability::VOUCHER_CREATE)) {
            throw AccessDeniedException::forCapability(Capability::VOUCHER_CREATE);
        }
        $type = (string) ($input['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new RuntimeException('ভাউচারের ধরন নির্বাচন করুন।');
        }
        $date = $this->validDate((string) ($input['voucher_date'] ?? ''));
        $this->periods->assertOpen($date);
        $narration = FeeCatalog::text((string) ($input['narration'] ?? ''), 255);
        if (mb_strlen($narration, 'UTF-8') < 3) {
            throw new RuntimeException('ভাউচারের বিবরণ (narration) লিখুন।');
        }
        $lines = $this->normalizeLines(is_array($input['lines'] ?? null) ? $input['lines'] : []);
        $this->assertTypeRules($type, $lines);
        $total = array_sum(array_column($lines, 'debit'));
        $now = AuditTrail::now()->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            if ($id === null) {
                if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
                    throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
                }
                $existing = \fetchRows($this->db, 'SELECT id, created_by FROM acc_vouchers WHERE idempotency_key = :key', ['key' => $idempotencyKey]);
                if ($existing !== []) {
                    $this->db->rollBack();

                    return ['voucher_id' => (int) $existing[0]['id'], 'duplicate' => true];
                }
                $this->db->prepare(
                    'INSERT INTO acc_vouchers (voucher_type, voucher_date, period, narration, total, status, idempotency_key, created_by, created_at, updated_at)
                     VALUES (:type, :date, :period, :narration, :total, :status, :key, :actor, :now, :now2)'
                )->execute([
                    'type' => $type, 'date' => $date, 'period' => PeriodClosing::periodOf($date), 'narration' => $narration,
                    'total' => Money::toDb($total), 'status' => self::DRAFT, 'key' => $idempotencyKey, 'actor' => $actor->teacherId(),
                    'now' => $now, 'now2' => $now,
                ]);
                $id = (int) $this->db->lastInsertId();
                $before = null;
            } else {
                $before = $this->lockVoucher($id);
                $this->assertEditable($actor, $before);
                $this->db->prepare(
                    'UPDATE acc_vouchers SET voucher_type = :type, voucher_date = :date, period = :period, narration = :narration, total = :total,
                         status = :status, updated_at = :now WHERE id = :id'
                )->execute([
                    'type' => $type, 'date' => $date, 'period' => PeriodClosing::periodOf($date), 'narration' => $narration,
                    'total' => Money::toDb($total), 'status' => self::DRAFT, 'now' => $now, 'id' => $id,
                ]);
                $before = ['type' => $before['voucher_type'], 'date' => $before['voucher_date'], 'narration' => $before['narration'], 'total' => $before['total'], 'lines' => $this->linesForAudit($id)];
                $this->db->prepare('DELETE FROM acc_voucher_lines WHERE voucher_id = :id')->execute(['id' => $id]);
            }
            $this->insertLines($id, $lines);
            $audit->record(
                $before === null ? 'voucher.create' : 'voucher.update',
                'voucher',
                $id,
                $before,
                ['type' => $type, 'date' => $date, 'narration' => $narration, 'total' => Money::toDb($total), 'lines' => $this->linesForAudit($id)],
                ($before === null ? 'ভাউচার খসড়া #' : 'ভাউচার সংশোধন #') . $id . ': ' . $narration
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ($exception->getCode() === '23000' && $idempotencyKey !== '') {
                $existing = \fetchRows($this->db, 'SELECT id FROM acc_vouchers WHERE idempotency_key = :key', ['key' => $idempotencyKey]);
                if ($existing !== []) {
                    return ['voucher_id' => (int) $existing[0]['id'], 'duplicate' => true];
                }
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return ['voucher_id' => $id, 'duplicate' => false];
    }

    public function submit(AccessContext $actor, AuditTrail $audit, int $id): void
    {
        $this->transition($actor, $audit, $id, static function (array $voucher, self $service) use ($actor): void {
            $service->assertEditable($actor, $voucher);
            $service->periods->assertOpen((string) $voucher['voucher_date']);
        }, self::SUBMITTED, 'voucher.submit', 'ভাউচার অনুমোদনের জন্য জমা #');
    }

    public function cancel(AccessContext $actor, AuditTrail $audit, int $id): void
    {
        $this->transition($actor, $audit, $id, static function (array $voucher, self $service) use ($actor): void {
            if ($voucher['status'] === self::SUBMITTED && !$actor->can(Capability::VOUCHER_APPROVE) && (int) $voucher['created_by'] !== $actor->teacherId()) {
                throw new RuntimeException('এই ভাউচার বাতিলের অনুমতি আপনার নেই।');
            }
            if (!in_array($voucher['status'], [self::DRAFT, self::REJECTED, self::SUBMITTED], true)) {
                throw new RuntimeException('অনুমোদিত ভাউচার বাতিল করা যায় না; রিভার্সাল দিন।');
            }
            if ($voucher['status'] !== self::SUBMITTED) {
                $service->assertEditable($actor, $voucher);
            }
        }, self::CANCELLED, 'voucher.cancel', 'ভাউচার বাতিল #');
    }

    public function reject(AccessContext $actor, AuditTrail $audit, int $id, string $remark): void
    {
        if (!$actor->can(Capability::VOUCHER_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::VOUCHER_APPROVE);
        }
        $remark = FeeCatalog::text($remark, 255);
        if (mb_strlen($remark, 'UTF-8') < 3) {
            throw new RuntimeException('ফেরতের কারণ লিখুন।');
        }
        $this->transition($actor, $audit, $id, static function (array $voucher): void {
            if ($voucher['status'] !== self::SUBMITTED) {
                throw new RuntimeException('শুধু অনুমোদনের অপেক্ষায় থাকা ভাউচার ফেরত দেওয়া যায়।');
            }
        }, self::REJECTED, 'voucher.reject', 'ভাউচার ফেরত #', $remark);
    }

    public function approve(AccessContext $actor, AuditTrail $audit, int $id): string
    {
        if (!$actor->can(Capability::VOUCHER_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::VOUCHER_APPROVE);
        }
        $this->db->beginTransaction();
        try {
            $voucher = $this->lockVoucher($id);
            $ownDraft = $voucher['status'] === self::DRAFT && (int) $voucher['created_by'] === $actor->teacherId();
            if ($voucher['status'] !== self::SUBMITTED && !$ownDraft) {
                throw new RuntimeException('ভাউচারটি অনুমোদনের অপেক্ষায় নেই।');
            }
            $date = (string) $voucher['voucher_date'];
            $this->periods->assertOpen($date);
            $lines = $this->lines($id);
            foreach ($lines as $line) {
                if ((int) $line['is_active'] !== 1 || (int) $line['is_group'] === 1) {
                    throw new RuntimeException('হিসাব ' . $line['code'] . ' এখন নিষ্ক্রিয়; ভাউচারটি সংশোধন করুন।');
                }
            }
            $this->assertSufficientFunds($lines, $date);
            $number = $this->assignNumber((string) $voucher['voucher_type'], $date);
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            $this->db->prepare('UPDATE acc_vouchers SET status = :status, voucher_no = :no, decided_by = :actor, decided_at = :now, updated_at = :now2 WHERE id = :id')
                ->execute(['status' => self::APPROVED, 'no' => $number, 'actor' => $actor->teacherId(), 'now' => $now, 'now2' => $now, 'id' => $id]);
            $audit->record('voucher.approve', 'voucher', $id, ['status' => $voucher['status']], ['status' => self::APPROVED, 'voucher_no' => $number, 'total' => $voucher['total']], 'ভাউচার অনুমোদন ' . $number . ': ' . $voucher['narration']);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $number;
    }

    /** Opposite-entry voucher for an approved manual voucher. */
    public function reverse(AccessContext $actor, AuditTrail $audit, int $id, string $reason): int
    {
        if (!$actor->can(Capability::VOUCHER_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::VOUCHER_APPROVE);
        }
        $reason = FeeCatalog::text($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new RuntimeException('রিভার্সালের কারণ লিখুন।');
        }
        $this->db->beginTransaction();
        try {
            $voucher = $this->lockVoucher($id);
            if ($voucher['status'] !== self::APPROVED) {
                throw new RuntimeException('শুধু অনুমোদিত ভাউচার রিভার্স করা যায়।');
            }
            if ($voucher['source_key'] !== null) {
                throw new RuntimeException('এটি স্বয়ংক্রিয় ভাউচার; মূল লেনদেনটি (রসিদ/ব্যাংক জমা) থেকে সংশোধন করুন।');
            }
            if ($voucher['reversal_of_id'] !== null || $voucher['reversed_by_id'] !== null) {
                throw new RuntimeException('ভাউচারটি আগেই রিভার্স করা হয়েছে বা এটি নিজেই একটি রিভার্সাল।');
            }
            $date = AuditTrail::now()->format('Y-m-d');
            $this->periods->assertOpen($date);
            $lines = array_map(static fn (array $line): array => [
                'account_id' => (int) $line['account_id'],
                'debit' => Money::fromDb($line['credit']),
                'credit' => Money::fromDb($line['debit']),
                'party_type' => (string) $line['party_type'],
                'party_id' => $line['party_id'] === null ? null : (int) $line['party_id'],
                'memo' => (string) $line['memo'],
            ], $this->lines($id));
            $this->assertSufficientFunds($this->withAccounts($lines), $date);
            $type = (string) $voucher['voucher_type'];
            $number = $this->assignNumber($type, $date);
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            $this->db->prepare(
                'INSERT INTO acc_vouchers (voucher_no, voucher_type, voucher_date, period, narration, total, status, created_by, created_at, updated_at,
                     decided_by, decided_at, reversal_of_id, reversal_reason)
                 VALUES (:no, :type, :date, :period, :narration, :total, :status, :actor, :now, :now2, :actor2, :now3, :of, :reason)'
            )->execute([
                'no' => $number, 'type' => $type, 'date' => $date, 'period' => PeriodClosing::periodOf($date),
                'narration' => mb_substr('রিভার্সাল: ' . $voucher['voucher_no'] . ' — ' . $reason, 0, 255), 'total' => $voucher['total'],
                'status' => self::APPROVED, 'actor' => $actor->teacherId(), 'now' => $now, 'now2' => $now, 'actor2' => $actor->teacherId(),
                'now3' => $now, 'of' => $id, 'reason' => $reason,
            ]);
            $reversalId = (int) $this->db->lastInsertId();
            $this->insertLines($reversalId, $lines);
            $this->db->prepare('UPDATE acc_vouchers SET reversed_by_id = :rev, reversal_reason = :reason, updated_at = :now WHERE id = :id')
                ->execute(['rev' => $reversalId, 'reason' => $reason, 'now' => $now, 'id' => $id]);
            $audit->record('voucher.reverse', 'voucher', $id, ['status' => self::APPROVED], ['reversed_by' => $number, 'reason' => $reason], 'ভাউচার রিভার্স ' . $voucher['voucher_no'] . ' → ' . $number);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $reversalId;
    }

    /**
     * Approved system voucher, written inside the caller's transaction. A second
     * call with the same source key returns the existing voucher.
     *
     * @param list<array{account_id: int, debit: int, credit: int, party_type?: string, party_id?: int|null, memo?: string}> $lines
     */
    public function postSystem(string $type, string $date, string $narration, array $lines, string $sourceKey, int $actorId): int
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('System postings require an open transaction.');
        }
        $existing = \fetchRows($this->db, 'SELECT id FROM acc_vouchers WHERE source_key = :key', ['key' => $sourceKey]);
        if ($existing !== []) {
            return (int) $existing[0]['id'];
        }
        $this->periods->assertOpen($date);
        $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['debit'] > 0 || $line['credit'] > 0));
        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));
        if ($debit !== $credit || $debit <= 0) {
            throw new RuntimeException('স্বয়ংক্রিয় ভাউচারে ডেবিট ও ক্রেডিট সমান হয়নি।');
        }
        $number = $this->assignNumber($type, $date);
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO acc_vouchers (voucher_no, voucher_type, voucher_date, period, narration, total, status, source_key, created_by, created_at, updated_at, decided_by, decided_at)
             VALUES (:no, :type, :date, :period, :narration, :total, :status, :source, :actor, :now, :now2, :actor2, :now3)'
        )->execute([
            'no' => $number, 'type' => $type, 'date' => $date, 'period' => PeriodClosing::periodOf($date), 'narration' => mb_substr($narration, 0, 255),
            'total' => Money::toDb($debit), 'status' => self::APPROVED, 'source' => $sourceKey, 'actor' => $actorId, 'now' => $now, 'now2' => $now,
            'actor2' => $actorId, 'now3' => $now,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->insertLines($id, $lines);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function voucher(int $id): ?array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT v.*, c.teacher_name AS created_by_name, d.teacher_name AS decided_by_name,
                    ro.voucher_no AS reversal_of_no, rb.voucher_no AS reversed_by_no
             FROM acc_vouchers v
             LEFT JOIN teacher_info c ON c.id = v.created_by
             LEFT JOIN teacher_info d ON d.id = v.decided_by
             LEFT JOIN acc_vouchers ro ON ro.id = v.reversal_of_id
             LEFT JOIN acc_vouchers rb ON rb.id = v.reversed_by_id
             WHERE v.id = :id',
            ['id' => $id]
        );

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $voucherId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT l.*, a.code, a.name_bn, a.account_type, a.money_kind, a.is_active, a.is_group, t.teacher_name AS party_teacher
             FROM acc_voucher_lines l
             JOIN acc_accounts a ON a.id = l.account_id
             LEFT JOIN teacher_info t ON l.party_type = \'teacher\' AND t.id = l.party_id
             WHERE l.voucher_id = :id ORDER BY l.line_no',
            ['id' => $voucherId]
        );
    }

    /**
     * @param array{status?: string, type?: string, from?: string, to?: string, source?: string, q?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function search(array $filter, int $limit = 500): array
    {
        $sql = 'SELECT v.*, c.teacher_name AS created_by_name FROM acc_vouchers v LEFT JOIN teacher_info c ON c.id = v.created_by WHERE 1 = 1';
        $params = [];
        if (isset(self::STATUS_LABELS[$filter['status'] ?? ''])) {
            $sql .= ' AND v.status = :status';
            $params['status'] = $filter['status'];
        }
        if (isset(self::TYPES[$filter['type'] ?? ''])) {
            $sql .= ' AND v.voucher_type = :type';
            $params['type'] = $filter['type'];
        }
        if (($filter['from'] ?? '') !== '') {
            $sql .= ' AND v.voucher_date >= :from';
            $params['from'] = $filter['from'];
        }
        if (($filter['to'] ?? '') !== '') {
            $sql .= ' AND v.voucher_date <= :to';
            $params['to'] = $filter['to'];
        }
        if (($filter['source'] ?? '') === 'manual') {
            $sql .= ' AND v.source_key IS NULL';
        } elseif (($filter['source'] ?? '') === 'system') {
            $sql .= ' AND v.source_key IS NOT NULL';
        }
        if (($filter['q'] ?? '') !== '') {
            $sql .= ' AND (v.voucher_no LIKE :q OR v.narration LIKE :q2)';
            $params['q'] = '%' . $filter['q'] . '%';
            $params['q2'] = '%' . $filter['q'] . '%';
        }
        $sql .= ' ORDER BY v.voucher_date DESC, v.id DESC LIMIT ' . max(1, min(2000, $limit));

        return \fetchRows($this->db, $sql, $params);
    }

    public function submittedCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM acc_vouchers WHERE status = 'submitted'")->fetchColumn();
    }

    /**
     * Approved entries of one account with running balance on its normal side.
     *
     * @return array{opening: int, rows: list<array<string, mixed>>, closing: int, debit: int, credit: int}
     */
    public function ledger(int $accountId, string $from, string $to): array
    {
        $account = $this->chart->account($accountId);
        if ($account === null) {
            throw new RuntimeException('হিসাবটি পাওয়া যায়নি।');
        }
        $debitNormal = ChartOfAccounts::isDebitNormal((string) $account['account_type']);
        $opening = $this->chart->balance($accountId, (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'));
        $rows = \fetchRows(
            $this->db,
            "SELECT l.id AS line_id, l.debit, l.credit, l.memo, l.party_type, l.party_id, v.id AS voucher_id, v.voucher_no, v.voucher_type,
                    v.voucher_date, v.narration, v.source_key
             FROM acc_voucher_lines l JOIN acc_vouchers v ON v.id = l.voucher_id
             WHERE v.status = 'approved' AND l.account_id = :account AND v.voucher_date BETWEEN :from AND :to
             ORDER BY v.voucher_date, v.id, l.line_no",
            ['account' => $accountId, 'from' => $from, 'to' => $to]
        );
        $running = $opening;
        $debit = 0;
        $credit = 0;
        foreach ($rows as &$row) {
            $dr = Money::fromDb($row['debit']);
            $cr = Money::fromDb($row['credit']);
            $debit += $dr;
            $credit += $cr;
            $running += $debitNormal ? $dr - $cr : $cr - $dr;
            $row['running'] = $running;
        }
        unset($row);

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $running, 'debit' => $debit, 'credit' => $credit];
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{account_id: int, debit: int, credit: int, party_type: string, party_id: int|null, memo: string}>
     */
    public function normalizeLines(array $raw): array
    {
        $postable = [];
        foreach ($this->chart->postable() as $account) {
            $postable[(int) $account['id']] = $account;
        }
        $lines = [];
        foreach ($raw as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $accountId = (int) ($line['account_id'] ?? 0);
            $debitInput = trim((string) ($line['debit'] ?? ''));
            $creditInput = trim((string) ($line['credit'] ?? ''));
            $memo = FeeCatalog::text((string) ($line['memo'] ?? ''), 160);
            if ($accountId === 0 && $debitInput === '' && $creditInput === '' && $memo === '') {
                continue;
            }
            $rowLabel = 'লাইন ' . bnDigits((string) ((int) $index + 1));
            if (!isset($postable[$accountId])) {
                throw new RuntimeException($rowLabel . ': হিসাব নির্বাচন করুন।');
            }
            $debit = Money::parseOptional($debitInput, $rowLabel . ' ডেবিট') ?? 0;
            $credit = Money::parseOptional($creditInput, $rowLabel . ' ক্রেডিট') ?? 0;
            if (($debit > 0) === ($credit > 0)) {
                throw new RuntimeException($rowLabel . ': ডেবিট অথবা ক্রেডিট — যেকোনো একটিতে টাকা দিন।');
            }
            $lines[] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'party_type' => '', 'party_id' => null, 'memo' => $memo];
        }
        if (count($lines) < 2) {
            throw new RuntimeException('অন্তত দুটি লাইন (একটি ডেবিট, একটি ক্রেডিট) দিন।');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new RuntimeException('একটি ভাউচারে সর্বোচ্চ ' . bnDigits((string) self::MAX_LINES) . 'টি লাইন দেওয়া যায়।');
        }
        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));
        if ($debit !== $credit) {
            throw new RuntimeException('মোট ডেবিট (' . Money::format($debit) . ') ও মোট ক্রেডিট (' . Money::format($credit) . ') সমান নয়।');
        }

        return $lines;
    }

    /** @param list<array{account_id: int, debit: int, credit: int}> $lines */
    public function assertTypeRules(string $type, array $lines): void
    {
        $kinds = [];
        foreach ($this->chart->postable() as $account) {
            $kinds[(int) $account['id']] = (string) $account['money_kind'];
        }
        $moneyDebit = false;
        $moneyCredit = false;
        $nonMoney = false;
        foreach ($lines as $line) {
            $isMoney = in_array($kinds[$line['account_id']] ?? 'none', ChartOfAccounts::SPENDABLE_KINDS, true);
            $moneyDebit = $moneyDebit || ($isMoney && $line['debit'] > 0);
            $moneyCredit = $moneyCredit || ($isMoney && $line['credit'] > 0);
            $nonMoney = $nonMoney || !$isMoney;
            if (($kinds[$line['account_id']] ?? '') === 'teacher_custody') {
                throw new RuntimeException('"শিক্ষকের কাছে অনিষ্পন্ন আদায়" হিসাবে শুধু আদায় ও হস্তান্তর থেকে স্বয়ংক্রিয় এন্ট্রি হয়।');
            }
        }
        $error = match ($type) {
            self::RECEIPT => !$moneyDebit || $moneyCredit ? 'প্রাপ্তি ভাউচারে নগদ/ব্যাংক হিসাব ডেবিট হবে (টাকা আসছে), ক্রেডিট নয়।' : null,
            self::PAYMENT => !$moneyCredit || $moneyDebit ? 'প্রদান ভাউচারে নগদ/ব্যাংক হিসাব ক্রেডিট হবে (টাকা যাচ্ছে), ডেবিট নয়।' : null,
            self::CONTRA => $nonMoney ? 'কন্ট্রা ভাউচারে শুধু নগদ ও ব্যাংক হিসাব থাকবে।' : null,
            self::JOURNAL => $moneyDebit || $moneyCredit ? 'জার্নাল ভাউচারে নগদ/ব্যাংক হিসাব থাকবে না; টাকা লেনদেনের জন্য প্রাপ্তি/প্রদান/কন্ট্রা ব্যবহার করুন।' : null,
            default => 'ভাউচারের ধরন সঠিক নয়।',
        };
        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    /** @param array<string, mixed> $voucher */
    public function assertEditable(AccessContext $actor, array $voucher): void
    {
        if (!in_array($voucher['status'], [self::DRAFT, self::REJECTED], true)) {
            throw new RuntimeException('শুধু খসড়া বা ফেরত আসা ভাউচার সংশোধন করা যায়।');
        }
        if ((int) $voucher['created_by'] !== $actor->teacherId() && !$actor->can(Capability::VOUCHER_APPROVE)) {
            throw new RuntimeException('অন্যের তৈরি ভাউচার সংশোধন করা যায় না।');
        }
    }

    /**
     * Cash, bank and wallet accounts may not go below zero on the voucher date or later.
     *
     * @param list<array<string, mixed>> $lines with account_id, debit, credit, money_kind, code, name_bn
     */
    private function assertSufficientFunds(array $lines, string $date): void
    {
        $net = [];
        $names = [];
        foreach ($lines as $line) {
            if (!in_array((string) $line['money_kind'], ChartOfAccounts::SPENDABLE_KINDS, true)) {
                continue;
            }
            $id = (int) $line['account_id'];
            $net[$id] = ($net[$id] ?? 0) + Money::fromDb($line['debit']) - Money::fromDb($line['credit']);
            $names[$id] = $line['code'] . ' ' . $line['name_bn'];
        }
        foreach ($net as $accountId => $change) {
            if ($change >= 0) {
                continue;
            }
            $available = min($this->chart->balance($accountId, $date), $this->chart->balance($accountId));
            if ($available + $change < 0) {
                throw new RuntimeException($names[$accountId] . ' হিসাবে পর্যাপ্ত টাকা নেই (স্থিতি ' . Money::format($available) . ', প্রয়োজন ' . Money::format(-$change) . ')।');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function withAccounts(array $lines): array
    {
        foreach ($lines as &$line) {
            $account = $this->chart->account((int) $line['account_id']) ?? [];
            $line['money_kind'] = $account['money_kind'] ?? 'none';
            $line['code'] = $account['code'] ?? '';
            $line['name_bn'] = $account['name_bn'] ?? '';
            $line['debit'] = Money::toDb((int) $line['debit']);
            $line['credit'] = Money::toDb((int) $line['credit']);
        }
        unset($line);

        return $lines;
    }

    private function assignNumber(string $type, string $date): string
    {
        $prefix = self::PREFIXES[$type] ?? 'JV';
        $year = substr($date, 0, 4);

        return Sequence::format($prefix, $year, Sequence::next($this->db, $prefix, $year));
    }

    /** @param list<array<string, mixed>> $lines */
    private function insertLines(int $voucherId, array $lines): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO acc_voucher_lines (voucher_id, line_no, account_id, debit, credit, party_type, party_id, memo)
             VALUES (:voucher, :line_no, :account, :debit, :credit, :party_type, :party_id, :memo)'
        );
        foreach (array_values($lines) as $index => $line) {
            $statement->execute([
                'voucher' => $voucherId,
                'line_no' => $index + 1,
                'account' => (int) $line['account_id'],
                'debit' => Money::toDb((int) $line['debit']),
                'credit' => Money::toDb((int) $line['credit']),
                'party_type' => (string) ($line['party_type'] ?? ''),
                'party_id' => $line['party_id'] ?? null,
                'memo' => mb_substr((string) ($line['memo'] ?? ''), 0, 160),
            ]);
        }
    }

    /** @return list<array<string, string>> */
    private function linesForAudit(int $voucherId): array
    {
        return array_map(static fn (array $line): array => [
            'account' => (string) $line['code'],
            'debit' => (string) $line['debit'],
            'credit' => (string) $line['credit'],
            'memo' => (string) $line['memo'],
        ], $this->lines($voucherId));
    }

    /** @return array<string, mixed> */
    private function lockVoucher(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM acc_vouchers WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $voucher = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$voucher) {
            throw new RuntimeException('ভাউচারটি পাওয়া যায়নি।');
        }
        if ($voucher['source_key'] !== null && $voucher['status'] !== self::APPROVED) {
            throw new RuntimeException('স্বয়ংক্রিয় ভাউচার এখান থেকে পরিবর্তন করা যায় না।');
        }

        return $voucher;
    }

    private function transition(AccessContext $actor, AuditTrail $audit, int $id, callable $guard, string $status, string $action, string $summary, ?string $remark = null): void
    {
        $this->db->beginTransaction();
        try {
            $voucher = $this->lockVoucher($id);
            if ($voucher['source_key'] !== null) {
                throw new RuntimeException('স্বয়ংক্রিয় ভাউচার এখান থেকে পরিবর্তন করা যায় না।');
            }
            $guard($voucher, $this);
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            if ($status === self::SUBMITTED) {
                $this->db->prepare('UPDATE acc_vouchers SET status = :status, submitted_at = :now, decision_remark = NULL, updated_at = :now2 WHERE id = :id')
                    ->execute(['status' => $status, 'now' => $now, 'now2' => $now, 'id' => $id]);
            } elseif ($status === self::REJECTED) {
                $this->db->prepare('UPDATE acc_vouchers SET status = :status, decided_by = :actor, decided_at = :now, decision_remark = :remark, updated_at = :now2 WHERE id = :id')
                    ->execute(['status' => $status, 'actor' => $actor->teacherId(), 'now' => $now, 'remark' => $remark, 'now2' => $now, 'id' => $id]);
            } else {
                $this->db->prepare('UPDATE acc_vouchers SET status = :status, updated_at = :now WHERE id = :id')
                    ->execute(['status' => $status, 'now' => $now, 'id' => $id]);
            }
            $audit->record($action, 'voucher', $id, ['status' => $voucher['status']], array_filter(['status' => $status, 'remark' => $remark]), $summary . $id . ': ' . $voucher['narration']);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function validDate(string $input): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($date === false || $date->format('Y-m-d') !== $input) {
            throw new RuntimeException('ভাউচারের তারিখ সঠিক নয়।');
        }
        if ($input > AuditTrail::now()->format('Y-m-d')) {
            throw new RuntimeException('ভবিষ্যতের তারিখে ভাউচার দেওয়া যায় না।');
        }
        if ($input < '2020-01-01') {
            throw new RuntimeException('ভাউচারের তারিখ সঠিক নয়।');
        }

        return $input;
    }
}
