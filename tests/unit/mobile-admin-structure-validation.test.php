<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bmlhs/api/v1/admin/structure_helpers.php';

$modules = ['classes', 'sections', 'subjects', 'exams', 'periods'];
foreach ($modules as $module) {
    if (mobileStructureModuleKey($module) !== $module) {
        fwrite(STDERR, "FAIL: missing permission mapping for {$module}\n");
        exit(1);
    }
}
foreach (['class', 'structure', 'admin'] as $unknown) {
    if (mobileStructureModuleKey($unknown) !== null) {
        fwrite(STDERR, "FAIL: unexpected permission mapping for {$unknown}\n");
        exit(1);
    }
}

foreach ($modules as $module) {
    $head = ['panel_role' => 'head', 'admin_modules' => '', 'teacher_designation' => ''];
    $admin = ['panel_role' => 'admin', 'admin_modules' => json_encode([$module]), 'teacher_designation' => ''];
    $wrongAdmin = ['panel_role' => 'admin', 'admin_modules' => json_encode(['notices']), 'teacher_designation' => ''];
    $teacher = ['panel_role' => 'teacher', 'admin_modules' => '', 'teacher_designation' => ''];
    if (!canAccessModule($module, $head) || !canAccessModule($module, $admin)) {
        fwrite(STDERR, "FAIL: authorized role denied for {$module}\n");
        exit(1);
    }
    if (canAccessModule($module, $wrongAdmin) || canAccessModule($module, $teacher)) {
        fwrite(STDERR, "FAIL: unauthorized role granted {$module}\n");
        exit(1);
    }
}

$payload = ['label' => '  Section A  ', 'enabled' => true, 'disabled' => '0'];
if (
    mobileStructureString($payload, 'label', 20, true) !== 'Section A'
    || mobileStructureString($payload, 'missing', 20, true) !== null
    || mobileStructureString(['label' => str_repeat('x', 21)], 'label', 20) !== null
    || mobileStructureBoolean($payload, 'enabled') !== 1
    || mobileStructureBoolean($payload, 'disabled', true) !== 0
    || mobileStructureBoolean(['enabled' => 'yes'], 'enabled') !== null
    || mobileStructureNonNegativeInt('0') !== 0
    || mobileStructureNonNegativeInt('-1') !== null
    || mobileStructureNonNegativeInt('12x') !== null
) {
    fwrite(STDERR, "FAIL: structure request validation helper mismatch\n");
    exit(1);
}

echo "PASS\n";
