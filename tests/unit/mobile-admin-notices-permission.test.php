<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bmlhs/api/v1/bootstrap.php';

$cases = [
    ['head', '', true],
    ['admin', '["notices"]', true],
    ['admin', '["results"]', false],
    ['teacher', '[]', false],
];

foreach ($cases as [$role, $modules, $expected]) {
    $teacher = [
        'panel_role' => $role,
        'admin_modules' => $modules,
        'teacher_designation' => '',
    ];
    $actual = canAccessModule('notices', $teacher);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: notices permission mismatch for {$role} with {$modules}\n");
        exit(1);
    }
}

foreach ([
    ['head', '', true],
    ['admin', '["events"]', true],
    ['admin', '["notices"]', false],
    ['teacher', '[]', false],
] as [$role, $modules, $expected]) {
    $teacher = [
        'panel_role' => $role,
        'admin_modules' => $modules,
        'teacher_designation' => '',
    ];
    $actual = canAccessModule('events', $teacher);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: calendar permission mismatch for {$role} with {$modules}\n");
        exit(1);
    }
}

foreach ([
    ['head', '', true],
    ['admin', '["headlines"]', true],
    ['admin', '["notices"]', false],
    ['teacher', '[]', false],
] as [$role, $modules, $expected]) {
    $teacher = [
        'panel_role' => $role,
        'admin_modules' => $modules,
        'teacher_designation' => '',
    ];
    $actual = canAccessModule('headlines', $teacher);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: headlines permission mismatch for {$role} with {$modules}\n");
        exit(1);
    }
}

echo "PASS\n";
