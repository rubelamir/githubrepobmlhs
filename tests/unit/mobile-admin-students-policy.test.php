<?php
declare(strict_types=1);

define('MOBILE_ADMIN_STUDENTS_TESTING', true);
require_once __DIR__ . '/../../bmlhs/api/v1/admin/students.php';

$moduleCases = [
    ['GET', 'list', 'students'],
    ['GET', 'detail', 'students'],
    ['GET', 'admission_applications', 'students'],
    ['GET', 'admission_options', 'students'],
    ['GET', 'create_options', 'students'],
    ['GET', 'roll_check', 'students'],
    ['POST', 'create', 'students'],
    ['POST', 'reset_password', 'students'],
    ['POST', 'approve_admission', 'students'],
    ['POST', 'reject_admission', 'students'],
    ['POST', 'restore_admission', 'students'],
    ['POST', 'update', 'students'],
    ['GET', 'promotion_candidates', 'promotions'],
    ['GET', 'promotion_options', 'promotions'],
    ['POST', 'promote', 'promotions'],
    ['POST', 'delete', null],
    ['GET', 'all', null],
];
foreach ($moduleCases as [$method, $action, $expected]) {
    $actual = mobileAdminStudentsRequiredModule($method, $action);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$method} {$action} permission module mismatch\n");
        exit(1);
    }
}

$permissionCases = [
    ['head', '', 'students', true],
    ['admin', '["students"]', 'students', true],
    ['admin', '["promotions"]', 'students', false],
    ['admin', '["promotions"]', 'promotions', true],
    ['admin', '["students"]', 'promotions', false],
    ['teacher', '[]', 'students', false],
    ['teacher', '[]', 'promotions', false],
];
foreach ($permissionCases as [$role, $modules, $module, $expected]) {
    $staff = [
        'panel_role' => $role,
        'admin_modules' => $modules,
        'teacher_designation' => '',
    ];
    if (canAccessModule($module, $staff) !== $expected) {
        fwrite(STDERR, "FAIL: {$role} permission mismatch for {$module}\n");
        exit(1);
    }
}

foreach ([
    ['1', 1],
    [42, 42],
    ['0', null],
    ['-1', null],
    ['1.5', null],
    ['1e2', null],
    [[], null],
    ['99999999999', null],
] as [$input, $expected]) {
    if (mobileAdminStudentsPositiveId($input) !== $expected) {
        fwrite(STDERR, 'FAIL: invalid student ID handling mismatch' . PHP_EOL);
        exit(1);
    }
}

foreach ([
    ['2000', true],
    ['2026', true],
    ['2199', true],
    ['1999', false],
    ['2200', false],
    ['20xx', false],
    ['20260', false],
] as [$session, $expected]) {
    if (mobileAdminStudentsValidSession($session) !== $expected) {
        fwrite(STDERR, "FAIL: session validation mismatch for {$session}\n");
        exit(1);
    }
}

foreach ([
    ['1', true],
    ['2147483647', true],
    ['0', false],
    ['-1', false],
    ['1.2', false],
    ['2147483648', false],
] as [$roll, $expected]) {
    if (mobileAdminStudentsValidRoll($roll) !== $expected) {
        fwrite(STDERR, "FAIL: roll validation mismatch for {$roll}\n");
        exit(1);
    }
}

$allowedFields = mobileAdminStudentsEditableFields();
foreach (['name_eng', 'mobile', 'birth_date', 'village_en'] as $field) {
    if (!in_array($field, $allowedFields, true)) {
        fwrite(STDERR, "FAIL: supported field {$field} is not editable\n");
        exit(1);
    }
}
foreach (['id', 'status', 'cls', 'sections', 'cls_roll', 'session_year', 'pass', 'student_pic'] as $field) {
    if (in_array($field, $allowedFields, true)) {
        fwrite(STDERR, "FAIL: protected field {$field} is editable\n");
        exit(1);
    }
}

$hashedPassword = mobileAdminStudentsPasswordHash('correct-horse-battery');
if (!password_verify('correct-horse-battery', $hashedPassword)) {
    fwrite(STDERR, "FAIL: reset password was not securely hashed\n");
    exit(1);
}
foreach ([null, 123, 'short', str_repeat('x', 1025)] as $password) {
    try {
        mobileAdminStudentsPasswordHash($password);
        fwrite(STDERR, "FAIL: invalid reset password was accepted\n");
        exit(1);
    } catch (InvalidArgumentException) {
    }
}

$dbWithoutConnection = new class extends PDO {
    public function __construct()
    {
    }
};
$createPayload = [
    'class_id' => '1',
    'section_id' => '1',
    'session_year' => '2026',
    'roll' => '1',
    'name_ban' => 'শিক্ষার্থী',
    'name_eng' => 'Student',
    'fname_ban' => 'পিতা',
    'fname_eng' => 'Father',
    'gender' => 'male',
    'mobile' => '01712345678',
    'password' => 'temporary-password',
];
foreach ([
    array_replace($createPayload, ['password' => 'short']),
    array_replace($createPayload, ['birth_date' => '2026-02-30']),
    array_replace($createPayload, ['gender' => []]),
    array_replace($createPayload, ['mobile' => 'invalid']),
    array_replace($createPayload, ['village' => []]),
] as $payload) {
    try {
        mobileAdminStudentsCreate($dbWithoutConnection, $payload);
        fwrite(STDERR, "FAIL: invalid direct admission payload was accepted\n");
        exit(1);
    } catch (InvalidArgumentException) {
    }
}

foreach ([
    [],
    ['class_id' => '1', 'section_id' => '1', 'session_year' => '2026', 'roll' => '0'],
    ['class_id' => '1', 'section_id' => [], 'session_year' => '2026', 'roll' => '1'],
] as $query) {
    try {
        mobileAdminStudentsCheckRoll($dbWithoutConnection, $query);
        fwrite(STDERR, "FAIL: invalid roll availability query was accepted\n");
        exit(1);
    } catch (InvalidArgumentException) {
    }
}

echo "PASS\n";
