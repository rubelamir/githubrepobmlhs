<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';
require_once dirname(__DIR__) . '/shared/mpdf-bangla.php';

$db = publicDatabase();
$school = currentSchool();
$examId = (int) ($_GET['exam'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? ''));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);
$exams = schoolPublishedExams($db);
$examName = '';
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $examId) {
        $examName = (string) ($exam['exam_name_bangla'] ?? '');
        break;
    }
}

if ($examName === '' || $sessionYear === '' || $classId < 1 || $studentId < 1) {
    http_response_code(404);
    exit('প্রকাশিত ফলাফলের মার্কশিট পাওয়া যায়নি।');
}

$summaries = classExamResultSummaries($db, $classId, $sectionId, $sessionYear, $examId);
$selected = null;
$subjectHighestMarks = [];
foreach ($summaries as $row) {
    if ((int) $row['student']['id'] === $studentId) {
        $selected = $row;
    }
    foreach ($row['sheet']['rows'] as $paper) {
        $subjectId = (int) $paper['subject']['id'];
        $subjectHighestMarks[$subjectId] = max(
            (float) ($subjectHighestMarks[$subjectId] ?? 0),
            (float) $paper['total']
        );
    }
}

if ($selected === null) {
    http_response_code(404);
    exit('প্রকাশিত ফলাফলের মার্কশিট পাওয়া যায়নি।');
}

$student = $selected['student'];
$sheet = $selected['sheet'];
$stuSection = (int) ($student['sections'] ?? 0);
$failedSubjects = array_values(array_filter(
    $sheet['rows'],
    static fn (array $paper): bool => (int) ($paper['subject']['is_optional'] ?? 0) !== 1 && (string) $paper['grade'] === 'F'
));
$marksheetLogo = str_replace('\\', '/', mpdfSchoolLogoPath($school));
$photo = mpdfStudentPhotoPath(isset($student['student_pic']) ? (string) $student['student_pic'] : null);
$marksheetPdf = true;
$marksheetUrl = publicAbsoluteUrl('result?' . http_build_query([
    'exam' => $examId,
    'session_year' => $sessionYear,
    'cls' => $classId,
    'sections' => $sectionId,
    'student_id' => $studentId,
]));
$marksheetQr = banglaQrPngDataUri($marksheetUrl, 6);

function resultGradeClass(string $grade): string
{
    return $grade === 'F' ? 'is-fail' : ($grade === 'A+' ? 'is-top' : 'is-pass');
}

ob_start();
include __DIR__ . '/marksheet-content.php';
$html = (string) ob_get_clean();

$mpdf = createBanglaMpdf(
    trim((string) ($school['school_name_bn'] ?? '')) . ' - মার্কশিট',
    trim((string) ($school['school_name_bn'] ?? 'School Admin')),
    'A4',
    'P'
);
$mpdf->SetMargins(12, 12, 12);
$mpdf->SetAutoPageBreak(true, 12);
$mpdf->WriteHTML(<<<'CSS'
body { font-family: hindsiliguri, sans-serif; color: #17231c; font-size: 9pt; }
.school-marksheet { width: auto; margin: 0; padding: 5mm; border: .25mm solid #9daea3; border-radius: 4mm; color: #17231c; background-color: #fff; }
.marksheet-pdf-header { width: 100%; border-collapse: collapse; border-bottom: .5mm solid #1a4d3e; margin: 0 0 3mm; }
.marksheet-pdf-header td { vertical-align: middle; padding: 0 0 3mm; }
.marksheet-pdf-logo, .marksheet-pdf-qr { width: 24mm; text-align: center; }
.marksheet-pdf-logo img { width: 18mm; height: 18mm; }
.marksheet-pdf-qr img { width: 18mm; height: 18mm; }
.marksheet-pdf-school { text-align: center; }
.marksheet-pdf-school h1 { font-size: 17pt; margin: 0 0 .5mm; color: #0f2f26; }
.marksheet-pdf-school p { font-size: 8pt; margin: 0; color: #5a6f66; }
.school-marksheet-title { text-align: left; padding: 3mm 2mm; margin: 0 0 3mm; border-left: 1mm solid #1a4d3e; background-color: #f0f5f2; }
.school-marksheet-title h2 { font-size: 12pt; margin: 0; color: #0f2f26; }
.school-marksheet-title span { display: block; margin-top: 1mm; font-size: 9pt; color: #5a6f66; font-weight: bold; }
.marksheet-pdf-meta { width: 100%; margin: 0 0 3mm; border-collapse: collapse; }
.marksheet-pdf-meta td { width: 27%; padding: 1mm 2mm; vertical-align: middle; font-size: 8pt; font-weight: bold; }
.marksheet-pdf-meta .marksheet-pdf-photo { width: 20mm; padding: 0 3mm 0 0; }
.marksheet-pdf-photo img { width: 20mm; height: 25mm; border: .25mm solid #d5e2db; }
.marksheet-pdf-meta small { font-size: 7pt; font-weight: normal; }
.marksheet-pdf-summary { width: 100%; margin: 0 0 3mm; border-collapse: separate; border-spacing: 1mm 0; }
.marksheet-pdf-summary td { width: 20%; border: .25mm solid #dbe7e0; padding: 2.5mm 2mm; vertical-align: middle; background-color: #fbfdfc; }
.marksheet-pdf-summary span { display: block; font-size: 7pt; color: #5a6f66; }
.marksheet-pdf-summary strong { font-size: 9pt; color: #173c31; }
.marksheet-pdf-summary strong.is-fail { color: #b42318; }
.marksheet-pdf-summary strong.is-pass { color: #187044; }
.school-marksheet-table { width: 100%; border-collapse: collapse; font-size: 8pt; background-color: #fff; }
.school-marksheet-table th, .school-marksheet-table td { border: .25mm solid #d7e0da; padding: 1.7mm 1.7mm; vertical-align: middle; text-align: center; }
.school-marksheet-table tbody td { padding-top: 3.4mm; padding-bottom: 3.4mm; }
.school-marksheet-table th:first-child, .school-marksheet-table td:first-child { text-align: left; font-weight: bold; }
.school-marksheet-table thead th, .school-marksheet-table tfoot th { background-color: #e8f1eb; color: #173c31; font-weight: bold; }
.marksheet-pdf-grade-pill { width: 12mm; margin: 0 auto; border-collapse: separate; border-radius: 3mm; }
.marksheet-pdf-grade-pill td { padding: 1mm; border: 0; border-radius: 3mm; text-align: center; font-weight: bold; }
.marksheet-pdf-grade-pill .is-top { background-color: #e8f6ee; color: #1f7a4d; }
.marksheet-pdf-grade-pill .is-pass { background-color: #eef4ff; color: #1849a9; }
.marksheet-pdf-grade-pill .is-fail { background-color: #fde8e6; color: #b42318; }
.school-grade-pill { display: inline-block; min-width: 9mm; padding: .5mm 2mm; border-radius: 3mm; font-weight: bold; }
.school-grade-pill.is-top { background-color: #e8f6ee; color: #1f7a4d; }
.school-grade-pill.is-pass { background-color: #eef4ff; color: #1849a9; }
.school-grade-pill.is-fail { background-color: #fde8e6; color: #b42318; }
.school-subject-note { display: block; font-size: 7pt; color: #5a6f66; }
.marksheet-pdf-grade-scale { width: 100%; margin-top: 3mm; border: .25mm solid #dbe7e0; border-collapse: collapse; color: #33443a; font-size: 6pt; }
.marksheet-pdf-grade-scale th, .marksheet-pdf-grade-scale td { padding: 2.3mm 1mm; text-align: center; white-space: nowrap; }
.marksheet-pdf-grade-scale th { color: #0f2f26; font-size: 7pt; }
.school-grade-scale strong { color: #0f2f26; }
.school-marksheet-failed, .school-marksheet-note { margin: 2mm 0 0; font-size: 8pt; }
.school-marksheet-failed { color: #b42318; }
CSS, \Mpdf\HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

$safeRoll = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $student['cls_roll']) ?: (string) $studentId;
$filename = 'marksheet-' . $safeRoll . '-' . preg_replace('/[^0-9-]+/', '', $sessionYear) . '.pdf';
$mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
