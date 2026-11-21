<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bmlhs/api/v1/bootstrap.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$staff = [
    'panel_role' => 'teacher',
    'admin_modules' => '[]',
    'teacher_designation' => '',
    'teacher_mobile' => '',
];
$admin = [
    'panel_role' => 'admin',
    'admin_modules' => '[]',
    'teacher_designation' => '',
    'teacher_mobile' => '',
];

$check(canAccessModule('student-profiles', $staff), 'a teacher can open the student profile list');
$check(canAccessModule('student-profile', $staff), 'a teacher can open one student profile');
$check(canAccessModule('student-profiles', $admin), 'an admin without the students module can open profiles');
$check(!canManageModule('students', $staff), 'viewing a profile does not grant student editing');

$list = file_get_contents(__DIR__ . '/../../dist/pages/student-profiles.php');
$check(is_string($list)
    && str_contains($list, 'examRoutineSectionCatalog(schoolSections($db))')
    && str_contains($list, 'id="profileSections"')
    && !str_contains($list, 'applyAssignedClassStudentScope')
    && !str_contains($list, 'data-sf-sections'), 'the profile list offers every class and section');

$sheet = file_get_contents(__DIR__ . '/../../dist/pages/student-profile.php');
$check(is_string($sheet) && !str_contains($sheet, 'panelMayViewStudentRecord'), 'one profile is not limited to the assigned class');

$sidebar = file_get_contents(__DIR__ . '/../../dist/includes/sidebar.php');
$check(is_string($sidebar)
    && str_contains($sidebar, 'if (!$detailsListStandalone): ?><li class="nav-item"><a href="<?= $basePath ?>pages/student-profiles.php"')
    && str_contains($sidebar, 'if ($showStudentDetailMenus && !$detailsListStandalone): ?><li class="nav-item"><a href="<?= $basePath ?>pages/student-details-list.php"'), 'the profile menu is open and the details list stays limited');

if ($failures > 0) {
    fwrite(STDERR, $failures . " check(s) failed\n");
    exit(1);
}

echo "PASS\n";
