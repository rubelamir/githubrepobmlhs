<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/tabulation-sheet.php';

$map = [
    'পৌরনীতি ও নাগরিকতা' => 'Civics',
    'তথ্য ও যোগাযোগ প্রযুক্তি' => 'ICT',
    'বাংলাদেশ ও বিশ্বপরিচয়' => 'Ban. & Global',
];

foreach ($map as $bangla => $expected) {
    $actual = tabulationSubjectDisplayName($bangla);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$bangla} => {$actual} (expected {$expected})\n");
        exit(1);
    }
}

$rows = buildTabulationSheetRows(
    [[ 'cls_roll' => '1', 'name_ban' => 'Student', 'sections' => 1 ]],
    [[
        'subject_name_ban' => 'বাংলা',
        'subject_name' => 'Bangla',
        'section_id' => 1,
        'source_ids' => [1 => 10],
        'optional_by_section' => [1 => 0],
        'pass_marks_by_section' => [1 => 33],
        'is_optional' => 0,
        'has_mcq' => 0,
        'has_cq' => 1,
        'has_practical' => 0,
        'pass_marks' => 33,
    ]],
    []
);

if (!isset($rows[0]['result']) || $rows[0]['result'] !== 'অসম্পূর্ণ') {
    fwrite(STDERR, "FAIL: incomplete result should remain internal status as অসম্পূর্ণ\n");
    exit(1);
}

if (($rows[0]['display_result'] ?? 'NOT_SET') !== '') {
    fwrite(STDERR, "FAIL: incomplete result should be hidden from display\n");
    exit(1);
}

echo "PASS\n";
