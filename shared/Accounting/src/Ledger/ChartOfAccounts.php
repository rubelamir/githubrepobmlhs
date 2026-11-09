<?php

declare(strict_types=1);

namespace School\Accounting\Ledger;

use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Support\Money;

/**
 * Chart of accounts. Group accounts only organise the tree; postings go to
 * leaf accounts. System accounts are used by automatic postings, so their
 * code, type and parent are fixed and they cannot be deactivated.
 */
final class ChartOfAccounts
{
    public const TYPES = [
        'asset' => 'সম্পদ',
        'liability' => 'দায়',
        'fund' => 'তহবিল',
        'income' => 'আয়',
        'expense' => 'ব্যয়',
    ];

    public const MONEY_KINDS = [
        'cash' => 'নগদ',
        'bank' => 'ব্যাংক',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'teacher_custody' => 'শিক্ষকের কাছে',
        'none' => '—',
    ];

    /** Accounts whose credit balance can never exceed what is physically there. */
    public const SPENDABLE_KINDS = ['cash', 'bank', 'bkash', 'nagad'];

    public const CASH = '1110';
    public const TEACHER_CUSTODY = '1120';
    public const BKASH = '1130';
    public const NAGAD = '1140';
    public const BANK_GROUP = '1150';
    public const GATEWAY_CHARGE = '5160';
    public const ADVANCE = '2110';
    public const LATE_FINE = '4140';
    public const OTHER_FEES = '4190';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $byCode = null;

    public function __construct(private PDO $db)
    {
    }

    public static function isDebitNormal(string $type): bool
    {
        return $type === 'asset' || $type === 'expense';
    }

    /** @return list<array<string, mixed>> ordered by code, with parent code */
    public function all(bool $activeOnly = false): array
    {
        return \fetchRows(
            $this->db,
            'SELECT a.*, p.code AS parent_code, p.name_bn AS parent_name FROM acc_accounts a
             LEFT JOIN acc_accounts p ON p.id = a.parent_id'
            . ($activeOnly ? ' WHERE a.is_active = 1' : '')
            . ' ORDER BY a.code'
        );
    }

    /** @return array<string, mixed>|null */
    public function account(int $id): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_accounts WHERE id = :id', ['id' => $id]);

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function byCode(string $code): array
    {
        if ($this->byCode === null) {
            $this->byCode = [];
            foreach (\fetchRows($this->db, 'SELECT * FROM acc_accounts') as $row) {
                $this->byCode[(string) $row['code']] = $row;
            }
        }
        if (!isset($this->byCode[$code])) {
            throw new RuntimeException('হিসাব ' . $code . ' পাওয়া যায়নি।');
        }

        return $this->byCode[$code];
    }

    public function idOf(string $code): int
    {
        return (int) $this->byCode($code)['id'];
    }

    /**
     * Active leaf accounts that vouchers may use.
     *
     * @param list<string>|null $moneyKinds
     * @return list<array<string, mixed>>
     */
    public function postable(?array $moneyKinds = null): array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_accounts WHERE is_active = 1 AND is_group = 0 ORDER BY code');
        if ($moneyKinds === null) {
            return $rows;
        }

        return array_values(array_filter($rows, static fn (array $row): bool => in_array((string) $row['money_kind'], $moneyKinds, true)));
    }

    /** @return list<array<string, mixed>> */
    public function bankAccounts(bool $activeOnly = true): array
    {
        return array_values(array_filter(
            $this->all($activeOnly),
            static fn (array $row): bool => (string) $row['money_kind'] === 'bank' && (int) $row['is_group'] === 0
        ));
    }

    /** Income account for an invoice line: late fines have their own account. */
    public function incomeAccountForLine(?int $headIncomeAccountId, string $lineKind): int
    {
        if ($lineKind === 'late_fine') {
            return $this->idOf(self::LATE_FINE);
        }

        return $headIncomeAccountId !== null && $headIncomeAccountId > 0 ? $headIncomeAccountId : $this->idOf(self::OTHER_FEES);
    }

    /**
     * Debit/credit totals of approved vouchers per account.
     *
     * @return array<int, array{debit: int, credit: int}>
     */
    public function totals(?string $fromDate = null, ?string $toDate = null): array
    {
        $sql = "SELECT l.account_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit
                FROM acc_voucher_lines l JOIN acc_vouchers v ON v.id = l.voucher_id
                WHERE v.status = 'approved'";
        $params = [];
        if ($fromDate !== null) {
            $sql .= ' AND v.voucher_date >= :from';
            $params['from'] = $fromDate;
        }
        if ($toDate !== null) {
            $sql .= ' AND v.voucher_date <= :to';
            $params['to'] = $toDate;
        }
        $totals = [];
        foreach (\fetchRows($this->db, $sql . ' GROUP BY l.account_id', $params) as $row) {
            $totals[(int) $row['account_id']] = ['debit' => Money::fromDb($row['debit']), 'credit' => Money::fromDb($row['credit'])];
        }

        return $totals;
    }

    /** Balance on the account's normal side (asset/expense: Dr − Cr; others: Cr − Dr). */
    public function balance(int $accountId, ?string $toDate = null): int
    {
        $account = $this->account($accountId);
        if ($account === null) {
            return 0;
        }
        $sql = "SELECT COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit
                FROM acc_voucher_lines l JOIN acc_vouchers v ON v.id = l.voucher_id
                WHERE v.status = 'approved' AND l.account_id = :account";
        $params = ['account' => $accountId];
        if ($toDate !== null) {
            $sql .= ' AND v.voucher_date <= :to';
            $params['to'] = $toDate;
        }
        $row = \fetchRows($this->db, $sql, $params)[0];
        $net = Money::fromDb($row['debit']) - Money::fromDb($row['credit']);

        return self::isDebitNormal((string) $account['account_type']) ? $net : -$net;
    }

    /**
     * Trial balance rows of leaf accounts with activity, plus totals.
     *
     * @return array{rows: list<array<string, mixed>>, debit: int, credit: int}
     */
    public function trialBalance(?string $toDate = null): array
    {
        $totals = $this->totals(null, $toDate);
        $rows = [];
        $debit = 0;
        $credit = 0;
        foreach ($this->all() as $account) {
            $id = (int) $account['id'];
            if (!isset($totals[$id])) {
                continue;
            }
            $net = $totals[$id]['debit'] - $totals[$id]['credit'];
            if ($net === 0) {
                continue;
            }
            $account['tb_debit'] = max(0, $net);
            $account['tb_credit'] = max(0, -$net);
            $debit += $account['tb_debit'];
            $credit += $account['tb_credit'];
            $rows[] = $account;
        }

        return ['rows' => $rows, 'debit' => $debit, 'credit' => $credit];
    }

    /** @param array<string, mixed> $input */
    public function save(AccessContext $actor, AuditTrail $audit, ?int $id, array $input): int
    {
        if (!$actor->can(Capability::CHART_MANAGE)) {
            throw AccessDeniedException::forCapability(Capability::CHART_MANAGE);
        }
        $name = FeeCatalog::text((string) ($input['name_bn'] ?? ''), 120);
        $description = FeeCatalog::text((string) ($input['description'] ?? ''), 255);
        if (mb_strlen($name, 'UTF-8') < 2) {
            throw new RuntimeException('হিসাবের নাম লিখুন।');
        }
        $now = AuditTrail::now()->format('Y-m-d H:i:s');

        if ($id !== null) {
            $before = $this->account($id);
            if ($before === null) {
                throw new RuntimeException('হিসাবটি পাওয়া যায়নি।');
            }
            $this->db->prepare('UPDATE acc_accounts SET name_bn = :name, description = :description, updated_at = :now WHERE id = :id')
                ->execute(['name' => $name, 'description' => $description, 'now' => $now, 'id' => $id]);
            $audit->record('account.update', 'account', $id, ['name_bn' => $before['name_bn'], 'description' => $before['description']], ['name_bn' => $name, 'description' => $description], 'হিসাব ' . $before['code'] . ' সংশোধন');
            $this->byCode = null;

            return $id;
        }

        $code = trim((string) ($input['code'] ?? ''));
        if (preg_match('/^[1-5]\d{3,5}$/', $code) !== 1) {
            throw new RuntimeException('হিসাব কোড ৪–৬ অঙ্কের সংখ্যা হবে এবং ১ (সম্পদ) থেকে ৫ (ব্যয়) দিয়ে শুরু হবে।');
        }
        $parent = $this->account((int) ($input['parent_id'] ?? 0));
        if ($parent === null || (int) $parent['is_group'] !== 1) {
            throw new RuntimeException('একটি মূল গ্রুপ নির্বাচন করুন।');
        }
        $type = (string) $parent['account_type'];
        $expectedDigit = ['asset' => '1', 'liability' => '2', 'fund' => '3', 'income' => '4', 'expense' => '5'][$type];
        if ($code[0] !== $expectedDigit) {
            throw new RuntimeException(self::TYPES[$type] . ' হিসাবের কোড ' . bnDigits($expectedDigit) . ' দিয়ে শুরু হবে।');
        }
        $isGroup = !empty($input['is_group']) ? 1 : 0;
        $moneyKind = 'none';
        if ($type === 'asset') {
            $moneyKind = (string) $parent['money_kind'] === 'bank' ? 'bank' : (string) ($input['money_kind'] ?? 'none');
            if (!in_array($moneyKind, ['none', 'cash', 'bank'], true)) {
                throw new RuntimeException('অর্থের ধরন সঠিক নয়।');
            }
        }
        if (\fetchRows($this->db, 'SELECT id FROM acc_accounts WHERE code = :code', ['code' => $code]) !== []) {
            throw new RuntimeException('কোড ' . $code . ' আগেই ব্যবহৃত হয়েছে।');
        }
        $this->db->prepare(
            'INSERT INTO acc_accounts (code, name_bn, account_type, money_kind, parent_id, is_group, is_system, is_active, description, created_at, updated_at)
             VALUES (:code, :name, :type, :money, :parent, :is_group, 0, 1, :description, :now, :now2)'
        )->execute([
            'code' => $code, 'name' => $name, 'type' => $type, 'money' => $moneyKind, 'parent' => (int) $parent['id'],
            'is_group' => $isGroup, 'description' => $description, 'now' => $now, 'now2' => $now,
        ]);
        $newId = (int) $this->db->lastInsertId();
        $audit->record('account.create', 'account', $newId, null, ['code' => $code, 'name_bn' => $name, 'type' => $type, 'money_kind' => $moneyKind, 'is_group' => $isGroup], 'নতুন হিসাব ' . $code . ' ' . $name);
        $this->byCode = null;

        return $newId;
    }

    public function setActive(AccessContext $actor, AuditTrail $audit, int $id, bool $active): void
    {
        if (!$actor->can(Capability::CHART_MANAGE)) {
            throw AccessDeniedException::forCapability(Capability::CHART_MANAGE);
        }
        $account = $this->account($id);
        if ($account === null) {
            throw new RuntimeException('হিসাবটি পাওয়া যায়নি।');
        }
        if (!$active) {
            if ((int) $account['is_system'] === 1) {
                throw new RuntimeException('সিস্টেম হিসাব নিষ্ক্রিয় করা যায় না।');
            }
            if ($this->balance($id) !== 0) {
                throw new RuntimeException('হিসাবে স্থিতি আছে; স্থিতি শূন্য না হলে নিষ্ক্রিয় করা যাবে না।');
            }
            if (\fetchRows($this->db, 'SELECT id FROM acc_accounts WHERE parent_id = :id AND is_active = 1', ['id' => $id]) !== []) {
                throw new RuntimeException('গ্রুপের ভেতরে সক্রিয় হিসাব আছে।');
            }
            $pending = \fetchRows(
                $this->db,
                "SELECT v.id FROM acc_voucher_lines l JOIN acc_vouchers v ON v.id = l.voucher_id
                 WHERE l.account_id = :id AND v.status IN ('draft', 'submitted') LIMIT 1",
                ['id' => $id]
            );
            if ($pending !== []) {
                throw new RuntimeException('অনিষ্পন্ন ভাউচারে হিসাবটি ব্যবহৃত হয়েছে।');
            }
        }
        $this->db->prepare('UPDATE acc_accounts SET is_active = :active, updated_at = :now WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'id' => $id]);
        $audit->record($active ? 'account.activate' : 'account.deactivate', 'account', $id, ['is_active' => (int) $account['is_active']], ['is_active' => $active ? 1 : 0], 'হিসাব ' . $account['code'] . ($active ? ' সক্রিয়' : ' নিষ্ক্রিয়'));
        $this->byCode = null;
    }
}
