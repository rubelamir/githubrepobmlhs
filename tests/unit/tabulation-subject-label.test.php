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

$religionRows = buildTabulationSheetRows(
    [[
        'cls_roll' => '2',
        'name_ban' => 'Hindu student',
        'sections' => 1,
        'religion' => 'Hindu',
        'subject_choices' => ['কৃষি-গার্হস্থ্য' => 21],
    ]],
    [
        [
            'subject_name_ban' => 'বাংলা',
            'subject_name' => 'Bangla',
            'source_ids' => [1 => 10],
            'optional_by_section' => [1 => 1],
            'has_mcq' => 0,
            'has_cq' => 1,
            'has_practical' => 0,
            'pass_marks' => 33,
            'is_optional' => 1,
        ],
        [
            'subject_name_ban' => 'ইসলাম শিক্ষা',
            'subject_name' => 'Islam',
            'source_ids' => [1 => 20],
            'audience_by_section' => [1 => 'Islam'],
            'optional_by_section' => [1 => 0],
            'has_mcq' => 0,
            'has_cq' => 1,
            'has_practical' => 0,
            'pass_marks' => 33,
            'is_optional' => 0,
        ],
        [
            'subject_name_ban' => 'গার্হস্থ্য বিজ্ঞান',
            'subject_name' => 'Home science',
            'source_ids' => [1 => 22],
            'choice_group_by_section' => [1 => 'কৃষি-গার্হস্থ্য'],
            'optional_by_section' => [1 => 0],
            'has_mcq' => 0,
            'has_cq' => 1,
            'has_practical' => 0,
            'pass_marks' => 33,
            'is_optional' => 0,
        ],
    ],
    [
        '2' => [
            1 => [
                10 => ['mcq' => null, 'cq' => 80, 'practical' => null],
            ],
        ],
    ]
);
if (($religionRows[0]['cells'][1]['applicable'] ?? true) !== false || ($religionRows[0]['cells'][2]['applicable'] ?? true) !== false) {
    fwrite(STDERR, "FAIL: religion and unchosen elective should not apply\n");
    exit(1);
}
if (($religionRows[0]['result'] ?? '') !== 'উত্তীর্ণ') {
    fwrite(STDERR, "FAIL: a mark in Bangla should pass when the other papers do not apply\n");
    exit(1);
}

echo "PASS\n";
