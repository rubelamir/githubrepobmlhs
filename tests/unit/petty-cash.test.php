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
$check(!empty($equalShare['equal']) && $equalShare['left'] === 50 && $equalShare['right'] === 50, 'wide sides share equally 50/50');
$check(!$equalShare['left_fit'] && !$equalShare['right_fit'], 'equal wide sides are not content-fit');
$equalCols = pettyCashReportColumnPercents($equalShare);
$check($equalCols['ldesc'] === $equalCols['rdesc'], 'equal panes get equal বিবরণ column widths');
$check(($equalCols['ld'] + $equalCols['ldesc'] + $equalCols['la'] + $equalCols['rd'] + $equalCols['rdesc'] + $equalCols['ra']) === 100, 'column percents sum to 100');

$bothShort = pettyCashReportDescWidthShare(
    [['description' => 'চা']],
    [['description' => 'কফি']]
);
$check(!empty($bothShort['equal']) && $bothShort['left'] === 50, 'both short sides stay equal');

$skewShare = pettyCashReportDescWidthShare(
    [['description' => 'চা']],
    [['description' => str_repeat('খুব লম্বা ব্যয়ের বিবরণ ', 8)]]
);
$check($skewShare['left_fit'] && !$skewShare['right_fit'] && empty($skewShare['equal']), 'short income pane is content-fit');
$check($skewShare['left'] < 50 && $skewShare['right'] > 50, 'long expense pane receives leftover width');
$skewCols = pettyCashReportColumnPercents($skewShare);
$check($skewCols['ldesc'] < $skewCols['rdesc'], 'short-side বিবরণ stays tighter than long side');
$check(($skewCols['ld'] + $skewCols['ldesc'] + $skewCols['la']) === $skewShare['left'], 'fit pane columns match pane width');

$wideDoc = pettyCashReportDocument(
    'মার্চ-' . bnDigits('2025'),
    '2025-03-01',
    '2025-03-31',
    [['entry_date' => '2025-03-01', 'description' => str_repeat('লম্বা আয়ের বিবরণ ', 8), 'amount' => '100.00']],
    [['entry_date' => '2025-03-02', 'description' => str_repeat('লম্বা ব্যয়ের বিবরণ ', 8), 'amount' => '100.00']],
    10000,
    10000,
    true
);
$check(str_contains($wideDoc['html'], 'table class="report"'), 'print/PDF uses a single report table');
$check(!str_contains($wideDoc['html'], 'desc-fit'), 'both-wide print/PDF allows wrap (no desc-fit)');
$check(str_contains($wideDoc['css'], 'white-space:normal'), 'both-wide print/PDF CSS wraps বিবরণ');

$doc = pettyCashReportDocument(
    'মার্চ-' . bnDigits('2025'),
    '2025-03-01',
    '2025-03-31',
    [['entry_date' => '2025-03-01', 'description' => 'চা', 'amount' => '100.00']],
    [
        ['entry_date' => '2025-03-02', 'description' => str_repeat('খুব লম্বা ব্যয়ের বিবরণ ', 8), 'amount' => '300.00'],
        ['entry_date' => '2025-03-03', 'description' => 'আরও ব্যয়', 'amount' => '200.00'],
    ],
    10000,
    50000,
    true
);
$check(str_contains($doc['html'], 'desc-fit'), 'print/PDF marks short বিবরণ as content-fit');
$check(str_contains($doc['html'], 'মোট আয়') && str_contains($doc['html'], 'মোট ব্যয়'), 'print/PDF includes both totals');
// Shorter income side is padded so both totals sit on one row after equal body rows.
$check(substr_count($doc['html'], '<tr>') >= 4, 'print/PDF pads shorter side with empty rows before totals');
$totalsPos = strpos($doc['html'], 'class="totals"');
$check($totalsPos !== false && str_contains(substr($doc['html'], $totalsPos, 280), 'মোট আয়')
    && str_contains(substr($doc['html'], $totalsPos, 280), 'মোট ব্যয়'), 'মোট আয় and মোট ব্যয় share one totals row');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' check(s) failed' . PHP_EOL);
    exit(1);
}
echo 'PASS' . PHP_EOL;
