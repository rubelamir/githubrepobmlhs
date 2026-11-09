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
 * Office cash deposited to the bank (officer records with the slip, head
 * verifies → contra voucher Dr bank / Cr cash) and ticking bank-book lines
 * against the bank statement.
 */
final class BankService
{
    public const SUBMITTED = 'submitted';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [self::SUBMITTED => 'যাচাইয়ের অপেক্ষায়', self::VERIFIED => 'যাচাইকৃত', self::REJECTED => 'ফেরত'];
    public const STATUS_BADGES = [self::SUBMITTED => 'text-bg-warning', self::VERIFIED => 'text-bg-success', self::REJECTED => 'text-bg-danger'];

    public function __construct(
        private PDO $db,
        private ChartOfAccounts $chart,
        private VoucherService $vouchers,
        private PeriodClosing $periods
    ) {
    }

    /** Office cash not yet claimed by a pending deposit. */
    public function availableCash(): int
    {
        $pending = Money::fromDb(\fetchRows($this->db, "SELECT COALESCE(SUM(amount), 0) AS total FROM acc_bank_deposits WHERE status = 'submitted'")[0]['total']);

        return $this->chart->balance($this->chart->idOf(ChartOfAccounts::CASH)) - $pending;
    }

    /**
     * @param array<string, mixed> $input bank_account_id, deposit_date, amount, slip_no, note
     * @return array{deposit_id: int, duplicate: bool}
     */
    public function submitDeposit(AccessContext $actor, AuditTrail $audit, string $idempotencyKey, array $input): array
    {
        if (!$actor->can(Capability::BANK_RECONCILE)) {
            throw AccessDeniedException::forCapability(Capability::BANK_RECONCILE);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        $existing = \fetchRows($this->db, 'SELECT id FROM acc_bank_deposits WHERE idempotency_key = :key', ['key' => $idempotencyKey]);
        if ($existing !== []) {
            return ['deposit_id' => (int) $existing[0]['id'], 'duplicate' => true];
        }
        $bank = $this->bankAccount((int) ($input['bank_account_id'] ?? 0));
        $date = (string) ($input['deposit_date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date > AuditTrail::now()->format('Y-m-d')) {
            throw new RuntimeException('জমার তারিখ সঠিক নয় (ভবিষ্যতের তারিখ দেওয়া যাবে না)।');
        }
        $this->periods->assertOpen($date);
        $amount = Money::parse((string) ($input['amount'] ?? ''), 'জমার পরিমাণ');
        if ($amount < 1) {
            throw new RuntimeException('জমার পরিমাণ শূন্যের বেশি হতে হবে।');
        }
        $slip = FeeCatalog::text((string) ($input['slip_no'] ?? ''), 60);
        if ($slip === '') {
            throw new RuntimeException('ব্যাংক জমা স্লিপের নম্বর লিখুন।');
        }
        $note = FeeCatalog::text((string) ($input['note'] ?? ''), 255);

        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT id FROM acc_accounts WHERE code = '" . ChartOfAccounts::CASH . "' FOR UPDATE");
            $available = $this->availableCash();
            if ($amount > $available) {
                throw new RuntimeException('অফিসের নগদ তহবিলে এত টাকা নেই (জমাযোগ্য ' . Money::format(max(0, $available)) . ')।');
            }
            $now = AuditTrail::now();
            $number = Sequence::format('DEP', $now->format('Y'), Sequence::next($this->db, 'DEP', $now->format('Y')));
            $this->db->prepare(
                'INSERT INTO acc_bank_deposits (deposit_no, idempotency_key, bank_account_id, deposit_date, amount, slip_no, status, note, submitted_by, submitted_at)
                 VALUES (:no, :key, :bank, :date, :amount, :slip, :status, :note, :actor, :now)'
            )->execute([
                'no' => $number, 'key' => $idempotencyKey, 'bank' => (int) $bank['id'], 'date' => $date, 'amount' => Money::toDb($amount),
                'slip' => $slip, 'status' => self::SUBMITTED, 'note' => $note, 'actor' => $actor->teacherId(), 'now' => $now->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->lastInsertId();
            $audit->record('bank_deposit.submit', 'bank_deposit', $id, null, ['deposit_no' => $number, 'bank' => $bank['code'], 'date' => $date, 'amount' => Money::toDb($amount), 'slip_no' => $slip], 'ব্যাংক জমা ' . $number . ': ' . Money::format($amount));
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            $raced = $exception->getCode() === '23000' ? \fetchRows($this->db, 'SELECT id FROM acc_bank_deposits WHERE idempotency_key = :key', ['key' => $idempotencyKey]) : [];
            if ($raced !== []) {
                return ['deposit_id' => (int) $raced[0]['id'], 'duplicate' => true];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['deposit_id' => $id, 'duplicate' => false];
    }

    public function decideDeposit(AccessContext $actor, AuditTrail $audit, int $id, bool $verify, string $remark): void
    {
        if (!$actor->can(Capability::VOUCHER_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::VOUCHER_APPROVE);
        }
        $remark = FeeCatalog::text($remark, 255);
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT * FROM acc_bank_deposits WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $id]);
            $deposit = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$deposit || $deposit['status'] !== self::SUBMITTED) {
                throw new RuntimeException('জমাটি পাওয়া যায়নি বা আগেই নিষ্পত্তি হয়েছে।');
            }
            $voucherId = null;
            if ($verify) {
                $amount = Money::fromDb($deposit['amount']);
                $bank = $this->bankAccount((int) $deposit['bank_account_id']);
                $voucherId = $this->vouchers->postSystem(
                    VoucherService::CONTRA,
                    (string) $deposit['deposit_date'],
                    'ব্যাংকে নগদ জমা — ' . $deposit['deposit_no'] . ', স্লিপ ' . $deposit['slip_no'],
                    [
                        ['account_id' => (int) $bank['id'], 'debit' => $amount, 'credit' => 0, 'memo' => 'স্লিপ ' . $deposit['slip_no']],
                        ['account_id' => $this->chart->idOf(ChartOfAccounts::CASH), 'debit' => 0, 'credit' => $amount, 'memo' => (string) $deposit['deposit_no']],
                    ],
                    'deposit:' . $id,
                    $actor->teacherId()
                );
                if ($this->chart->balance($this->chart->idOf(ChartOfAccounts::CASH)) < 0) {
                    throw new RuntimeException('অফিসের নগদ তহবিলে পর্যাপ্ত টাকা নেই; জমাটি যাচাই করা যাচ্ছে না।');
                }
            } elseif (mb_strlen($remark, 'UTF-8') < 3) {
                throw new RuntimeException('ফেরতের কারণ লিখুন।');
            }
            $status = $verify ? self::VERIFIED : self::REJECTED;
            $this->db->prepare('UPDATE acc_bank_deposits SET status = :status, decided_by = :actor, decided_at = :now, remark = :remark, voucher_id = :voucher WHERE id = :id')
                ->execute(['status' => $status, 'actor' => $actor->teacherId(), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'remark' => $remark, 'voucher' => $voucherId, 'id' => $id]);
            $audit->record($verify ? 'bank_deposit.verify' : 'bank_deposit.reject', 'bank_deposit', $id, ['status' => self::SUBMITTED], ['status' => $status, 'remark' => $remark, 'voucher_id' => $voucherId], ($verify ? 'ব্যাংক জমা যাচাই ' : 'ব্যাংক জমা ফেরত ') . $deposit['deposit_no']);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function deposit(int $id): ?array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT d.*, a.code AS bank_code, a.name_bn AS bank_name, s.teacher_name AS submitted_by_name, x.teacher_name AS decided_by_name, v.voucher_no
             FROM acc_bank_deposits d
             JOIN acc_accounts a ON a.id = d.bank_account_id
             LEFT JOIN teacher_info s ON s.id = d.submitted_by
             LEFT JOIN teacher_info x ON x.id = d.decided_by
             LEFT JOIN acc_vouchers v ON v.id = d.voucher_id
             WHERE d.id = :id',
            ['id' => $id]
        );

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function deposits(string $status, int $limit = 300): array
    {
        $sql = 'SELECT d.*, a.code AS bank_code, a.name_bn AS bank_name, s.teacher_name AS submitted_by_name, x.teacher_name AS decided_by_name, v.voucher_no
                FROM acc_bank_deposits d
                JOIN acc_accounts a ON a.id = d.bank_account_id
                LEFT JOIN teacher_info s ON s.id = d.submitted_by
                LEFT JOIN teacher_info x ON x.id = d.decided_by
                LEFT JOIN acc_vouchers v ON v.id = d.voucher_id';
        $params = [];
        if (isset(self::STATUS_LABELS[$status])) {
            $sql .= ' WHERE d.status = :status';
            $params['status'] = $status;
        }

        return \fetchRows($this->db, $sql . ' ORDER BY d.deposit_date DESC, d.id DESC LIMIT ' . max(1, min(1000, $limit)), $params);
    }

    public function submittedCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM acc_bank_deposits WHERE status = 'submitted'")->fetchColumn();
    }

    /**
     * Bank-book lines with their reconciliation state.
     *
     * @return array{rows: list<array<string, mixed>>, book: int, reconciled: int, uncleared_in: int, uncleared_out: int}
     */
    public function bankBook(int $bankAccountId, string $from, string $to): array
    {
        $this->bankAccount($bankAccountId, false);
        $rows = \fetchRows(
            $this->db,
            "SELECT l.id AS line_id, l.debit, l.credit, l.memo, v.id AS voucher_id, v.voucher_no, v.voucher_date, v.narration,
                    r.statement_date, r.reconciled_at, t.teacher_name AS reconciled_by_name
             FROM acc_voucher_lines l
             JOIN acc_vouchers v ON v.id = l.voucher_id
             LEFT JOIN acc_bank_reconciliations r ON r.voucher_line_id = l.id
             LEFT JOIN teacher_info t ON t.id = r.reconciled_by
             WHERE v.status = 'approved' AND l.account_id = :account AND v.voucher_date BETWEEN :from AND :to
             ORDER BY v.voucher_date, v.id, l.line_no",
            ['account' => $bankAccountId, 'from' => $from, 'to' => $to]
        );
        $totals = \fetchRows(
            $this->db,
            "SELECT COALESCE(SUM(l.debit - l.credit), 0) AS book,
                    COALESCE(SUM(CASE WHEN r.voucher_line_id IS NOT NULL THEN l.debit - l.credit ELSE 0 END), 0) AS reconciled,
                    COALESCE(SUM(CASE WHEN r.voucher_line_id IS NULL THEN l.debit ELSE 0 END), 0) AS uncleared_in,
                    COALESCE(SUM(CASE WHEN r.voucher_line_id IS NULL THEN l.credit ELSE 0 END), 0) AS uncleared_out
             FROM acc_voucher_lines l
             JOIN acc_vouchers v ON v.id = l.voucher_id
             LEFT JOIN acc_bank_reconciliations r ON r.voucher_line_id = l.id
             WHERE v.status = 'approved' AND l.account_id = :account",
            ['account' => $bankAccountId]
        )[0];

        return [
            'rows' => $rows,
            'book' => Money::fromDb($totals['book']),
            'reconciled' => Money::fromDb($totals['reconciled']),
            'uncleared_in' => Money::fromDb($totals['uncleared_in']),
            'uncleared_out' => Money::fromDb($totals['uncleared_out']),
        ];
    }

    /** @param list<int> $lineIds */
    public function reconcile(AccessContext $actor, AuditTrail $audit, int $bankAccountId, array $lineIds, string $statementDate, bool $mark): int
    {
        if (!$actor->can(Capability::BANK_RECONCILE)) {
            throw AccessDeniedException::forCapability(Capability::BANK_RECONCILE);
        }
        $bank = $this->bankAccount($bankAccountId, false);
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $id): bool => $id > 0)));
        if ($lineIds === []) {
            throw new RuntimeException('অন্তত একটি লেনদেন নির্বাচন করুন।');
        }
        if ($mark) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $statementDate);
            if ($parsed === false || $parsed->format('Y-m-d') !== $statementDate || $statementDate > AuditTrail::now()->format('Y-m-d')) {
                throw new RuntimeException('ব্যাংক স্টেটমেন্টের তারিখ সঠিক নয়।');
            }
        }
        $valid = \fetchRows(
            $this->db,
            "SELECT l.id, v.voucher_date FROM acc_voucher_lines l JOIN acc_vouchers v ON v.id = l.voucher_id
             WHERE v.status = 'approved' AND l.account_id = :account AND l.id IN (" . implode(',', $lineIds) . ')',
            ['account' => $bankAccountId]
        );
        if (count($valid) !== count($lineIds)) {
            throw new RuntimeException('কিছু লেনদেন এই ব্যাংক হিসাবের নয়।');
        }
        $changed = 0;
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        foreach ($valid as $line) {
            if ($mark) {
                if ($statementDate < (string) $line['voucher_date']) {
                    throw new RuntimeException('স্টেটমেন্টের তারিখ লেনদেনের তারিখের আগে হতে পারে না।');
                }
                $statement = $this->db->prepare('INSERT IGNORE INTO acc_bank_reconciliations (voucher_line_id, statement_date, reconciled_by, reconciled_at) VALUES (:line, :date, :actor, :now)');
                $statement->execute(['line' => (int) $line['id'], 'date' => $statementDate, 'actor' => $actor->teacherId(), 'now' => $now]);
            } else {
                $statement = $this->db->prepare('DELETE FROM acc_bank_reconciliations WHERE voucher_line_id = :line');
                $statement->execute(['line' => (int) $line['id']]);
            }
            $changed += $statement->rowCount();
        }
        if ($changed > 0) {
            $audit->record($mark ? 'bank.reconcile' : 'bank.unreconcile', 'account', (int) $bank['id'], null, ['lines' => array_map('intval', array_column($valid, 'id')), 'statement_date' => $mark ? $statementDate : null], ($mark ? 'ব্যাংক মিলকরণ: ' : 'ব্যাংক মিলকরণ বাতিল: ') . $bank['code'] . ' — ' . bnDigits((string) $changed) . 'টি লেনদেন');
        }

        return $changed;
    }

    /** @return array<string, mixed> */
    private function bankAccount(int $id, bool $activeOnly = true): array
    {
        $account = $this->chart->account($id);
        if ($account === null || (string) $account['money_kind'] !== 'bank' || (int) $account['is_group'] === 1 || ($activeOnly && (int) $account['is_active'] !== 1)) {
            throw new RuntimeException('ব্যাংক হিসাব নির্বাচন করুন।');
        }

        return $account;
    }
}
