<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileAdminResultsError(string $code, string $message, int $status = 422): never
{
    mobileApiJson(['error' => ['code' => $code, 'message' => $message]], $status);
}

function mobileAdminResultsPublication(PDO $db): never
{
    mobileApiJson(['data' => [
        'exams' => array_map(static fn (array $exam): array => [
            'id' => (int) $exam['id'],
            'name' => trim((string) ($exam['exam_name_bangla'] ?? '')) ?: (string) ($exam['exam_name_english'] ?? ''),
            'active' => (int) ($exam['is_active'] ?? 1) === 1,
            'published' => (int) ($exam['public_results_enabled'] ?? 0) === 1,
        ], schoolExams($db)),
    ]]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
}

try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    if ($identity['account_type'] !== 'staff') {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'Staff access is required.']], 403);
    }
    $teacher = $identity['account'];

    if ($method === 'POST') {
        if (!canAccessModule('result-publication', $teacher)) {
            mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to publish results.']], 403);
        }
        mobileApiBeginIdempotentWrite($db, $identity);
        $payload = mobileApiReadJson(8192);
        if (($payload['action'] ?? null) !== 'set_publication') {
            mobileAdminResultsError('invalid_action', 'Action must be set_publication.');
        }
        $examId = filter_var($payload['exam_id'] ?? null, FILTER_VALIDATE_INT);
        $published = $payload['published'] ?? null;
        if (!is_int($examId) || $examId < 1 || !in_array($published, [true, false, 0, 1, '0', '1'], true)) {
            mobileAdminResultsError('invalid_publication', 'Provide a valid exam_id and published boolean.');
        }
        $value = in_array($published, [true, 1, '1'], true) ? 1 : 0;
        $db->beginTransaction();
        $exam = $db->prepare('SELECT id, public_results_enabled FROM exam_details WHERE id = :id LIMIT 1 FOR UPDATE');
        $exam->execute(['id' => $examId]);
        $current = $exam->fetch();
        if ($current === false) {
            $db->rollBack();
            mobileAdminResultsError('not_found', 'Exam not found.', 404);
        }
        $wasPublished = (int) ($current['public_results_enabled'] ?? 0) === 1;
        $db->prepare('UPDATE exam_details SET public_results_enabled = :published WHERE id = :id')
            ->execute(['published' => $value, 'id' => $examId]);
        $db->commit();
        if ($value === 1 && !$wasPublished) {
            writeAuditLog($db, 'publish', 'exam_result', $examId, 'Published exam results from the mobile admin.');
        }
        mobileApiJson(['data' => [
            'exam_id' => $examId,
            'published' => $value === 1,
            'status' => $value === 1 ? 'published' : 'hidden',
        ]]);
    }

    if (($_GET['view'] ?? '') === 'publication') {
        if (!canAccessModule('result-publication', $teacher)) {
            mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to view publication settings.']], 403);
        }
        mobileAdminResultsPublication($db);
    }
    if (!canAccessModule('results', $teacher)) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to view results.']], 403);
    }

    $classId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
    $sectionId = filter_var($_GET['section_id'] ?? 0, FILTER_VALIDATE_INT);
    $examId = filter_var($_GET['exam_id'] ?? null, FILTER_VALIDATE_INT);
    $subjectId = filter_var($_GET['subject_id'] ?? null, FILTER_VALIDATE_INT);
    $sessionYear = $_GET['session_year'] ?? (string) date('Y');
    if (!is_int($classId) || $classId < 1 || !is_int($sectionId) || $sectionId < 0
        || !is_int($examId) || $examId < 1 || !is_int($subjectId) || $subjectId < 1
        || !is_string($sessionYear) || preg_match('/^\d{4}$/', $sessionYear) !== 1
    ) {
        mobileAdminResultsError(
            'invalid_filter',
            'Valid class_id, section_id, exam_id, subject_id and four-digit session_year are required.'
        );
    }

    $class = null;
    foreach (schoolClasses($db) as $row) {
        if ((int) $row['id'] === $classId) {
            $class = $row;
            break;
        }
    }
    if ($class === null) {
        mobileAdminResultsError('invalid_class', 'The selected class is unavailable.');
    }
    if ($sectionId > 0 && !in_array(
        $sectionId,
        array_map(
            static fn (array $row): int => (int) $row['id'],
            schoolSections($db, $classId)
        ),
        true
    )) {
        mobileAdminResultsError('invalid_section', 'The selected section is not valid for this class.');
    }

    $exam = null;
    foreach (schoolExams($db, true) as $row) {
        if ((int) $row['id'] === $examId) {
            $exam = $row;
            break;
        }
    }
    if ($exam === null) {
        mobileAdminResultsError('invalid_exam', 'The selected active exam is unavailable.');
    }

    $seeAll = canViewAllMarksheets($teacher);
    $subjects = classSubjects($db, $classId, $sectionId);
    if (!$seeAll) {
        $subjects = filterSubjectsForTeacher($subjects, (string) ($teacher['teacher_mobile'] ?? ''));
    }
    $subject = null;
    foreach ($subjects as $row) {
        if ((int) $row['id'] === $subjectId) {
            $subject = $row;
            break;
        }
    }
    if ($subject === null) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You are not authorized to view this subject result.']], 403);
    }

    $isOwnerTeacher = canEditClassSubject($subject, $teacher);
    $submission = markSubmissionRow($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
    $requireFinal = !$isOwnerTeacher;
    $markMap = marksByStudentForSubject($db, $classId, $sectionId, $sessionYear, $examId, $subjectId, $requireFinal);

    $studentSql = 'SELECT id, name_ban, name_eng, cls_roll
                   FROM students_info
                   WHERE status = 1 AND cls = :class_id AND session_year = :session_year';
    $studentParams = ['class_id' => $classId, 'session_year' => $sessionYear];
    if ($sectionId > 0) {
        $studentSql .= ' AND sections = :section_id';
        $studentParams['section_id'] = (string) $sectionId;
    }
    $students = fetchRows($db, $studentSql . ' ORDER BY cls_roll ASC, id ASC LIMIT 401', $studentParams);
    if (count($students) > 400) {
        mobileAdminResultsError('roster_too_large', 'This class roster exceeds the 400-student response limit.');
    }
    $fullMarks = (float) ($subject['full_marks'] ?? 100);
    $results = [];
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $mark = $markMap['id:' . $studentId] ?? $markMap['roll:' . (string) $student['cls_roll']] ?? [];
        $values = [];
        $hasMarks = false;
        foreach (['mcq', 'cq', 'practical'] as $component) {
            $raw = $mark[$component] ?? null;
            $values[$component] = $raw === null || $raw === '' ? null : (float) $raw;
            $hasMarks = $hasMarks || $values[$component] !== null;
        }
        $total = $hasMarks
            ? (float) ($values['mcq'] ?? 0) + (float) ($values['cq'] ?? 0) + (float) ($values['practical'] ?? 0)
            : null;
        $results[] = [
            'student_id' => $studentId,
            'roll' => (string) ($student['cls_roll'] ?? ''),
            'name' => trim((string) ($student['name_ban'] ?? '')) ?: (string) ($student['name_eng'] ?? ''),
            'mcq' => $values['mcq'],
            'cq' => $values['cq'],
            'practical' => $values['practical'],
            'total' => $total,
            'grade' => $hasMarks ? subjectPaperGrade($subject, $values['mcq'], $values['cq'], $values['practical']) : null,
        ];
    }

    $className = trim((string) ($class['class_name_ban'] ?? '')) ?: (string) ($class['class_name'] ?? '');
    $examName = trim((string) ($exam['exam_name_bangla'] ?? '')) ?: (string) ($exam['exam_name_english'] ?? '');
    $subjectName = trim((string) ($subject['subject_name_ban'] ?? '')) ?: (string) ($subject['subject_name'] ?? '');
    mobileApiJson(['data' => [
        'class' => ['id' => $classId, 'name' => $className],
        'section_id' => $sectionId,
        'exam' => ['id' => $examId, 'name' => $examName],
        'subject' => [
            'id' => $subjectId,
            'name' => $subjectName,
            'full_marks' => $fullMarks,
            'has_mcq' => (int) ($subject['has_mcq'] ?? 1) === 1,
            'has_cq' => (int) ($subject['has_cq'] ?? 1) === 1,
            'has_practical' => (int) ($subject['has_practical'] ?? 0) === 1,
        ],
        'session_year' => $sessionYear,
        'submission_status' => $submission === null ? 'legacy' : (string) $submission['status'],
        'marks_visible' => $isOwnerTeacher || $submission === null || (string) $submission['status'] === 'submitted',
        'students' => $results,
    ]]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiDatabaseFailure($exception, 'admin results');
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mobile API admin results failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The results request could not be completed.']], 500);
}
