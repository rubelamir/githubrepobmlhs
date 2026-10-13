<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';
if (!function_exists('schoolPlainAddress')) {
    require_once dirname(__DIR__) . '/bmlhs/includes/site.php';
}

/**
 * @param list<array<string, mixed>> $students
 * @param array<string, mixed> $markMap
 * @param array<string, mixed> $subject
 * @param array<string, mixed> $settings
 */
function outputResultsPdf(
    array $students,
    array $markMap,
    array $subject,
    array $settings,
    string $examName,
    string $className,
    string $sectionName,
    string $sessionYear,
    string $filename
): void {
    if ($students === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো শিক্ষার্থী নেই।');
    }

    $mpdf = createBanglaMpdf(
        trim((string) ($settings['school_name_bn'] ?? '')) . ' - মার্কশিট',
        trim((string) ($settings['school_name_bn'] ?? 'School Admin'))
    );
    $mpdf->SetMargins(10, 10, 12);
    $mpdf->SetAutoPageBreak(true, 10);

    $mpdf->WriteHTML(resultsPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML(
        resultsPdfHtml($students, $markMap, $subject, $settings, $examName, $className, $sectionName, $sessionYear),
        \Mpdf\HTMLParserMode::HTML_BODY
    );

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'results.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

function resultsPdfCss(): string
{
    return <<<'CSS'
body { font-family: hindsiliguri, sans-serif; color: #111; font-size: 10pt; }
.results-head { width: 100%; border-collapse: collapse; border-bottom: .35mm solid #444; }
.results-head td { vertical-align: middle; padding: 0 0 2mm; }
.results-logo-cell { width: 25mm; }
.results-logo { width: 22mm; height: 22mm; }
.results-brand { text-align: center; }
.results-school { font-size: 19pt; font-weight: bold; }
.results-meta { font-size: 9pt; }
.results-title { margin: 3mm 0; text-align: center; }
.results-title strong { font-size: 13pt; }
.results-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
.results-table th { background-color: #e8efe9; font-weight: bold; }
.results-table th, .results-table td { border: .25mm solid #555; padding: 1.5mm 1mm; vertical-align: middle; }
.results-table th.center, .results-table td.center { text-align: center; }
.results-table tr.alt td { background-color: #f7faf8; }
CSS;
}

/**
 * @param list<array<string, mixed>> $students
 * @param array<string, mixed> $markMap
 * @param array<string, mixed> $subject
 * @param array<string, mixed> $settings
 */
function resultsPdfHtml(
    array $students,
    array $markMap,
    array $subject,
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
    $subjectName = trim((string) ($subject['subject_name_ban'] ?: $subject['subject_name']));
    $meta = '';
    if ($eiin !== '') {
        $meta .= 'EIIN: ' . h($eiin);
    }
    if ($eiin !== '' && $mobile !== '') {
        $meta .= ' · ';
    }
    if ($mobile !== '') {
        $meta .= 'মোবাইল: ' . h($mobile);
    }

    $html = '<table class="results-head"><tr><td class="results-logo-cell">'
        . ($logo !== '' ? '<img class="results-logo" src="' . h($logo) . '" alt="">' : '')
        . '</td><td class="results-brand">'
        . '<div class="results-school">' . h($schoolName) . '</div>'
        . ($address !== '' ? '<div class="results-meta">' . h($address) . '</div>' : '')
        . ($meta !== '' ? '<div class="results-meta">' . $meta . '</div>' : '')
        . '</td><td class="results-logo-cell"></td></tr></table>';
    $classLine = $className . ($sectionName !== '' ? ' · ' . $sectionName : '');
    $html .= '<div class="results-title"><strong>মার্কশিট</strong><br>'
        . h($examName) . ' · ' . h($classLine) . ' · শিক্ষাবর্ষ ' . h(bnDigits($sessionYear))
        . '<br>' . h($subjectName) . ' · পূর্ণমান ' . h((string) ($subject['full_marks'] ?? 100))
        . ((int) ($subject['is_optional'] ?? 0) === 1 ? ' · ঐচ্ছিক' : '')
        . '</div>';

    $hasMcq = (int) ($subject['has_mcq'] ?? 0) === 1;
    $hasCq = (int) ($subject['has_cq'] ?? 0) === 1;
    $hasPractical = (int) ($subject['has_practical'] ?? 0) === 1;
    $html .= '<table class="results-table" repeat_header="1"><thead><tr>'
        . '<th class="center" style="width:10%">রোল</th><th>শিক্ষার্থীর নাম</th>'
        . ($hasMcq ? '<th class="center" style="width:12%">MCQ</th>' : '')
        . ($hasCq ? '<th class="center" style="width:14%">CQ / লিখিত</th>' : '')
        . ($hasPractical ? '<th class="center" style="width:14%">ব্যবহারিক</th>' : '')
        . '<th class="center" style="width:12%">মোট</th><th class="center" style="width:12%">গ্রেড</th>'
        . '</tr></thead><tbody>';

    foreach (array_values($students) as $index => $student) {
        $id = (int) $student['id'];
        $mark = $markMap['id:' . $id] ?? $markMap['roll:' . (string) $student['cls_roll']] ?? [];
        $mcq = formatMarkValue($mark['mcq'] ?? '');
        $cq = formatMarkValue($mark['cq'] ?? '');
        $practical = formatMarkValue($mark['practical'] ?? '');
        $hasMarks = $mcq !== '' || $cq !== '' || $practical !== '';
        $total = (float) $mcq + (float) $cq + (float) $practical;
        $full = (float) ($subject['full_marks'] ?? 100);
        $html .= '<tr' . ($index % 2 === 1 ? ' class="alt"' : '') . '>'
            . '<td class="center">' . h((string) $student['cls_roll']) . '</td>'
            . '<td>' . h((string) $student['name_ban']) . '</td>'
            . ($hasMcq ? '<td class="center">' . h($mcq !== '' ? $mcq : '—') . '</td>' : '')
            . ($hasCq ? '<td class="center">' . h($cq !== '' ? $cq : '—') . '</td>' : '')
            . ($hasPractical ? '<td class="center">' . h($practical !== '' ? $practical : '—') . '</td>' : '')
            . '<td class="center">' . ($hasMarks ? h((string) (int) round($total)) : '—') . '</td>'
            . '<td class="center">' . ($hasMarks ? h(letterGrade($total, $full > 0 ? $full : 100)) : '—') . '</td>'
            . '</tr>';
    }

    return $html . '</tbody></table>';
}
