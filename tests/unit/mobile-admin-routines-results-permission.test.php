<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bmlhs/api/v1/bootstrap.php';

$modules = ['routine', 'exam-routine', 'results', 'result-publication'];
$admin = [
    'panel_role' => 'admin',
    'admin_modules' => '["routine","result-publication"]',
    'teacher_designation' => '',
];
$teacher = [
    'panel_role' => 'teacher',
    'admin_modules' => '[]',
    'teacher_designation' => '',
];
$head = [
    'panel_role' => 'head',
    'admin_modules' => '[]',
    'teacher_designation' => '',
];

foreach ($modules as $module) {
    $expectedAdmin = in_array($module, ['routine', 'result-publication'], true);
    if (canAccessModule($module, $admin) !== $expectedAdmin) {
        fwrite(STDERR, "FAIL: admin module access was not exact for {$module}\n");
        exit(1);
    }
    if (!canAccessModule($module, $head)) {
        fwrite(STDERR, "FAIL: head access was denied for {$module}\n");
        exit(1);
    }
}
if (!canAccessModule('results', $teacher)
    || canAccessModule('routine', $teacher)
    || canAccessModule('exam-routine', $teacher)
    || canAccessModule('result-publication', $teacher)
) {
    fwrite(STDERR, "FAIL: teacher permissions do not match the existing module policy\n");
    exit(1);
}

$assignedSubject = ['teacher_mobile' => '01700000001'];
if (!canEditClassSubject($assignedSubject, $teacher + ['teacher_mobile' => '01700000001'])
    || canEditClassSubject($assignedSubject, $teacher + ['teacher_mobile' => '01700000002'])
) {
    fwrite(STDERR, "FAIL: subject ownership must remain restricted to its assigned teacher\n");
    exit(1);
}

$contracts = [
    'routine endpoint' => [
        __DIR__ . '/../../bmlhs/api/v1/admin/routine.php',
        ["canAccessModule('routine'", 'saveClassRoutineDay(', 'clearClassRoutineDay(', 'saveRoutineSettings('],
    ],
    'exam routine endpoint' => [
        __DIR__ . '/../../bmlhs/api/v1/admin/exam-routine.php',
        ["canAccessModule('exam-routine'", 'classSubjects($db, $classId, $sectionId)', 'saveExamRoutineRow(', 'clearExamRoutine('],
    ],
    'results endpoint' => [
        __DIR__ . '/../../bmlhs/api/v1/admin/results.php',
        ["canAccessModule('result-publication'", "canAccessModule('results'", 'canEditClassSubject(', 'marksByStudentForSubject('],
    ],
];
foreach ($contracts as $label => [$path, $needles]) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        fwrite(STDERR, "FAIL: could not read {$label}\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            fwrite(STDERR, "FAIL: {$label} is missing required authorization/workflow call {$needle}\n");
            exit(1);
        }
    }
    if (preg_match('/\b(?:CREATE|ALTER)\s+TABLE\b/i', $source) === 1) {
        fwrite(STDERR, "FAIL: {$label} must not introduce schema/write semantics\n");
        exit(1);
    }
}

$marksEndpoint = file_get_contents(__DIR__ . '/../../bmlhs/api/v1/teacher/marks.php');
if (!is_string($marksEndpoint)
    || !str_contains($marksEndpoint, "(\$submission['status'] ?? '') === 'submitted'")
    || !str_contains($marksEndpoint, "['save', 'final_submit']")
    || !str_contains($marksEndpoint, 'FOR UPDATE')
) {
    fwrite(STDERR, "FAIL: existing draft/final-submit lock workflow is not in place\n");
    exit(1);
}

echo "PASS\n";
