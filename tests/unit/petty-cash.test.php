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

$check(pettyCashAssertSelectedMonthDate('2021-01-15', '2021-01') === '2021-01-15', 'selected-month date is allowed');
try {
    pettyCashAssertSelectedMonthDate('2021-02-01', '2021-01');
    $check(false, 'date outside selected month must fail');
} catch (Throwable $exception) {
    $check(str_contains($exception->getMessage(), 'নির্বাচিত মাস'), 'outside selected month shows warning text');
}
$check(pettyCashValidateDate('2021-01-15') === '2021-01-15', 'old-month dates remain valid');
$check(function_exists('pettyCashDelete'), 'delete helper is available');
$check(function_exists('pettyCashSearch'), 'search helper is available');
$check(function_exists('pettyCashReorder'), 'reorder helper is available');
$check(pettyCashParseAmount('1250') === 125000, 'whole taka parses to paisa');
$check(pettyCashFormatAmount(125000) === '৳ ' . bnDigits('1,250'), 'format omits .00');
try {
    pettyCashParseAmount('1250.50');
    $check(false, 'fractional amount must fail');
} catch (Throwable $exception) {
    $check(str_contains($exception->getMessage(), 'পূর্ণ সংখ্যা'), 'fractional amount rejected');
}

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

$logoUri = pettyCashBrowserLogoDataUri(['logo' => 'image/school_logo.png']);
$check(str_starts_with($logoUri, 'data:image/'), 'browser print logo embeds as a data URI');
$check(str_contains(pettyCashBrowserPadHtml([
    'logo' => 'image/school_logo.png',
    'school_name_bn' => 'টেস্ট স্কুল',
    'address' => '',
    'eiin' => '',
    'mobile' => '',
]), 'school-pad-logo-img'), 'browser pad HTML includes the logo image');

$equalShare = pettyCashReportDescWidthShare(
    [['description' => str_repeat('লম্বা বিবরণ ', 6)], ['description' => str_repeat('আরও লম্বা ', 6)]],
    [['description' => str_repeat('লম্বা ব্যয় ', 6)], ['description' => str_repeat('আরও ব্যয় ', 6)]]
);
$check(!empty($equalShare['equal']), 'wide descriptions use equal print/PDF layout');
$check(!$equalShare['left_fit'] && !$equalShare['right_fit'], 'equal wide sides are not content-fit');

$skewShare = pettyCashReportDescWidthShare(
    [['description' => 'চা']],
    [['description' => str_repeat('খুব লম্বা ব্যয়ের বিবরণ ', 8)]]
);
$check($skewShare['left_fit'] && !$skewShare['right_fit'] && empty($skewShare['equal']), 'short income description is content-fit in print/PDF');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' check(s) failed' . PHP_EOL);
    exit(1);
}
echo 'PASS' . PHP_EOL;
