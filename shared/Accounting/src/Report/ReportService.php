<?php

declare(strict_types=1);

namespace School\Accounting\Report;

use PDO;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\Capability;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\PeriodClosing;
use School\Accounting\Support\Money;

/**
 * Read-only statements. Amounts stay integer paisa. Class teachers only see
 * collection, dues and concession rows inside their class scope.
 */
final class ReportService
{
    public const TRIAL = 'trial';
    public const INCOME = 'income';
    public const CASH = 'cash';
    public const COLLECTION = 'collection';
    public const DUES = 'dues';
    public const CONCESSION = 'concession';

    public const LABELS = [
        self::TRIAL => 'উদ্বৃত্ত',
        self::INCOME => 'আয়-ব্যয় বিবরণী',
        self::CASH => 'নগদ ও ব্যাংক',
        self::COLLECTION => 'ফি আদায়',
        self::DUES => 'শ্রেণীভিত্তিক বকেয়া',
        self::CONCESSION => 'বৃত্তি ও ছাড়',
    ];

    public function __construct(private PDO $db, private ChartOfAccounts $chart)
    {
    }

    public static function allows(AccessContext $actor, string $type): bool
    {
        if (!isset(self::LABELS[$type])) {
            return false;
        }
        if (in_array($type, [self::COLLECTION, self::DUES, self::CONCESSION], true)) {
            return $actor->can(Capability::REPORTS_VIEW_CLASS) || $actor->can(Capability::REPORTS_VIEW_ALL);
        }

        return $actor->can(Capability::REPORTS_VIEW_ALL);
    }

    /**
     * Cumulative balances of leaf accounts up to $to. Debit column equals credit column.
     *
     * @param list<array<string, mixed>> $accounts
     * @param array<int, array{debit: int, credit: int}> $totals
     * @return array{rows: list<array{code: string, name: string, debit: int, credit: int}>, debit: int, credit: int}
     */
    public static function trialRows(array $accounts, array $totals): array
    {
        $rows = [];
        $debit = 0;
        $credit = 0;
        foreach ($accounts as $account) {
            if ((int) ($account['is_group'] ?? 0) === 1) {
                continue;
            }
            $id = (int) $account['id'];
            $dr = $totals[$id]['debit'] ?? 0;
            $cr = $totals[$id]['credit'] ?? 0;
            $normal = ChartOfAccounts::isDebitNormal((string) $account['account_type']) ? $dr - $cr : $cr - $dr;
            if ($normal === 0) {
                continue;
            }
            $rowDebit = $normal > 0 && ChartOfAccounts::isDebitNormal((string) $account['account_type']) ? $normal : ($normal < 0 && !ChartOfAccounts::isDebitNormal((string) $account['account_type']) ? -$normal : 0);
            $rowCredit = $normal > 0 && !ChartOfAccounts::isDebitNormal((string) $account['account_type']) ? $normal : ($normal < 0 && ChartOfAccounts::isDebitNormal((string) $account['account_type']) ? -$normal : 0);
            $rows[] = ['code' => (string) $account['code'], 'name' => (string) $account['name_bn'], 'debit' => $rowDebit, 'credit' => $rowCredit];
            $debit += $rowDebit;
            $credit += $rowCredit;
        }

        return ['rows' => $rows, 'debit' => $debit, 'credit' => $credit];
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @param array<int, array{debit: int, credit: int}> $totals period movement
     * @return array{income: list<array{code: string, name: string, amount: int}>, expense: list<array{code: string, name: string, amount: int}>, income_total: int, expense_total: int, surplus: int}
     */
    public static function incomeRows(array $accounts, array $totals): array
    {
        $income = [];
        $expense = [];
        $incomeTotal = 0;
        $expenseTotal = 0;
        foreach ($accounts as $account) {
            if ((int) ($account['is_group'] ?? 0) === 1) {
                continue;
            }
            $id = (int) $account['id'];
            $dr = $totals[$id]['debit'] ?? 0;
            $cr = $totals[$id]['credit'] ?? 0;
            $type = (string) $account['account_type'];
            if ($type === 'income') {
                $amount = $cr - $dr;
                if ($amount === 0) {
                    continue;
                }
                $income[] = ['code' => (string) $account['code'], 'name' => (string) $account['name_bn'], 'amount' => $amount];
                $incomeTotal += $amount;
            } elseif ($type === 'expense') {
                $amount = $dr - $cr;
                if ($amount === 0) {
                    continue;
                }
                $expense[] = ['code' => (string) $account['code'], 'name' => (string) $account['name_bn'], 'amount' => $amount];
                $expenseTotal += $amount;
            }
        }

        return [
            'income' => $income,
            'expense' => $expense,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'surplus' => $incomeTotal - $expenseTotal,
        ];
    }

    /** @return array{rows: list<array{code: string, name: string, debit: int, credit: int}>, debit: int, credit: int, as_of: string} */
    public function trial(string $to): array
    {
        $presented = self::trialRows($this->chart->all(), $this->chart->totals(null, $to));
        $presented['as_of'] = $to;

        return $presented;
    }

    /** @return array{income: list<array{code: string, name: string, amount: int}>, expense: list<array{code: string, name: string, amount: int}>, income_total: int, expense_total: int, surplus: int, from: string, to: string} */
    public function income(string $from, string $to): array
    {
        $presented = self::incomeRows($this->chart->all(), $this->chart->totals($from, $to));
        $presented['from'] = $from;
        $presented['to'] = $to;

        return $presented;
    }

    /**
     * @return array{rows: list<array{code: string, name: string, opening: int, debit: int, credit: int, closing: int}>, opening: int, debit: int, credit: int, closing: int}
     */
    public function cash(string $from, string $to): array
    {
        $openingDate = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $movement = $this->chart->totals($from, $to);
        $rows = [];
        $opening = 0;
        $debit = 0;
        $credit = 0;
        $closing = 0;
        foreach ($this->chart->all() as $account) {
            if ((int) $account['is_group'] === 1 || !isset(ChartOfAccounts::MONEY_KINDS[$account['money_kind']]) || $account['money_kind'] === 'none') {
                continue;
            }
            $id = (int) $account['id'];
            $open = $this->chart->balance($id, $openingDate);
            $dr = $movement[$id]['debit'] ?? 0;
            $cr = $movement[$id]['credit'] ?? 0;
            $close = $open + $dr - $cr;
            if ($open === 0 && $dr === 0 && $cr === 0) {
                continue;
            }
            $rows[] = ['code' => (string) $account['code'], 'name' => (string) $account['name_bn'], 'opening' => $open, 'debit' => $dr, 'credit' => $cr, 'closing' => $close];
            $opening += $open;
            $debit += $dr;
            $credit += $cr;
            $closing += $close;
        }

        return ['rows' => $rows, 'opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $closing];
    }

    /**
     * @return list<array{class_id: int, section_id: int, method: string, count: int, amount: int}>
     */
    public function collection(AccessContext $actor, string $from, string $to): array
    {
        $end = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        $rows = [];
        foreach (\fetchRows(
            $this->db,
            "SELECT class_id, section_id, method, COUNT(*) AS n, SUM(amount) AS amount
             FROM acc_payments
             WHERE status = 'confirmed' AND collected_at >= :from AND collected_at < :end
             GROUP BY class_id, section_id, method
             ORDER BY class_id, section_id, method",
            ['from' => $from . ' 00:00:00', 'end' => $end . ' 00:00:00']
        ) as $row) {
            if (!$this->inScope($actor, (int) $row['class_id'], (int) $row['section_id'])) {
                continue;
            }
            $rows[] = [
                'class_id' => (int) $row['class_id'],
                'section_id' => (int) $row['section_id'],
                'method' => (string) $row['method'],
                'count' => (int) $row['n'],
                'amount' => Money::fromDb($row['amount']),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{class_id: int, section_id: int, invoices: int, net: int, paid: int, due: int, students_due: int}>
     */
    public function dues(AccessContext $actor, string $sessionYear): array
    {
        $rows = [];
        foreach (\fetchRows(
            $this->db,
            "SELECT class_id, section_id, COUNT(*) AS invoices, SUM(net) AS net, SUM(paid) AS paid,
                    COUNT(DISTINCT CASE WHEN net > paid THEN student_id END) AS students_due
             FROM acc_invoices
             WHERE session_year = :year AND status <> 'cancelled'
             GROUP BY class_id, section_id
             ORDER BY class_id, section_id",
            ['year' => $sessionYear]
        ) as $row) {
            if (!$this->inScope($actor, (int) $row['class_id'], (int) $row['section_id'])) {
                continue;
            }
            $net = Money::fromDb($row['net']);
            $paid = Money::fromDb($row['paid']);
            $rows[] = [
                'class_id' => (int) $row['class_id'],
                'section_id' => (int) $row['section_id'],
                'invoices' => (int) $row['invoices'],
                'net' => $net,
                'paid' => $paid,
                'due' => $net - $paid,
                'students_due' => (int) $row['students_due'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{category: string, concession: int, net: int}>
     */
    public function concession(AccessContext $actor, string $sessionYear): array
    {
        $grouped = [];
        foreach (\fetchRows(
            $this->db,
            "SELECT inv.class_id, inv.section_id, COALESCE(cat.name_bn, 'সাধারণ') AS category,
                    SUM(i.category_concession) AS concession, SUM(i.net) AS net
             FROM acc_invoice_items i
             JOIN acc_invoices inv ON inv.id = i.invoice_id
             LEFT JOIN acc_fee_categories cat ON cat.id = i.fee_category_id
             WHERE inv.session_year = :year AND inv.status <> 'cancelled'
             GROUP BY inv.class_id, inv.section_id, i.fee_category_id, cat.name_bn",
            ['year' => $sessionYear]
        ) as $row) {
            if (!$this->inScope($actor, (int) $row['class_id'], (int) $row['section_id'])) {
                continue;
            }
            $name = (string) $row['category'];
            $grouped[$name]['concession'] = ($grouped[$name]['concession'] ?? 0) + Money::fromDb($row['concession']);
            $grouped[$name]['net'] = ($grouped[$name]['net'] ?? 0) + Money::fromDb($row['net']);
        }
        $rows = [];
        foreach ($grouped as $name => $amounts) {
            if ($amounts['concession'] === 0 && $amounts['net'] === 0) {
                continue;
            }
            $rows[] = ['category' => $name, 'concession' => $amounts['concession'], 'net' => $amounts['net']];
        }
        usort($rows, static fn (array $a, array $b): int => $b['concession'] <=> $a['concession']);

        return $rows;
    }

    /**
     * Last $months calendar months, oldest first. Income and expense are empty
     * unless the actor may see the whole ledger.
     *
     * @return array{labels: list<string>, keys: list<string>, collection: list<int>, income: list<int>, expense: list<int>}
     */
    public function charts(AccessContext $actor, int $months = 6): array
    {
        $months = max(1, min(12, $months));
        $end = new \DateTimeImmutable('first day of this month');
        $start = $end->modify('-' . ($months - 1) . ' months');
        $keys = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 month')) {
            $keys[] = $cursor->format('Y-m');
        }
        $collection = array_fill_keys($keys, 0);
        $endExclusive = $end->modify('+1 month')->format('Y-m-d');
        foreach (\fetchRows(
            $this->db,
            "SELECT DATE_FORMAT(collected_at, '%Y-%m') AS month, class_id, section_id, SUM(amount) AS amount
             FROM acc_payments
             WHERE status = 'confirmed' AND collected_at >= :from AND collected_at < :end
             GROUP BY month, class_id, section_id",
            ['from' => $start->format('Y-m-d') . ' 00:00:00', 'end' => $endExclusive . ' 00:00:00']
        ) as $row) {
            $month = (string) $row['month'];
            if (!isset($collection[$month]) || !$this->inScope($actor, (int) $row['class_id'], (int) $row['section_id'])) {
                continue;
            }
            $collection[$month] += Money::fromDb($row['amount']);
        }
        $income = array_fill_keys($keys, 0);
        $expense = array_fill_keys($keys, 0);
        if ($actor->can(Capability::REPORTS_VIEW_ALL)) {
            foreach (\fetchRows(
                $this->db,
                "SELECT DATE_FORMAT(v.voucher_date, '%Y-%m') AS month, a.account_type,
                        SUM(l.debit) AS debit, SUM(l.credit) AS credit
                 FROM acc_voucher_lines l
                 JOIN acc_vouchers v ON v.id = l.voucher_id
                 JOIN acc_accounts a ON a.id = l.account_id
                 WHERE v.status = 'approved' AND v.voucher_date >= :from AND v.voucher_date < :end
                   AND a.account_type IN ('income', 'expense')
                 GROUP BY month, a.account_type",
                ['from' => $start->format('Y-m-d'), 'end' => $endExclusive]
            ) as $row) {
                $month = (string) $row['month'];
                if (!isset($income[$month])) {
                    continue;
                }
                if ($row['account_type'] === 'income') {
                    $income[$month] += Money::fromDb($row['credit']) - Money::fromDb($row['debit']);
                } else {
                    $expense[$month] += Money::fromDb($row['debit']) - Money::fromDb($row['credit']);
                }
            }
        }

        return [
            'labels' => array_map(static fn (string $key): string => PeriodClosing::label($key), $keys),
            'keys' => $keys,
            'collection' => array_values($collection),
            'income' => $actor->can(Capability::REPORTS_VIEW_ALL) ? array_values($income) : [],
            'expense' => $actor->can(Capability::REPORTS_VIEW_ALL) ? array_values($expense) : [],
        ];
    }

    private function inScope(AccessContext $actor, int $classId, int $sectionId): bool
    {
        if ($actor->hasSchoolWideScope()) {
            return true;
        }
        $scope = $actor->classScope();
        if (isset($scope['entire'][$classId])) {
            return true;
        }

        return $sectionId > 0 && isset($scope['sections'][$classId][$sectionId]);
    }
}
