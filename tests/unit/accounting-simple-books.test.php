<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Ledger\SimpleBooks;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$reset = accResetTables();
$kept = [
    'students', 'student_info', 'teacher_info', 'cls', 'sections', 'users', 'settings',
    'acc_accounts', 'acc_fee_heads', 'acc_fee_structures', 'acc_fee_categories',
    'acc_student_fee_categories', 'acc_concessions', 'acc_officers', 'acc_gateway_settings',
];
foreach ($kept as $table) {
    $check(!in_array($table, $reset, true), $table . ' is not wiped');
}
foreach (['acc_payments', 'acc_invoices', 'acc_vouchers', 'acc_voucher_lines', 'acc_gateway_transactions', 'acc_audit_log'] as $table) {
    $check(in_array($table, $reset, true), $table . ' is wiped');
}
$check(count($reset) === count(array_unique($reset)), 'reset list has no duplicates');
$check(in_array('4110', SimpleBooks::FEE_INCOME_CODES, true), 'tuition stays on fee collection');
$check(SimpleBooks::STAFF_ADVANCE === '1180', 'staff advance account');
$check(SimpleBooks::FUND === '3100', 'opening balance credits the fund');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
