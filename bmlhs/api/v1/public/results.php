<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$examId = filter_input(INPUT_GET, 'exam_id', FILTER_VALIDATE_INT);
$sectionId = filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT);
$year = trim((string) ($_GET['session_year'] ?? (string) date('Y')));
if (!is_int($classId) || $classId < 1 || !is_int($examId) || $examId < 1
    || preg_match('/^\d{4}$/', $year) !== 1 || (int) $year < 2000 || (int) $year > 2100
) {
    mobileApiJson(['error' => ['code' => 'invalid_filter', 'message' => 'Valid class_id, exam_id and session_year are required.']], 422);
}
$sectionId = is_int($sectionId) && $sectionId > 0 ? $sectionId : 0;

try {
    $db = getDatabaseConnection();
    $publishedExam = null;
    foreach (schoolPublishedExams($db) as $exam) {
        if ((int) $exam['id'] === $examId) {
            $publishedExam = $exam;
            break;
        }
    }
    if ($publishedExam === null) {
        mobileApiJson(['error' => ['code' => 'not_published', 'message' => 'Results for this exam are not published.']], 404);
    }
    $classExists = false;
    foreach (schoolClasses($db, true) as $class) {
        if ((int) $class['id'] === $classId) {
            $classExists = true;
            break;
        }
    }
    if (!$classExists) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Class was not found.']], 404);
    }
    if ($sectionId > 0) {
        $sections = schoolSections($db, $classId, true);
        if (!in_array($sectionId, array_map(static fn (array $row): int => (int) $row['id'], $sections), true)) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Section was not found for the selected class.']], 404);
        }
    }

    $summaries = classExamResultSummaries($db, $classId, $sectionId, $year, $examId);
    $results = [];
    foreach ($summaries as $row) {
        $student = $row['student'];
        $sheet = $row['sheet'];
        $studentName = trim((string) ($student['name_ban'] ?? ''));
        if ($studentName === '') {
            $studentName = trim((string) ($student['name_eng'] ?? ''));
        }
        $results[] = [
            'student_id' => (int) $student['id'],
            'name' => $studentName,
            'roll' => (string) ($student['cls_roll'] ?? ''),
            'section_id' => (int) ($student['sections'] ?? 0),
            'total' => (float) ($sheet['total'] ?? 0),
            'gpa' => (float) ($sheet['gpa'] ?? 0),
            'grade' => (string) ($sheet['letter'] ?? ''),
            'rank' => (int) ($row['rank'] ?? 0),
        ];
    }
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public results');
}

mobileApiJson([
    'data' => [
        'exam_id' => $examId,
        'exam_name' => (string) ($publishedExam['exam_name_bangla'] ?? $publishedExam['exam_name_english'] ?? ''),
        'class_id' => $classId,
        'section_id' => $sectionId,
        'session_year' => $year,
        'results' => $results,
    ],
]);
