<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/academics.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$catalog = examRoutineSectionCatalog([
    ['id' => 4, 'class_id' => 10, 'section_name_ban' => 'ক', 'section_name' => 'A'],
    ['id' => 5, 'class_id' => 10, 'section_name_ban' => '', 'section_name' => 'খ'],
    ['id' => 8, 'class_id' => 11, 'section_name_ban' => 'বিজ্ঞান', 'section_name' => 'Science'],
    ['id' => 0, 'class_id' => 11, 'section_name_ban' => 'বাদ', 'section_name' => 'Skip'],
    ['id' => 9, 'class_id' => 0, 'section_name_ban' => 'বাদ', 'section_name' => 'Skip'],
]);

$check(array_keys($catalog) === [10, 11], 'catalog keeps every class that has a section');
$check($catalog[10] === [
    ['id' => 4, 'name' => 'ক'],
    ['id' => 5, 'name' => 'খ'],
], 'a class lists each section, using the English name when Bangla is blank');
$check($catalog[11] === [
    ['id' => 8, 'name' => 'বিজ্ঞান'],
], 'rows without a class or section id are left out');

$page = file_get_contents(__DIR__ . '/../../dist/pages/exam-routine.php');
$check(is_string($page)
    && str_contains($page, 'examRoutineSectionCatalog(schoolSections($db))')
    && str_contains($page, 'id="examRoutineSections"')
    && !str_contains($page, 'academic-api.php?type=sections'), 'the exam routine page lists every section itself');

if ($failures > 0) {
    fwrite(STDERR, $failures . " check(s) failed\n");
    exit(1);
}

echo "PASS\n";
