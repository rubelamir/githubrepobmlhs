<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/tabulation-sheet.php';

$subjects = [
    [
        'subject_name_ban' => 'বাংলা',
        'subject_name' => 'Bangla',
        'has_mcq' => 1,
        'has_cq' => 1,
        'has_practical' => 0,
        'is_optional' => 0,
    ],
    [
        'subject_name_ban' => 'বিজ্ঞান',
        'subject_name' => 'Science',
        'has_mcq' => 0,
        'has_cq' => 0,
        'has_practical' => 1,
        'is_optional' => 0,
    ],
    [
        'subject_name_ban' => 'ইংরেজি ১ম পত্র',
        'subject_name' => 'English First Paper',
        'has_mcq' => 0,
        'has_cq' => 1,
        'has_practical' => 0,
        'is_optional' => 0,
    ],
    [
        'subject_name_ban' => 'ইংরেজি ২য় পত্র',
        'subject_name' => 'English Second Paper',
        'has_mcq' => 0,
        'has_cq' => 1,
        'has_practical' => 0,
        'is_optional' => 0,
    ],
];
$rows = [[
    'student' => ['cls_roll' => '1', 'name_ban' => 'শিক্ষার্থী'],
    'cells' => [
        ['mcq' => 10.0, 'cq' => 50.0, 'practical' => null, 'pass_marks' => 33.0],
        ['mcq' => null, 'cq' => null, 'practical' => 20.0, 'pass_marks' => 33.0],
        ['mcq' => null, 'cq' => 60.0, 'practical' => null, 'pass_marks' => 33.0],
        ['mcq' => null, 'cq' => 55.0, 'practical' => null, 'pass_marks' => 33.0],
    ],
    'result' => 'উত্তীর্ণ',
    'display_result' => 'উত্তীর্ণ',
    'total' => 80.0,
    'rank' => 1,
]];

$html = tabulationSheetTableHtml($subjects, $rows);
preg_match('/<colgroup>(.*?)<\/colgroup>/s', $html, $matches);
$columnGroup = $matches[1] ?? '';
$markColumnCount = substr_count($columnGroup, 'class="ts-mark-cell"');
if (substr_count($columnGroup, 'class="ts-mark-cell" width="7mm" style="width:7mm"') !== 5) {
    fwrite(STDERR, "FAIL: every PDF mark column should have an explicit 7mm width\n");
    exit(1);
}
preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $bodyMatches);
$markCellCount = substr_count($bodyMatches[1] ?? '', 'ts-mark-cell');
if (
    $markColumnCount !== 5
    || $markCellCount !== 5
    || substr_count($bodyMatches[1] ?? '', 'width="7mm" style="width:7mm;min-width:7mm;max-width:7mm"') !== 5
) {
    fwrite(STDERR, "FAIL: mark columns and mark cells should have matching fixed-width columns\n");
    exit(1);
}
if (substr_count($html, '<th class="ts-mark-cell" width="7mm" style="width:7mm;min-width:7mm;max-width:7mm">CQ</th>') !== 3) {
    fwrite(STDERR, "FAIL: single-component CQ subjects must each have one CQ header cell\n");
    exit(1);
}
if (substr_count($html, 'class="ts-subject-label"') !== count($subjects)) {
    fwrite(STDERR, "FAIL: every subject heading must use the constrained label wrapper\n");
    exit(1);
}
if (substr_count($html, 'width="7mm" style="width:7mm;max-width:7mm;white-space:normal;word-wrap:break-word"') !== 3) {
    fwrite(STDERR, "FAIL: CQ-only subject headings should be constrained to one mark cell\n");
    exit(1);
}
foreach (['ts-roll-cell', 'ts-name-cell', 'ts-total-cell', 'ts-result-cell', 'ts-rank-cell'] as $class) {
    if (!str_contains($columnGroup, 'class="' . $class . '"')) {
        fwrite(STDERR, "FAIL: missing fixed column {$class}\n");
        exit(1);
    }
}

echo "PASS\n";
