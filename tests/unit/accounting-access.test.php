<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Access\AccessContext;
use School\Accounting\Access\Capability;
use School\Accounting\Access\Role;
use School\Accounting\Audit\AuditTrail;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$noScope = ['entire' => [], 'sections' => []];
$head = new AccessContext(1, 'Head', [Role::HEAD], $noScope, '2026');
$officer = new AccessContext(2, 'Officer', [Role::OFFICER], $noScope, '2026');
$classTeacher = new AccessContext(3, 'Teacher', [Role::CLASS_TEACHER], ['entire' => [6 => true], 'sections' => [9 => [21 => true]]], '2026');
$officerAndTeacher = new AccessContext(4, 'Both', [Role::CLASS_TEACHER, Role::OFFICER], ['entire' => [], 'sections' => [9 => [21 => true]]], '2026');
$guest = AccessContext::guest();
$ghost = new AccessContext(0, '', [Role::HEAD], $noScope, '2026');

// The head appoints the officer and can see and verify every account. Day-to-day control belongs to the officer.
$headKeeps = [
    Capability::DASHBOARD_VIEW, Capability::OFFICERS_MANAGE, Capability::FEES_VIEW_ALL, Capability::FEES_VIEW_CLASS,
    Capability::CONCESSION_APPROVE, Capability::WAIVER_APPROVE, Capability::VOUCHER_APPROVE, Capability::REFUND_APPROVE,
    Capability::REPORTS_VIEW_ALL, Capability::REPORTS_VIEW_CLASS, Capability::AUDIT_VIEW,
];
$officerControls = [
    Capability::FEE_STRUCTURE_MANAGE, Capability::CHART_MANAGE, Capability::FEES_COLLECT, Capability::CONCESSION_REQUEST,
    Capability::INVOICE_MANAGE, Capability::INVOICE_CANCEL, Capability::WAIVER_REQUEST, Capability::PAYMENT_REVERSE,
    Capability::HANDOVER_RECEIVE, Capability::VOUCHER_CREATE, Capability::BANK_RECONCILE, Capability::GATEWAY_SETTINGS,
    Capability::REFUND_REQUEST, Capability::PERIOD_CLOSE,
];
foreach (array_keys(Capability::labels()) as $capability) {
    $check($head->can($capability) === in_array($capability, $headKeeps, true), "head capability {$capability}");
}
$classTeacherMayShare = [Capability::FEES_COLLECT, Capability::CONCESSION_REQUEST, Capability::WAIVER_REQUEST];
foreach ($officerControls as $capability) {
    $check($officer->can($capability), "officer controls {$capability}");
    $check(!$head->can($capability), "head does not control {$capability}");
    if (!in_array($capability, $classTeacherMayShare, true)) {
        $check(!$classTeacher->can($capability), "class teacher cannot {$capability}");
    }
}
foreach ([Capability::OFFICERS_MANAGE, Capability::CONCESSION_APPROVE, Capability::WAIVER_APPROVE, Capability::VOUCHER_APPROVE, Capability::REFUND_APPROVE, Capability::AUDIT_VIEW] as $capability) {
    $check(!$officer->can($capability), "officer cannot {$capability}");
}
$check($officer->can(Capability::VOUCHER_CREATE), 'officer creates vouchers');
$check($officer->can(Capability::HANDOVER_RECEIVE), 'officer receives handovers');
$check($officer->can(Capability::BANK_RECONCILE), 'officer reconciles bank');
$check(!$officer->can(Capability::HANDOVER_SUBMIT), 'officer does not submit handovers');
$check($classTeacher->can(Capability::HANDOVER_SUBMIT), 'class teacher submits handovers');
$check(!$classTeacher->can(Capability::FEES_VIEW_ALL), 'class teacher cannot view all fees');
$check(!$classTeacher->can(Capability::VOUCHER_CREATE), 'class teacher cannot create vouchers');
$check(!$classTeacher->can(Capability::REPORTS_VIEW_ALL), 'class teacher cannot view all reports');

// Class scope.
$check($classTeacher->canForClass(Capability::FEES_COLLECT, 6, 0), 'entire-class assignment, class without sections');
$check($classTeacher->canForClass(Capability::FEES_COLLECT, 6, 14), 'entire-class assignment covers any section');
$check($classTeacher->canForClass(Capability::FEES_COLLECT, 9, 21), 'assigned section');
$check(!$classTeacher->canForClass(Capability::FEES_COLLECT, 9, 22), 'other section of same class');
$check(!$classTeacher->canForClass(Capability::FEES_COLLECT, 9, 0), 'section assignment does not cover whole class');
$check(!$classTeacher->canForClass(Capability::FEES_COLLECT, 7, 0), 'unassigned class');
$check(!$classTeacher->canForClass(Capability::FEES_COLLECT, 0, 0), 'invalid class id');
$check($officer->canForClass(Capability::FEES_COLLECT, 7, 3), 'officer collects school-wide');
$check($officerAndTeacher->canForClass(Capability::FEES_COLLECT, 8, 1), 'officer role widens a class teacher scope');
$check($officerAndTeacher->can(Capability::HANDOVER_SUBMIT) && $officerAndTeacher->can(Capability::HANDOVER_RECEIVE), 'combined roles merge capabilities');
$check($officerAndTeacher->primaryRole() === Role::OFFICER, 'primary role follows precedence');

// No identity, no access.
$check(!$guest->hasAnyAccess() && !$guest->can(Capability::DASHBOARD_VIEW), 'guest has no access');
$check(!$ghost->can(Capability::OFFICERS_MANAGE), 'roles ignored without a teacher id');
$check(!(new AccessContext(5, 'X', ['superuser'], $noScope, '2026'))->hasAnyAccess(), 'unknown role ignored');

// Every capability in the matrix has a label, and every class-scoped one exists.
foreach (Capability::MATRIX as $role => $capabilities) {
    $check(Role::isKnown($role), "matrix role {$role} is known");
    foreach ($capabilities as $capability) {
        $check(isset(Capability::labels()[$capability]), "label for {$capability}");
    }
}
foreach (Capability::CLASS_SCOPED as $capability) {
    $check(isset(Capability::labels()[$capability]), "class-scoped {$capability} exists");
}

// Audit masking.
$masked = AuditTrail::mask([
    'amount' => '500.00',
    'app_secret' => 's3cr3t',
    'Password' => 'p',
    'nested' => ['id_token' => 'abc', 'refresh_token' => 'def', 'trx_id' => 'TRX1'],
    'idempotency_key' => 'keep-me',
]);
$check($masked['amount'] === '500.00', 'amount kept');
$check($masked['app_secret'] === '***', 'secret masked');
$check($masked['Password'] === '***', 'password masked case-insensitively');
$check($masked['nested']['id_token'] === '***' && $masked['nested']['refresh_token'] === '***', 'nested tokens masked');
$check($masked['nested']['trx_id'] === 'TRX1', 'transaction id kept');
$check($masked['idempotency_key'] === 'keep-me', 'idempotency key kept');

$changed = AuditTrail::changedKeys(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 1, 'b' => '2', 'd' => 4]);
sort($changed);
$check($changed === ['b', 'c', 'd'], 'changed keys detect type change, removal and addition');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
