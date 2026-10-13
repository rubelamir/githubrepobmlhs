<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileMarksRoster(PDO $db, int $classId, int $sectionId, string $sessionYear, bool $requireUniqueRolls = false): array
{
    $sql = 'SELECT id, name_ban, name_eng, cls_roll FROM students_info
            WHERE status = 1 AND cls = :class_id AND session_year = :session_year';
    $params = ['class_id' => $classId, 'session_year' => $sessionYear];
    if ($sectionId > 0) {
        $sql .= ' AND sections = :section_id';
        $params['section_id'] = (string) $sectionId;
    }
    $students = fetchRows($db, $sql . ' ORDER BY cls_roll ASC, id ASC LIMIT 401', $params);
    if (count($students) > 400) {
        mobileApiJson(['error' => ['code' => 'roster_too_large', 'message' => 'This class roster exceeds the 400-student limit.']], 422);
    }
    $rolls = [];
    if ($requireUniqueRolls) {
        foreach ($students as $student) {
            $roll = (string) $student['cls_roll'];
            if (isset($rolls[$roll])) {
                mobileApiJson(['error' => ['code' => 'duplicate_roll', 'message' => 'Duplicate student rolls must be corrected before marks can be entered.']], 422);
            }
            $rolls[$roll] = true;
        }
    }

    return $students;
}

function mobileMarkNumber(mixed $raw, string $component): ?float
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
        mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => strtoupper($component) . ' must be a non-negative number or blank.']], 422);
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }
    if (!is_numeric($text) || !is_finite((float) $text) || (float) $text < 0) {
        mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => strtoupper($component) . ' must be a non-negative number or blank.']], 422);
    }

    return (float) $text;
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
    $payload = $method === 'POST' ? mobileApiReadJson(524288) : [];
    $value = static function (string $key, mixed $default = null) use ($method, $payload): mixed {
        return $method === 'GET' ? ($_GET[$key] ?? $default) : ($payload[$key] ?? $default);
    };
    $classId = filter_var($value('class_id'), FILTER_VALIDATE_INT);
    $sectionId = filter_var($value('section_id', 0), FILTER_VALIDATE_INT);
    $examId = filter_var($value('exam_id', 0), FILTER_VALIDATE_INT);
    $subjectId = filter_var($value('subject_id', 0), FILTER_VALIDATE_INT);
    $sessionYear = $value('session_year', date('Y'));
    if (!is_int($classId) || $classId < 1 || !is_int($sectionId) || $sectionId < 0
        || !is_int($examId) || $examId < 0 || !is_int($subjectId) || $subjectId < 0
        || !is_string($sessionYear) || preg_match('/^\d{4}$/', $sessionYear) !== 1
    ) {
        mobileApiJson(['error' => ['code' => 'invalid_filter', 'message' => 'A valid class, section and four-digit session year are required.']], 422);
    }
    if ($sectionId > 0) {
        $sectionIds = array_map(
            static fn (array $section): int => (int) $section['id'],
            schoolSections($db, $classId)
        );
        if (!in_array($sectionId, $sectionIds, true)) {
            mobileApiJson(['error' => ['code' => 'invalid_section', 'message' => 'The selected section is not valid.']], 422);
        }
    }

    $subjects = filterSubjectsForTeacher(classSubjects($db, $classId, $sectionId), (string) ($teacher['teacher_mobile'] ?? ''));
    $exams = schoolExams($db, true);
    if ($subjectId === 0 || $examId === 0) {
        if ($method === 'POST') {
            mobileApiJson(['error' => ['code' => 'invalid_selection', 'message' => 'Select an assigned subject and an exam.']], 422);
        }
        mobileApiJson(['data' => [
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'subjects' => array_map(static fn (array $subject): array => [
                'id' => (int) $subject['id'],
                'name' => trim((string) ($subject['subject_name_ban'] ?? '')) ?: (string) ($subject['subject_name'] ?? ''),
            ] + array_intersect_key($subject, array_flip(['has_mcq', 'has_cq', 'has_practical', 'full_marks']),), $subjects),
            'exams' => array_map(static fn (array $exam): array => [
                'id' => (int) $exam['id'],
                'name' => trim((string) ($exam['exam_name_bangla'] ?? '')) ?: (string) ($exam['exam_name_english'] ?? ''),
            ], $exams),
        ]]);
    }

    $subject = null;
    foreach ($subjects as $candidate) {
        if ((int) $candidate['id'] === $subjectId) {
            $subject = $candidate;
            break;
        }
    }
    $examExists = false;
    foreach ($exams as $exam) {
        if ((int) $exam['id'] === $examId) {
            $examExists = true;
            break;
        }
    }
    if ($subject === null) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You are not assigned to enter marks for this subject.']], 403);
    }
    if (!$examExists) {
        mobileApiJson(['error' => ['code' => 'invalid_exam', 'message' => 'The selected exam is unavailable.']], 422);
    }
    $action = null;
    if ($method === 'POST') {
        $action = $payload['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['save', 'final_submit'], true)) {
            mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Action must be save or final_submit.']], 422);
        }
        $db->beginTransaction();
        ensureMarkSubmissionDraft($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
        $lock = $db->prepare(
            'SELECT status FROM mark_submissions
             WHERE class_id = :class_id AND section_id = :section_id
               AND session_year = :session_year AND exam_id = :exam_id
               AND class_subject_id = :subject_id
             LIMIT 1 FOR UPDATE'
        );
        $lock->execute([
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_year' => $sessionYear,
            'exam_id' => $examId,
            'subject_id' => $subjectId,
        ]);
        $submission = $lock->fetch();
    } else {
        $submission = markSubmissionRow($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
    }
    if (($submission['status'] ?? '') === 'submitted' && $method === 'POST') {
        $db->rollBack();
        mobileApiJson(['error' => ['code' => 'marks_locked', 'message' => 'These marks have been finally submitted and cannot be changed.']], 409);
    }
    $students = mobileMarksRoster($db, $classId, $sectionId, $sessionYear, $method === 'POST');

    if ($method === 'POST' && $action === 'final_submit') {
        if ($students === []) {
            $db->rollBack();
            mobileApiJson(['error' => ['code' => 'empty_roster', 'message' => 'Marks cannot be submitted for an empty class roster.']], 422);
        }
        finalizeMarkSubmission($db, $classId, $sectionId, $sessionYear, $examId, $subjectId, (string) ($teacher['teacher_mobile'] ?? ''));
        $db->commit();
        mobileApiJson(['data' => ['status' => 'submitted']]);
    }

    if ($method === 'GET') {
        $markMap = marksByStudentForSubject($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
        $rows = [];
        foreach ($students as $student) {
            $id = (int) $student['id'];
            $mark = $markMap['id:' . $id] ?? [];
            $rows[] = [
                'student_id' => $id,
                'roll' => (string) $student['cls_roll'],
                'name' => trim((string) ($student['name_ban'] ?? '')) ?: (string) ($student['name_eng'] ?? ''),
                'mcq' => $mark['mcq'] ?? null,
                'cq' => $mark['cq'] ?? null,
                'practical' => $mark['practical'] ?? null,
            ];
        }
        mobileApiJson(['data' => [
            'subject' => [
                'id' => $subjectId,
                'name' => trim((string) ($subject['subject_name_ban'] ?? '')) ?: (string) ($subject['subject_name'] ?? ''),
                'has_mcq' => (int) ($subject['has_mcq'] ?? 1) === 1,
                'has_cq' => (int) ($subject['has_cq'] ?? 1) === 1,
                'has_practical' => (int) ($subject['has_practical'] ?? 0) === 1,
                'full_marks' => (float) ($subject['full_marks'] ?? 100),
            ],
            'exam_id' => $examId,
            'session_year' => $sessionYear,
            'submission_status' => (string) ($submission['status'] ?? 'draft'),
            'students' => $rows,
        ]]);
    }

    $entries = $payload['students'] ?? null;
    if (!is_array($entries) || $students === [] || count($entries) !== count($students) || count($entries) > 400) {
        mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => 'Submit one marks entry for every student in the selected roster.']], 422);
    }
    $allowed = [];
    foreach ($students as $student) {
        $allowed[(int) $student['id']] = $student;
    }
    $validated = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => 'Each marks entry must be an object.']], 422);
        }
        $studentId = filter_var($entry['student_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($studentId) || !isset($allowed[$studentId]) || isset($validated[$studentId])) {
            mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => 'Marks contain an unknown or duplicate student.']], 422);
        }
        $marks = [];
        foreach (['mcq', 'cq', 'practical'] as $component) {
            $raw = $entry[$component] ?? null;
            $enabled = (int) ($subject['has_' . $component] ?? ($component !== 'practical' ? 1 : 0)) === 1;
            if (!$enabled && $raw !== null && $raw !== '') {
                mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => strtoupper($component) . ' is disabled for this subject.']], 422);
            }
            $marks[$component] = $enabled ? mobileMarkNumber($raw, $component) : null;
        }
        $total = (float) ($marks['mcq'] ?? 0) + (float) ($marks['cq'] ?? 0) + (float) ($marks['practical'] ?? 0);
        $fullMarks = (float) ($subject['full_marks'] ?? 100);
        if ($fullMarks > 0 && $total > $fullMarks) {
            mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => 'A student total cannot exceed the subject full marks.']], 422);
        }
        $validated[$studentId] = $marks;
    }
    if (count($validated) !== count($allowed)) {
        mobileApiJson(['error' => ['code' => 'invalid_marks', 'message' => 'Marks must include every student in the selected roster.']], 422);
    }

    foreach ($validated as $studentId => $marks) {
        $student = $allowed[$studentId];
        if ($marks['mcq'] === null && $marks['cq'] === null && $marks['practical'] === null) {
            deleteStudentSubjectMark(
                $db,
                (string) $student['cls_roll'],
                $classId,
                $sectionId,
                $sessionYear,
                $examId,
                $subjectId
            );
            continue;
        }
        upsertStudentSubjectMark(
            $db,
            $studentId,
            (string) $student['cls_roll'],
            $classId,
            $sectionId,
            $sessionYear,
            $examId,
            $subjectId,
            $marks['mcq'],
            $marks['cq'],
            $marks['practical'],
            (string) ($teacher['teacher_mobile'] ?? '')
        );
    }
    $db->commit();
    mobileApiJson(['data' => ['status' => 'draft', 'saved' => count($validated)]]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiDatabaseFailure($exception, 'teacher marks');
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mobile API teacher marks failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The marks request could not be completed.']], 500);
}
