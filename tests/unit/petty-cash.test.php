<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';
require_once __DIR__ . '/../../shared/petty-cash.php';

use School\Accounting\Support\Money;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$month = pettyCashParseMonth('2026-11');
$check($month['from'] === '2026-11-01' && $month['to'] === '2026-11-30', 'November month bounds');
$check($month['key'] === '2026-11', 'month key is Y-m');

$fallback = pettyCashParseMonth('bad', new DateTimeImmutable('2026-03-15'));
$check($fallback['key'] === '2026-03' && $fallback['from'] === '2026-03-01', 'invalid month falls back to provided today');

$income = [
    ['id' => 1, 'entry_date' => '2026-11-01', 'description' => 'চা তহবিল', 'amount' => '100.00'],
    ['id' => 2, 'entry_date' => '2026-11-05', 'description' => 'দান', 'amount' => '50.50'],
];
$expense = [
    ['id' => 3, 'entry_date' => '2026-11-02', 'description' => 'স্টেশনারি', 'amount' => '40.00'],
];
$rows = pettyCashTableRows($income, $expense);
$check(count($rows) === 2, 'table pads the shorter expense side');
$check($rows[0]['income']['id'] === 1 && $rows[0]['expense']['id'] === 3, 'first row pairs income and expense');
$check($rows[1]['expense'] === null, 'missing expense cell stays empty');

$check(pettyCashTotalPaisa($income) === 15050, 'income total uses paisa');
$check(pettyCashTotalPaisa($expense) === 4000, 'expense total uses paisa');
$check(Money::format(15050 - 4000) === Money::format(11050), 'difference amount formats cleanly');

try {
    pettyCashValidateDescription('   ');
    $check(false, 'blank description must fail');
} catch (Throwable $exception) {
    $check(str_contains($exception->getMessage(), 'বিবরণ'), 'blank description is rejected');
}

try {
    pettyCashValidateDate('2026-13-40');
    $check(false, 'invalid date must fail');
} catch (Throwable $exception) {
    $check(str_contains($exception->getMessage(), 'তারিখ'), 'invalid date is rejected');
}

$check(pettyCashValidateDate('2026-11-22') === '2026-11-22', 'valid date passes');
$check(pettyCashValidateDescription('  চা   নাস্তা  ') === 'চা নাস্তা', 'description is trimmed and collapsed');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' check(s) failed' . PHP_EOL);
    exit(1);
}
echo 'PASS' . PHP_EOL;
