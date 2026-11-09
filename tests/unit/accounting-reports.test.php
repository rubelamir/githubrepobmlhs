<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Access\AccessContext;
use School\Accounting\Access\Role;
use School\Accounting\Report\ReportService;
use School\Accounting\Report\Workbook;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$accounts = [
    ['id' => 1, 'code' => '1110', 'name_bn' => 'নগদ', 'account_type' => 'asset', 'is_group' => 0],
    ['id' => 2, 'code' => '3100', 'name_bn' => 'সাধারণ তহবিল', 'account_type' => 'equity', 'is_group' => 0],
    ['id' => 3, 'code' => '4110', 'name_bn' => 'বেতন', 'account_type' => 'income', 'is_group' => 0],
    ['id' => 4, 'code' => '5110', 'name_bn' => 'বেতন খরচ', 'account_type' => 'expense', 'is_group' => 0],
    ['id' => 5, 'code' => '1100', 'name_bn' => 'সম্পদ', 'account_type' => 'asset', 'is_group' => 1],
    ['id' => 6, 'code' => '4190', 'name_bn' => 'অন্যান্য', 'account_type' => 'income', 'is_group' => 0],
];
$totals = [
    1 => ['debit' => 12000, 'credit' => 2000],
    2 => ['debit' => 0, 'credit' => 5000],
    3 => ['debit' => 500, 'credit' => 8000],
    4 => ['debit' => 2500, 'credit' => 0],
    5 => ['debit' => 999, 'credit' => 0],
    6 => ['debit' => 100, 'credit' => 100],
];
$trial = ReportService::trialRows($accounts, $totals);
$byCode = [];
foreach ($trial['rows'] as $row) {
    $byCode[$row['code']] = $row;
}
$check(!isset($byCode['1100']), 'group accounts stay off the trial balance');
$check(!isset($byCode['4190']), 'a zero balance is omitted');
$check($byCode['1110']['debit'] === 10000 && $byCode['1110']['credit'] === 0, 'debit-normal surplus sits in the debit column');
$check($byCode['3100']['credit'] === 5000 && $byCode['3100']['debit'] === 0, 'credit-normal surplus sits in the credit column');
$check($byCode['4110']['credit'] === 7500, 'income is shown on the credit side');
$check($byCode['5110']['debit'] === 2500, 'expense is shown on the debit side');
$check($trial['debit'] === $trial['credit'] && $trial['debit'] === 12500, 'trial balance columns are equal');

$income = ReportService::incomeRows($accounts, $totals);
$check($income['income_total'] === 7500, 'income is credit minus debit');
$check($income['expense_total'] === 2500, 'expense is debit minus credit');
$check($income['surplus'] === 5000, 'surplus is income minus expense');
$check(count($income['income']) === 1 && $income['income'][0]['code'] === '4110', 'zero income rows are omitted');

$overdrawn = ReportService::trialRows(
    [
        ['id' => 1, 'code' => '1110', 'name_bn' => 'নগদ', 'account_type' => 'asset', 'is_group' => 0],
        ['id' => 2, 'code' => '2110', 'name_bn' => 'অগ্রিম', 'account_type' => 'liability', 'is_group' => 0],
    ],
    [1 => ['debit' => 100, 'credit' => 400], 2 => ['debit' => 400, 'credit' => 100]]
);
$overdrawnByCode = [];
foreach ($overdrawn['rows'] as $row) {
    $overdrawnByCode[$row['code']] = $row;
}
$check($overdrawnByCode['1110']['credit'] === 300 && $overdrawnByCode['2110']['debit'] === 300, 'abnormal balances switch columns');
$check($overdrawn['debit'] === $overdrawn['credit'], 'abnormal balances still balance');

$reversed = ReportService::incomeRows(
    [['id' => 9, 'code' => '4110', 'name_bn' => 'বেতন', 'account_type' => 'income', 'is_group' => 0]],
    [9 => ['debit' => 400, 'credit' => 100]]
);
$check($reversed['income'][0]['amount'] === -300 && $reversed['surplus'] === -300, 'a reversed income account stays negative');

$xml = Workbook::xml('আদায়', ['শ্রেণী', 'টাকা', 'নোট'], [
    ['৬ষ্ঠ — ক', '1200.50', 'ঠিক আছে'],
    ['<script>', '-10.00', 'a & b'],
]);
$check(str_contains($xml, 'আদায়'), 'sheet name keeps Bengali');
$check(str_contains($xml, '<Data ss:Type="Number">1200.50</Data>'), 'money is a numeric cell');
$check(str_contains($xml, '<Data ss:Type="Number">-10.00</Data>'), 'negative money stays numeric');
$check(str_contains($xml, '&lt;script&gt;') && str_contains($xml, 'a &amp; b'), 'cell text is escaped');
$check(!str_contains($xml, '<script>'), 'raw markup cannot break the workbook');

$scope = ['entire' => [3 => true], 'sections' => [4 => [9 => true]]];
$teacher = new AccessContext(4, 'শ্রেণিশিক্ষক', [Role::CLASS_TEACHER], $scope, '2026');
$head = new AccessContext(1, 'প্রধান', [Role::HEAD], ['entire' => [], 'sections' => []], '2026');
foreach ([ReportService::COLLECTION, ReportService::DUES, ReportService::CONCESSION] as $classReport) {
    $check(ReportService::allows($teacher, $classReport), 'class teacher may open ' . $classReport);
}
foreach ([ReportService::TRIAL, ReportService::INCOME, ReportService::CASH] as $ledgerReport) {
    $check(!ReportService::allows($teacher, $ledgerReport), 'class teacher may not open ' . $ledgerReport);
    $check(ReportService::allows($head, $ledgerReport), 'head may open ' . $ledgerReport);
}
$check(!ReportService::allows($teacher, 'ledger'), 'unknown report types are refused');
$scoped = (new ReflectionClass(ReportService::class))->newInstanceWithoutConstructor();
$visible = new ReflectionMethod(ReportService::class, 'inScope');
$check($visible->invoke($scoped, $teacher, 3, 0), 'entire-class assignment sees that class');
$check($visible->invoke($scoped, $teacher, 4, 9), 'assigned section is visible');
$check(!$visible->invoke($scoped, $teacher, 4, 8), 'another section of the same class stays hidden');
$check(!$visible->invoke($scoped, $teacher, 4, 0), 'a section teacher does not see class-wide rows');
$check($visible->invoke($scoped, $head, 9, 0), 'school-wide roles see every class');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' check(s) failed' . PHP_EOL);
    exit(1);
}
echo 'PASS' . PHP_EOL;
