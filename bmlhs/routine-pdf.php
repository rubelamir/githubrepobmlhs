<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';
require_once dirname(__DIR__) . '/shared/mpdf-bangla.php';

$db = publicDatabase();
$school = currentSchool();
$routineSettings = routineSettings($db);
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$classes = schoolClasses($db, true);
$classExists = false;
foreach ($classes as $class) {
    if ((int) $class['id'] === $classId) {
        $classExists = true;
        break;
    }
}

if ((int) ($routineSettings['is_published'] ?? 1) !== 1 || !$classExists || $classId < 1) {
    http_response_code(404);
    exit('প্রকাশিত শ্রেণী রুটিন পাওয়া যায়নি।');
}

$sections = schoolSections($db, $classId, true);
if ($sectionId > 0) {
    $sectionExists = false;
    foreach ($sections as $section) {
        if ((int) $section['id'] === $sectionId) {
            $sectionExists = true;
            break;
        }
    }
    if (!$sectionExists) {
        http_response_code(404);
        exit('নির্বাচিত শ্রেণী/শাখার রুটিন পাওয়া যায়নি।');
    }
}

$grid = classRoutineGrid($db, $classId, $sectionId);
$hasRoutine = false;
foreach ($grid as $daySlots) {
    foreach ($daySlots as $slotRows) {
        if ($slotRows !== []) {
            $hasRoutine = true;
            break 2;
        }
    }
}
if (!$hasRoutine) {
    http_response_code(404);
    exit('এই শ্রেণী/শাখার রুটিন এখনো প্রস্তুত হয়নি।');
}

$periodMeta = schoolPeriodMeta($db);
$days = weekDays();
$teacherMap = teacherNameMap($db);
$today = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('w');
$className = classLabel($db, $classId);
$sectionName = $sectionId > 0 ? sectionLabel($db, $sectionId) : '';
$logoPath = mpdfSchoolLogoPath($school);
$logoHtml = $logoPath !== ''
    ? '<img class="routine-logo" src="' . h(str_replace('\\', '/', $logoPath)) . '" alt="">'
    : '';

$html = '<div class="routine-sheet">'
    . '<table class="routine-head"><tr>'
    . '<td class="routine-logo-cell">' . $logoHtml . '</td>'
    . '<td><h1>' . h((string) $school['school_name_bn']) . '</h1>'
    . '<p>' . h((string) $school['address']) . '</p>'
    . '<h2>শ্রেণী রুটিন — ' . h($className) . ($sectionName !== '' ? ' ' . h($sectionName) : '') . '</h2>'
    . (!empty($routineSettings['session_label']) ? '<p>সেশন ' . h((string) $routineSettings['session_label']) . '</p>' : '')
    . '</td></tr></table>';

$bells = [];
if (trim((string) ($routineSettings['assembly_time'] ?? '')) !== '') {
    $bells[] = trim((string) $routineSettings['assembly_label']) . ': ' . trim((string) $routineSettings['assembly_time']);
}
if (trim((string) ($routineSettings['break_time'] ?? '')) !== '') {
    $bells[] = trim((string) $routineSettings['break_label']) . ': ' . trim((string) $routineSettings['break_time']);
}
$bells[] = isset($days[$today]) ? 'আজ ' . weekDayBangla($today) : 'আজ সাপ্তাহিক ছুটি';
$html .= '<div class="routine-bells">' . implode(' <span>·</span> ', array_map('h', $bells)) . '</div>';
$html .= '<table class="routine-table"><thead><tr><th class="day-col">বার</th>';
foreach ($periodMeta as $meta) {
    $html .= '<th><strong>' . h((string) $meta['label']) . '</strong><br><span class="period-time">'
        . h((string) $meta['time']) . '</span></th>';
}
$html .= '</tr></thead><tbody>';
foreach ($days as $dayNo => $dayLabel) {
    $html .= '<tr' . ((int) $dayNo === $today ? ' class="today"' : '') . '><th>' . h(weekDayBangla((int) $dayNo)) . '</th>';
    foreach ($periodMeta as $periodNo => $meta) {
        $slotRows = $grid[$dayNo][$periodNo] ?? [];
        if ($slotRows === []) {
            $html .= '<td><div class="routine-cell empty">—</div></td>';
            continue;
        }
        $html .= '<td>';
        foreach ($slotRows as $slot) {
            $subject = trim((string) ($slot['subject_name'] ?? ''));
            $tone = routineSubjectTone($subject);
            $teacher = teacherDisplayName($teacherMap, (string) ($slot['teacher_mobile'] ?? ''));
            $room = trim((string) ($slot['room'] ?? ''));
            $html .= '<div class="routine-cell" style="background-color:' . h($tone['bg']) . ';color:' . h($tone['ink']) . '">'
                . '<strong>' . h($subject) . '</strong>'
                . ($teacher !== '' ? '<br><span>' . h($teacher) . '</span>' : '')
                . ($room !== '' ? '<small>' . h($room) . '</small>' : '')
                . '</div>';
        }
        $html .= '</td>';
    }
    $html .= '</tr>';
}
$html .= '</tbody></table>';
if (trim((string) ($routineSettings['footer_note'] ?? '')) !== '') {
    $html .= '<p class="routine-note">' . h((string) $routineSettings['footer_note']) . '</p>';
}
$html .= '</div>';

$mpdf = createBanglaMpdf(
    trim((string) ($school['school_name_bn'] ?? '')) . ' - শ্রেণী রুটিন',
    trim((string) ($school['school_name_bn'] ?? 'School Admin')),
    'A4',
    'L'
);
$mpdf->SetMargins(8, 8, 8);
$mpdf->SetAutoPageBreak(false);
$mpdf->WriteHTML(<<<'CSS'
body { font-family: hindsiliguri, sans-serif; color: #1a2a24; font-size: 8pt; }
.routine-sheet { border: .3mm solid #9daea3; border-radius: 3mm; padding: 4mm; }
.routine-head { width: 100%; border-collapse: collapse; border-bottom: .5mm solid #1a4d3e; margin-bottom: 3mm; }
.routine-head td { padding: 0 0 2mm; vertical-align: middle; text-align: center; }
.routine-logo-cell { width: 25mm; }
.routine-logo { width: 18mm; height: 18mm; }
.routine-head h1 { margin: 0; font-size: 16pt; color: #0f2f26; }
.routine-head h2 { margin: 1mm 0 0; font-size: 11pt; color: #1a4d3e; }
.routine-head p { margin: 0; font-size: 8pt; color: #5a6f66; }
.routine-bells { margin: 0 0 2mm; text-align: center; font-size: 8pt; font-weight: bold; color: #9a3d2a; }
.routine-bells span { padding: 0 3mm; color: #5a6f66; }
.routine-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 7pt; }
.routine-table th, .routine-table td { width: auto; border: .25mm solid #000; padding: 1mm; vertical-align: middle; text-align: center; }
.routine-table .day-col, .routine-table tbody th { width: 16mm; }
.routine-table thead th { background-color: #0f2f26; color: #fff; font-weight: bold; }
.routine-table thead .period-time { display: block; margin-top: .5mm; font-size: 6pt; font-weight: normal; white-space: nowrap; }
.routine-table tbody th { background-color: #f3f7f5; color: #0f2f26; font-size: 8pt; font-weight: bold; }
.routine-table tbody tr.today th { background-color: #1a4d3e; color: #fff; }
.routine-cell { min-height: 15mm; padding: 1mm; border-radius: 1mm; line-height: 1.2; }
.routine-cell strong, .routine-cell span, .routine-cell small { display: block; }
.routine-cell strong { font-size: 7.5pt; }
.routine-cell span, .routine-cell small { margin-top: .5mm; font-size: 6pt; }
.routine-cell.empty { background-color: #f6f8f7; color: #9aa8a2; }
.routine-note { margin: 2mm 0 0; text-align: center; color: #5a6f66; font-size: 8pt; }
CSS, \Mpdf\HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

$filename = 'class-routine-' . $classId . ($sectionId > 0 ? '-section-' . $sectionId : '') . '.pdf';
$mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
