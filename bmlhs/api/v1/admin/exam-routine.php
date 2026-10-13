<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileAdminExamRoutineError(string $code, string $message, int $status = 422): never
{
    mobileApiJson(['error' => ['code' => $code, 'message' => $message]], $status);
}

function mobileAdminExamRoutineScope(PDO $db, int $examId, int $classId, int $sectionId): array
{
    $exam = null;
    foreach (schoolExams($db, true) as $row) {
        if ((int) $row['id'] === $examId) {
            $exam = $row;
            break;
        }
    }
    if ($exam === null) {
        mobileAdminExamRoutineError('invalid_exam', 'The selected active exam is unavailable.');
    }

    $class = null;
    foreach (schoolClasses($db) as $row) {
        if ((int) $row['id'] === $classId) {
            $class = $row;
            break;
        }
    }
    if ($class === null) {
        mobileAdminExamRoutineError('invalid_class', 'The selected class is unavailable.');
    }
    $sections = schoolSections($db, $classId);
    if ($sectionId > 0 && !in_array(
        $sectionId,
        array_map(static fn (array $row): int => (int) $row['id'], $sections),
        true
    )) {
        mobileAdminExamRoutineError('invalid_section', 'The selected section is not valid for this class.');
    }

    return [$exam, $class, $sections];
}

function mobileAdminExamRoutineYear(mixed $value): string
{
    if (!is_string($value) || preg_match('/^\d{4}$/', $value) !== 1) {
        mobileAdminExamRoutineError('invalid_session_year', 'session_year must contain four digits.');
    }

    return $value;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
}

try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    if ($identity['account_type'] !== 'staff' || !canAccessModule('exam-routine', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage exam routines.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $examId = filter_var($_GET['exam_id'] ?? null, FILTER_VALIDATE_INT);
        $classId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
        $sectionId = filter_var($_GET['section_id'] ?? 0, FILTER_VALIDATE_INT);
        $sessionYear = mobileAdminExamRoutineYear($_GET['session_year'] ?? (string) date('Y'));
        if (!is_int($examId) || $examId < 1 || !is_int($classId) || $classId < 1
            || !is_int($sectionId) || $sectionId < 0
        ) {
            mobileAdminExamRoutineError('invalid_filter', 'Valid exam_id, class_id and non-negative section_id are required.');
        }
        [$exam, $class, $sections] = mobileAdminExamRoutineScope($db, $examId, $classId, $sectionId);
        $rows = examRoutineRows($db, $examId, $sessionYear, $classId, $sectionId);
        if ($sectionId > 0) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['section_id'] ?? 0) === $sectionId
                    || (int) ($row['section_id'] ?? 0) === 0
            ));
        }
        mobileApiJson(['data' => [
            'exam' => [
                'id' => $examId,
                'name' => trim((string) ($exam['exam_name_bangla'] ?? '')) ?: (string) ($exam['exam_name_english'] ?? ''),
            ],
            'class' => [
                'id' => $classId,
                'name' => trim((string) ($class['class_name_ban'] ?? '')) ?: (string) ($class['class_name'] ?? ''),
            ],
            'section_id' => $sectionId,
            'sections' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => trim((string) ($row['section_name_ban'] ?? '')) ?: (string) ($row['section_name'] ?? ''),
            ], $sections),
            'session_year' => $sessionYear,
            'subjects' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => examRoutineSubjectLabel($row),
                'full_marks' => (int) ($row['full_marks'] ?? 100),
            ], classSubjects($db, $classId, $sectionId)),
            'entries' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'exam_date' => (string) $row['exam_date'],
                'subject' => (string) $row['subject_name'],
                'class_subject_id' => (int) ($row['class_subject_id'] ?? 0),
                'start_time' => (string) ($row['start_time'] ?? ''),
                'end_time' => (string) ($row['end_time'] ?? ''),
                'room' => (string) ($row['room'] ?? ''),
                'full_marks' => (string) ($row['full_marks'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'section_id' => (int) ($row['section_id'] ?? 0),
            ], $rows),
        ]]);
    }

    $payload = mobileApiReadJson(65536);
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['save', 'delete', 'clear', 'copy'], true)) {
        mobileAdminExamRoutineError('invalid_action', 'Action must be save, delete, clear or copy.');
    }
    $examId = filter_var($payload['exam_id'] ?? null, FILTER_VALIDATE_INT);
    $classId = filter_var($payload['class_id'] ?? null, FILTER_VALIDATE_INT);
    $sectionId = filter_var($payload['section_id'] ?? 0, FILTER_VALIDATE_INT);
    $sessionYear = mobileAdminExamRoutineYear($payload['session_year'] ?? null);
    if (!is_int($examId) || $examId < 1 || !is_int($classId) || $classId < 1
        || !is_int($sectionId) || $sectionId < 0
    ) {
        mobileAdminExamRoutineError('invalid_filter', 'Valid exam_id, class_id and non-negative section_id are required.');
    }
    mobileAdminExamRoutineScope($db, $examId, $classId, $sectionId);

    if ($action === 'copy') {
        $toClassId = filter_var($payload['to_class_id'] ?? null, FILTER_VALIDATE_INT);
        $toSectionId = filter_var($payload['to_section_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!is_int($toClassId) || $toClassId < 1 || !is_int($toSectionId) || $toSectionId < 0) {
            mobileAdminExamRoutineError('invalid_destination', 'A valid destination class_id and section_id are required.');
        }
        mobileAdminExamRoutineScope($db, $examId, $toClassId, $toSectionId);
        $db->beginTransaction();
        $copied = copyExamRoutineToSection($db, $examId, $sessionYear, $classId, $sectionId, $toClassId, $toSectionId);
        $db->commit();
        mobileApiJson(['data' => [
            'status' => 'copied',
            'copied' => $copied,
            'from' => ['class_id' => $classId, 'section_id' => $sectionId],
            'to' => ['class_id' => $toClassId, 'section_id' => $toSectionId],
        ]]);
    }

    if ($action === 'clear') {
        $db->beginTransaction();
        $deleted = clearExamRoutine($db, $examId, $sessionYear, $classId, $sectionId);
        $db->commit();
        mobileApiJson(['data' => ['status' => 'cleared', 'deleted' => $deleted]]);
    }

    $entryId = filter_var($payload['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!is_int($entryId) || $entryId < 0) {
        mobileAdminExamRoutineError('invalid_entry', 'id must be a positive entry ID when provided.');
    }
    if ($action === 'delete') {
        if ($entryId < 1) {
            mobileAdminExamRoutineError('invalid_entry', 'A valid entry id is required.');
        }
        $db->beginTransaction();
        $statement = $db->prepare(
            'SELECT id FROM exam_routine
             WHERE id = :id AND exam_id = :exam_id AND session_year = :session_year
               AND class_id = :class_id AND section_id = :section_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'id' => $entryId,
            'exam_id' => $examId,
            'session_year' => $sessionYear,
            'class_id' => $classId,
            'section_id' => $sectionId,
        ]);
        if ($statement->fetchColumn() === false) {
            $db->rollBack();
            mobileAdminExamRoutineError('not_found', 'Exam routine entry not found in this scope.', 404);
        }
        deleteExamRoutineRow($db, $entryId);
        $db->commit();
        mobileApiJson(['data' => ['status' => 'deleted', 'id' => $entryId]]);
    }

    $subjectId = filter_var($payload['subject_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($subjectId) || $subjectId < 1) {
        mobileAdminExamRoutineError('invalid_subject', 'A valid class subject_id is required.');
    }
    $subject = null;
    foreach (classSubjects($db, $classId, $sectionId) as $candidate) {
        if ((int) $candidate['id'] === $subjectId) {
            $subject = $candidate;
            break;
        }
    }
    if ($subject === null) {
        mobileAdminExamRoutineError('invalid_subject', 'The subject is not assigned to this class and section.');
    }

    $date = $payload['exam_date'] ?? null;
    if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        mobileAdminExamRoutineError('invalid_date', 'exam_date must use YYYY-MM-DD.');
    }
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    if (!checkdate($month, $day, $year)) {
        mobileAdminExamRoutineError('invalid_date', 'exam_date is not a valid calendar date.');
    }
    $startTime = $payload['start_time'] ?? '';
    $endTime = $payload['end_time'] ?? '';
    foreach (['start_time' => $startTime, 'end_time' => $endTime] as $field => $value) {
        if (!is_string($value) || ($value !== '' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1)) {
            mobileAdminExamRoutineError('invalid_time', $field . ' must be blank or use 24-hour HH:MM.');
        }
    }
    $room = $payload['room'] ?? '';
    $fullMarks = $payload['full_marks'] ?? ($subject['full_marks'] ?? 100);
    $note = $payload['note'] ?? '';
    if (!is_string($room) || mb_strlen(trim($room), 'UTF-8') > 80
        || (!is_string($fullMarks) && !is_int($fullMarks) && !is_float($fullMarks))
        || mb_strlen(trim((string) $fullMarks), 'UTF-8') > 20
        || !is_string($note) || mb_strlen(trim($note), 'UTF-8') > 255
    ) {
        mobileAdminExamRoutineError('invalid_entry', 'room, full_marks or note is invalid or too long.');
    }
    if (trim((string) $fullMarks) !== ''
        && (!is_numeric((string) $fullMarks) || !is_finite((float) $fullMarks) || (float) $fullMarks < 0)
    ) {
        mobileAdminExamRoutineError('invalid_entry', 'full_marks must be blank or a non-negative number.');
    }
    $sortOrder = filter_var($payload['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 1000000) {
        mobileAdminExamRoutineError('invalid_entry', 'sort_order must be an integer from 0 to 1,000,000.');
    }
    if ($entryId > 0) {
        $db->beginTransaction();
        $statement = $db->prepare(
            'SELECT id FROM exam_routine
             WHERE id = :id AND exam_id = :exam_id AND session_year = :session_year
               AND class_id = :class_id AND section_id = :section_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'id' => $entryId,
            'exam_id' => $examId,
            'session_year' => $sessionYear,
            'class_id' => $classId,
            'section_id' => $sectionId,
        ]);
        if ($statement->fetchColumn() === false) {
            $db->rollBack();
            mobileAdminExamRoutineError('not_found', 'Exam routine entry not found in this scope.', 404);
        }
    }

    if (!$db->inTransaction()) {
        $db->beginTransaction();
    }
    $savedId = saveExamRoutineRow($db, [
        'exam_id' => $examId,
        'session_year' => $sessionYear,
        'class_id' => $classId,
        'section_id' => $sectionId,
        'exam_date' => $date,
        'subject_name' => examRoutineSubjectLabel($subject),
        'class_subject_id' => $subjectId,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'room' => trim($room),
        'full_marks' => trim((string) $fullMarks),
        'note' => trim($note),
        'sort_order' => $sortOrder,
    ], $entryId);
    $db->commit();
    mobileApiJson(['data' => ['status' => $entryId > 0 ? 'updated' : 'saved', 'id' => $savedId]]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiDatabaseFailure($exception, 'admin exam routine');
} catch (RuntimeException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiJson(['error' => ['code' => 'invalid_entry', 'message' => $exception->getMessage()]], 422);
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mobile API admin exam routine failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The exam routine request could not be completed.']], 500);
}
