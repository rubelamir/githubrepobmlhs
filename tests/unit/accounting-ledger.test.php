<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\PeriodClosing;
use School\Accounting\Ledger\VoucherService;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$check(PeriodClosing::validPeriod('2026-10'), 'valid period');
foreach (['2026-13', '2026-1', '26-10', '2026-10-01', '', '1999-01'] as $bad) {
    $check(!PeriodClosing::validPeriod($bad), "reject period '{$bad}'");
}
$check(PeriodClosing::periodOf('2026-11-09') === '2026-11', 'period of a date');
$check(PeriodClosing::label('2026-02') === 'ফেব্রুয়ারি ২০২৬', 'Bengali month label');

$check(ChartOfAccounts::isDebitNormal('asset') && ChartOfAccounts::isDebitNormal('expense'), 'assets and expenses are debit-normal');
foreach (['liability', 'fund', 'income'] as $type) {
    $check(!ChartOfAccounts::isDebitNormal($type), "{$type} is credit-normal");
}
$check(!in_array('teacher_custody', ChartOfAccounts::SPENDABLE_KINDS, true), 'teacher custody is not spendable from vouchers');

$check(array_keys(VoucherService::PREFIXES) === array_keys(VoucherService::TYPES), 'every voucher type has a number prefix');
$check(count(array_unique(VoucherService::PREFIXES)) === count(VoucherService::PREFIXES), 'voucher prefixes are distinct');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
