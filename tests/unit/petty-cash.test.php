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
$check($month['label'] === 'নভেম্বর-' . bnDigits('2026'), 'month label is Bangla month-year');

$fallback = pettyCashParseMonth('bad', new DateTimeImmutable('2026-03-15'));
$check($fallback['key'] === '2026-03' && $fallback['from'] === '2026-03-01', 'invalid month falls back to provided today');
$check($fallback['label'] === 'মার্চ-' . bnDigits('2026'), 'fallback month uses Bangla label');

$today = new DateTimeImmutable('2026-11-22', new DateTimeZone('Asia/Dhaka'));
$check(pettyCashAssertCurrentMonthDate('2026-11-05', $today) === '2026-11-05', 'current-month date is allowed for create');
try {
    pettyCashAssertCurrentMonthDate('2026-10-31', $today);
    $check(false, 'other-month date must fail for create');
} catch (Throwable $exception) {
    $check(str_contains($exception->getMessage(), 'চলতি মাস'), 'other-month create shows warning text');
}
$check(pettyCashValidateDate('2021-01-15') === '2021-01-15', 'old-month dates remain valid for update/delete');
$check(function_exists('pettyCashDelete'), 'delete helper is available');

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

$jsonPath = pettyCashImportJsonPath();
$check(is_file($jsonPath), 'bundled Jan-21…May-26 JSON is present');
$payload = json_decode((string) file_get_contents($jsonPath), true);
$check(is_array($payload) && isset($payload['entries']) && is_array($payload['entries']), 'import JSON has entries');
$check(count($payload['entries']) >= 1900, 'import JSON covers the Excel history rows');
$first = $payload['entries'][0] ?? [];
$last = $payload['entries'][count($payload['entries']) - 1] ?? [];
$check(($first['date'] ?? '') === '2021-01-01', 'import starts at 2021-01-01');
$check(($last['date'] ?? '') >= '2026-05-01', 'import reaches May-26');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' check(s) failed' . PHP_EOL);
    exit(1);
}
echo 'PASS' . PHP_EOL;
