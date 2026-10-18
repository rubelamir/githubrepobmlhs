<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';
if (!function_exists('schoolPlainAddress')) {
    require_once dirname(__DIR__) . '/bmlhs/includes/site.php';
}

function tabulationSubjectDisplayName(string $subjectName): string
{
    $normalized = trim($subjectName);
    if ($normalized === '') {
        return $subjectName;
    }

    $map = [
        'পৌরনীতি ও নাগরিকতা' => 'Civics',
        'পৌরনীতি ও নাগরিকতা ' => 'Civics',
        'তথ্য ও যোগাযোগ প্রযুক্তি' => 'ICT',
        'বাংলাদেশ ও বিশ্বপরিচয়' => 'Ban. & Global',
        'বাংলাদেশ ও বিশ্ব পরিচয়' => 'Ban. & Global',
        'বাংলাদেশ ও বিশ্বপরিচয়' => 'Ban. & Global',
        'বাংলাদেশ ও বিশ্ব পরিচয়' => 'Ban. & Global',
    ];

    $direct = $map[$normalized] ?? null;
    if ($direct !== null) {
        return $direct;
    }

    foreach ($map as $bangla => $display) {
        if (strtolower($normalized) === strtolower($bangla)) {
            return $display;
        }
    }

    return $subjectName;
}

/**
 * @param list<array<string, mixed>> $students
 * @param list<array<string, mixed>> $subjects
 * @param array<string, array<int, array<int, array<string, mixed>>>> $marks
 * @return list<array<string, mixed>>
 */
function buildTabulationSheetRows(array $students, array $subjects, array $marks): array
{
    $rows = [];
    foreach ($students as $student) {
        $roll = (string) ($student['cls_roll'] ?? '');
        $sectionId = (int) ($student['sections'] ?? 0);
        $cells = [];
        $total = 0.0;
        $complete = true;
        $failed = false;
        $hasAnyMarks = false;

        foreach ($subjects as $subject) {
            $sourceIds = $subject['source_ids'] ?? [];
            $sourceId = $sourceIds[$sectionId] ?? $sourceIds[0] ?? 0;
            $subjectId = (int) $sourceId;
            $applicable = $subjectId > 0;
            $subjectSourceSectionId = isset($sourceIds[$sectionId]) ? $sectionId : 0;
            $optional = (int) ($subject['optional_by_section'][$subjectSourceSectionId] ?? $subject['is_optional'] ?? 0) === 1;
            $rules = function_exists('subjectComponentRules') ? subjectComponentRules($subject) : ['mcq' => null, 'cq' => null, 'practical' => null];
            $passMcq = is_array($rules['mcq'] ?? null) ? (float) $rules['mcq']['pass'] : null;
            $passCq = is_array($rules['cq'] ?? null) ? (float) $rules['cq']['pass'] : null;
            $passPractical = is_array($rules['practical'] ?? null) ? (float) $rules['practical']['pass'] : null;
            $mark = $subjectId > 0
                ? ($marks[$roll][$sectionId][$subjectId] ?? $marks[$roll][0][$subjectId] ?? null)
                : null;
            $hasMcq = (int) ($subject['has_mcq'] ?? 0) === 1;
            $hasCq = (int) ($subject['has_cq'] ?? 0) === 1;
            $hasPractical = (int) ($subject['has_practical'] ?? 0) === 1;
            $mcq = $hasMcq && is_array($mark) && $mark['mcq'] !== null ? (float) $mark['mcq'] : null;
            $cq = $hasCq && is_array($mark) && $mark['cq'] !== null ? (float) $mark['cq'] : null;
            $practical = $hasPractical && is_array($mark) && $mark['practical'] !== null
                ? (float) $mark['practical']
                : null;
            $subjectHasMarks = $applicable && is_array($mark) && ($mcq !== null || $cq !== null || $practical !== null);
            $subjectTotal = $subjectHasMarks
                ? (float) ($mcq ?? 0) + (float) ($cq ?? 0) + (float) ($practical ?? 0)
                : null;

            if ($subjectHasMarks) {
                $hasAnyMarks = true;
                $total += (float) $subjectTotal;
            } elseif ($applicable && !$optional) {
                $complete = false;
            }
            if ($subjectHasMarks && $applicable && !$optional) {
                $grade = subjectPaperGrade($subject, $mcq, $cq, $practical);
                if ($grade === 'F') {
                    $failed = true;
                }
            }

            $cells[] = [
                'mcq' => $mcq,
                'cq' => $cq,
                'practical' => $practical,
                'total' => $subjectTotal,
                'applicable' => $applicable,
                'pass_mcq' => $passMcq,
                'pass_cq' => $passCq,
                'pass_practical' => $passPractical,
            ];
        }

        $result = !$complete || !$hasAnyMarks ? 'অসম্পূর্ণ' : ($failed ? 'অকৃতকার্য' : 'উত্তীর্ণ');
        $displayResult = $result === 'অসম্পূর্ণ' ? '' : $result;
        $rows[] = [
            'student' => $student,
            'cells' => $cells,
            'total' => $total,
            'result' => $result,
            'display_result' => $displayResult,
            'rank' => null,
            'rankable' => $result !== 'অসম্পূর্ণ',
        ];
    }

    $rankIndexes = [];
    foreach ($rows as $index => $row) {
        if ($row['rankable']) {
            $rankIndexes[] = $index;
        }
    }
    usort(
        $rankIndexes,
        static function (int $left, int $right) use ($rows): int {
            $totalOrder = (float) $rows[$right]['total'] <=> (float) $rows[$left]['total'];
            if ($totalOrder !== 0) {
                return $totalOrder;
            }

            $leftRoll = (string) ($rows[$left]['student']['cls_roll'] ?? '');
            $rightRoll = (string) ($rows[$right]['student']['cls_roll'] ?? '');
            if (ctype_digit($leftRoll) && ctype_digit($rightRoll)) {
                return (int) $leftRoll <=> (int) $rightRoll;
            }

            return strnatcasecmp($leftRoll, $rightRoll);
        }
    );

    $rank = 1;
    foreach ($rankIndexes as $position => $rowIndex) {
        $rows[$rowIndex]['rank'] = $rank;
        $nextIndex = $rankIndexes[$position + 1] ?? null;
        if ($nextIndex !== null && abs((float) $rows[$nextIndex]['total'] - (float) $rows[$rowIndex]['total']) >= 0.005) {
            $rank = $position + 2;
        }
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $subjects
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $settings
 */
function outputTabulationSheetPdf(
    array $subjects,
    array $rows,
    array $settings,
    string $examName,
    string $className,
    string $sectionName,
    string $sessionYear,
    string $filename
): void {
    if ($rows === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।');
    }

    $mpdf = createBanglaMpdf(
        trim((string) ($settings['school_name_bn'] ?? '')) . ' - টেবুলেশন শীট',
        trim((string) ($settings['school_name_bn'] ?? 'School Admin')),
        'Legal',
        'L'
    );
    $mpdf->SetMargins(8, 8, 10);
    $mpdf->SetAutoPageBreak(true, 8);
    $mpdf->WriteHTML(tabulationSheetPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML(
        tabulationSheetPdfHtml($subjects, $rows, $settings, $examName, $className, $sectionName, $sessionYear),
        \Mpdf\HTMLParserMode::HTML_BODY
    );
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'tabulation-sheet.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

function tabulationSheetPdfCss(): string
{
    return <<<'CSS'
body { font-family: hindsiliguri, sans-serif; color: #16231e; font-size: 7pt; }
.ts-head { width: 100%; border-collapse: collapse; border-bottom: .35mm solid #315849; margin-bottom: 2mm; }
.ts-head td { vertical-align: middle; padding: 0 0 1.5mm; }
.ts-logo-cell { width: 20mm; }
.ts-logo { width: 17mm; height: 17mm; }
.ts-brand { text-align: center; }
.ts-school { font-size: 16pt; font-weight: bold; color: #174936; }
.ts-meta { font-size: 8pt; color: #374b43; }
.ts-title { text-align: center; margin: 1.5mm 0 2.5mm; font-size: 9pt; }
.ts-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8.2pt; }
.ts-table th, .ts-table td { border: .2mm solid #687b72; padding: 1mm .55mm; vertical-align: middle; }
.ts-table thead th { background-color: #e5ede9; color: #173b2d; font-weight: bold; text-align: center; }
.ts-table col.ts-mark-cell { width: 7mm; }
.ts-table .ts-subject-label { display: block; width: 14mm; max-width: 100%; margin: 0 auto; white-space: normal; word-wrap: break-word; }
.ts-table .ts-mark-cell { box-sizing: border-box; width: 7mm; min-width: 7mm; max-width: 7mm; white-space: nowrap; }
.ts-table .ts-roll-cell { box-sizing: border-box; width: 10mm; min-width: 10mm; max-width: 10mm; white-space: nowrap; font-weight: bold; }
.ts-table .ts-name-cell { box-sizing: border-box; width: 30mm; min-width: 30mm; max-width: 30mm; white-space: normal; word-wrap: break-word; }
.ts-table .ts-total-cell, .ts-table .ts-rank-cell { box-sizing: border-box; width: 9mm; min-width: 9mm; max-width: 9mm; white-space: nowrap; }
.ts-table .ts-result-cell { box-sizing: border-box; width: 17mm; min-width: 17mm; max-width: 17mm; white-space: nowrap; font-weight: bold; }
.ts-table tbody tr.alt td { background-color: #f3f7f5; }
.ts-table td.center { text-align: center; }
.ts-pass { color: #12613d; font-weight: bold; }
.ts-fail { color: #a12727; font-weight: bold; }
.ts-pending { color: #725314; font-weight: bold; }
.ts-below-pass { color: #c62828; font-weight: bold; }
CSS;
}

/**
 * @param list<array<string, mixed>> $subjects
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $settings
 */
function tabulationSheetPdfHtml(
    array $subjects,
    array $rows,
    array $settings,
    string $examName,
    string $className,
    string $sectionName,
    string $sessionYear
): string {
    $logoPath = mpdfSchoolLogoPath($settings);
    $logo = $logoPath !== '' && is_file($logoPath) ? str_replace('\\', '/', $logoPath) : '';
    $schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
    $address = schoolPlainAddress((string) ($settings['address'] ?? ''));
    $eiin = trim((string) ($settings['eiin'] ?? ''));
    $mobile = publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''));
    $meta = $eiin !== '' ? 'EIIN: ' . h($eiin) : '';
    if ($eiin !== '' && $mobile !== '') {
        $meta .= ' · ';
    }
    if ($mobile !== '') {
        $meta .= 'মোবাইল: ' . h($mobile);
    }
    $html = '<table class="ts-head"><tr><td class="ts-logo-cell">'
        . ($logo !== '' ? '<img class="ts-logo" src="' . h($logo) . '" alt="">' : '')
        . '</td><td class="ts-brand"><div class="ts-school">' . h($schoolName) . '</div>'
        . ($address !== '' ? '<div class="ts-meta">' . h($address) . '</div>' : '')
        . ($meta !== '' ? '<div class="ts-meta">' . $meta . '</div>' : '')
        . '</td><td class="ts-logo-cell"></td></tr></table>';
    $sectionLabel = $sectionName !== '' ? ' · ' . $sectionName : '';
    $html .= '<div class="ts-title"><strong>পরীক্ষার টেবুলেশন শীট</strong><br>'
        . h($examName) . ' · ' . h($className) . h($sectionLabel)
        . ' · শিক্ষাবর্ষ ' . h(bnDigits($sessionYear)) . '</div>';
    $html .= tabulationSheetTableHtml($subjects, $rows);

    return $html;
}

/**
 * @param list<array<string, mixed>> $subjects
 * @param list<array<string, mixed>> $rows
 */
function tabulationSheetTableHtml(array $subjects, array $rows): string
{
    $markCellAttributes = ' width="7mm" style="width:7mm;min-width:7mm;max-width:7mm"';
    $html = '<table class="ts-table" repeat_header="1"><colgroup>'
        . '<col class="ts-roll-cell" width="10mm" style="width:10mm">'
        . '<col class="ts-name-cell" width="30mm" style="width:30mm">';
    foreach ($subjects as $subject) {
        if ((int) ($subject['has_mcq'] ?? 0) === 1) {
            $html .= '<col class="ts-mark-cell" width="7mm" style="width:7mm">';
        }
        if ((int) ($subject['has_cq'] ?? 0) === 1) {
            $html .= '<col class="ts-mark-cell" width="7mm" style="width:7mm">';
        }
        if ((int) ($subject['has_practical'] ?? 0) === 1) {
            $html .= '<col class="ts-mark-cell" width="7mm" style="width:7mm">';
        }
        if (tabulationSubjectColumnCount($subject) === 1
            && (int) ($subject['has_mcq'] ?? 0) + (int) ($subject['has_cq'] ?? 0) + (int) ($subject['has_practical'] ?? 0) === 0
        ) {
            $html .= '<col class="ts-mark-cell" width="7mm" style="width:7mm">';
        }
    }
    $html .= '<col class="ts-total-cell" width="9mm" style="width:9mm">'
        . '<col class="ts-result-cell" width="17mm" style="width:17mm">'
        . '<col class="ts-rank-cell" width="9mm" style="width:9mm">'
        . '</colgroup><thead><tr>'
        . '<th class="ts-roll-cell" rowspan="2">রোল</th><th class="ts-name-cell" rowspan="2">শিক্ষার্থীর নাম</th>';
    foreach ($subjects as $subject) {
        $subjectLabel = tabulationSubjectDisplayName((string) ($subject['subject_name_ban'] ?: $subject['subject_name']));
        $subjectWidth = 7 * tabulationSubjectColumnCount($subject);
        $html .= '<th colspan="' . tabulationSubjectColumnCount($subject) . '" width="' . $subjectWidth
            . 'mm" style="width:' . $subjectWidth . 'mm;max-width:' . $subjectWidth
            . 'mm;white-space:normal;word-wrap:break-word">'
            . '<span class="ts-subject-label" style="display:block;width:' . $subjectWidth
            . 'mm;max-width:' . $subjectWidth . 'mm;white-space:normal;word-wrap:break-word">'
            . h($subjectLabel)
            . ((int) ($subject['is_optional'] ?? 0) === 1 ? ' *' : '')
            . '</span></th>';
    }
    $html .= '<th class="ts-total-cell" rowspan="2">মোট</th><th class="ts-result-cell" rowspan="2">ফলাফল</th><th class="ts-rank-cell" rowspan="2">মেধাক্রম</th></tr><tr>';
    foreach ($subjects as $subject) {
        if ((int) ($subject['has_mcq'] ?? 0) === 1) {
            $html .= '<th class="ts-mark-cell"' . $markCellAttributes . '>MCQ</th>';
        }
        if ((int) ($subject['has_cq'] ?? 0) === 1) {
            $html .= '<th class="ts-mark-cell"' . $markCellAttributes . '>CQ</th>';
        }
        if ((int) ($subject['has_practical'] ?? 0) === 1) {
            $html .= '<th class="ts-mark-cell"' . $markCellAttributes . '>Pr</th>';
        }
        if (tabulationSubjectColumnCount($subject) === 1
            && (int) ($subject['has_mcq'] ?? 0) + (int) ($subject['has_cq'] ?? 0) + (int) ($subject['has_practical'] ?? 0) === 0
        ) {
            $html .= '<th class="ts-mark-cell"' . $markCellAttributes . '>—</th>';
        }
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $index => $row) {
        $student = $row['student'];
        $html .= '<tr' . ($index % 2 === 1 ? ' class="alt"' : '') . '>'
            . '<td class="center ts-roll-cell">' . h((string) ($student['cls_roll'] ?? '')) . '</td>'
            . '<td class="ts-name-cell">' . h((string) ($student['name_ban'] ?? $student['name_eng'] ?? '')) . '</td>';
        foreach ($subjects as $subjectIndex => $subject) {
            $cell = $row['cells'][$subjectIndex] ?? [];
            if ((int) ($subject['has_mcq'] ?? 0) === 1) {
                $html .= tabulationPdfMarkCell($cell['mcq'] ?? null, isset($cell['pass_mcq']) ? (float) $cell['pass_mcq'] : null);
            }
            if ((int) ($subject['has_cq'] ?? 0) === 1) {
                $html .= tabulationPdfMarkCell($cell['cq'] ?? null, isset($cell['pass_cq']) ? (float) $cell['pass_cq'] : null);
            }
            if ((int) ($subject['has_practical'] ?? 0) === 1) {
                $html .= tabulationPdfMarkCell($cell['practical'] ?? null, isset($cell['pass_practical']) ? (float) $cell['pass_practical'] : null);
            }
            if (tabulationSubjectColumnCount($subject) === 1
                && (int) ($subject['has_mcq'] ?? 0) + (int) ($subject['has_cq'] ?? 0) + (int) ($subject['has_practical'] ?? 0) === 0
            ) {
                $html .= '<td class="center ts-mark-cell"' . $markCellAttributes . '>—</td>';
            }
        }
        $resultCode = (string) ($row['result'] ?? '');
        $resultClass = $resultCode === 'উত্তীর্ণ' ? 'ts-pass' : ($resultCode === 'অকৃতকার্য' ? 'ts-fail' : 'ts-pending');
        $resultText = (string) ($row['display_result'] ?? $resultCode);
        $resultHtml = $resultText !== '' ? h($resultText) : '—';
        $html .= '<td class="center ts-total-cell"><strong>' . h(formatMarkValue(number_format((float) $row['total'], 2, '.', ''))) . '</strong></td>'
            . '<td class="center ts-result-cell ' . $resultClass . '">' . $resultHtml . '</td>'
            . '<td class="center ts-rank-cell">' . ($row['rank'] !== null ? h(bnDigits((string) $row['rank'])) : '—') . '</td></tr>';
    }
    $html .= '</tbody></table><div class="ts-meta" style="margin-top:2mm">* চতুর্থ বিষয়। আবশ্যিক বিষয়ে যেকোনো অংশে (CQ, MCQ বা Practical) ফেল করলে অকৃতকার্য। চতুর্থ বিষয়ের ফেল সামগ্রিক ফেল নয়। অসম্পূর্ণ নম্বরের শিক্ষার্থীরা মেধাক্রমে অন্তর্ভুক্ত নয়।</div>';

    return $html;
}

function tabulationPdfMarkCell(?float $mark, ?float $passMarks): string
{
    $class = $mark !== null && $passMarks !== null && $mark < $passMarks ? 'center ts-below-pass' : 'center';

    return '<td class="' . $class . ' ts-mark-cell" width="7mm" style="width:7mm;min-width:7mm;max-width:7mm">'
        . tabulationMarkValue($mark) . '</td>';
}

function tabulationSubjectColumnCount(array $subject): int
{
    return max(
        1,
        (int) ($subject['has_mcq'] ?? 0)
            + (int) ($subject['has_cq'] ?? 0)
            + (int) ($subject['has_practical'] ?? 0)
    );
}

function tabulationMarkValue(?float $mark): string
{
    return $mark === null ? '—' : h(formatMarkValue(number_format($mark, 2, '.', '')));
}
