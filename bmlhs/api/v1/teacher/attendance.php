<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileAttendanceDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function mobileAttendanceStudentRoster(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear
): array {
    $sql = 'SELECT id, name_ban, name_eng, cls_roll
            FROM students_info
            WHERE status = 1 AND cls = :class_id AND session_year = :session_year';
    $params = ['class_id' => $classId, 'session_year' => $sessionYear];
    if ($sectionId > 0) {
        $sql .= ' AND sections = :section_id';
        $params['section_id'] = (string) $sectionId;
    }
    $sql .= ' ORDER BY cls_roll ASC, id ASC LIMIT 401';
    $students = fetchRows($db, $sql, $params);
    if (count($students) > 400) {
        mobileApiJson(['error' => ['code' => 'roster_too_large', 'message' => 'This class roster exceeds the 400-student limit.']], 422);
    }

    return $students;
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
    $date = $method === 'GET' ? ($_GET['date'] ?? date('Y-m-d')) : '';
    $payload = $method === 'POST' ? mobileApiReadJson(524288) : [];
    if ($method === 'POST') {
        $date = $payload['date'] ?? null;
    }
    $classId = filter_var($method === 'GET' ? ($_GET['class_id'] ?? null) : ($payload['class_id'] ?? null), FILTER_VALIDATE_INT);
    $sectionId = filter_var($method === 'GET' ? ($_GET['section_id'] ?? 0) : ($payload['section_id'] ?? 0), FILTER_VALIDATE_INT);
    $sessionYear = $method === 'GET' ? ($_GET['session_year'] ?? date('Y')) : ($payload['session_year'] ?? null);

    if (!is_string($date) || !is_string($sessionYear)
        || !is_int($classId) || $classId < 1 || !is_int($sectionId) || $sectionId < 0
        || preg_match('/^\d{4}$/', $sessionYear) !== 1 || !mobileAttendanceDate($date)
    ) {
        mobileApiJson(['error' => ['code' => 'invalid_filter', 'message' => 'A valid class, section, four-digit session year and YYYY-MM-DD date are required.']], 422);
    }
    $date = trim($date);
    $sessionYear = trim($sessionYear);
    $classIds = array_map(static fn (array $class): int => (int) $class['id'], schoolClasses($db));
    if (!in_array($classId, $classIds, true)) {
        mobileApiJson(['error' => ['code' => 'invalid_class', 'message' => 'The selected class is unavailable.']], 422);
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
    if (!canTakeAttendanceForClass($db, $classId, $sectionId, $sessionYear, $teacher)) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You are not assigned to take attendance for this class and section.']], 403);
    }
    $students = mobileAttendanceStudentRoster($db, $classId, $sectionId, $sessionYear);
    $allowed = [];
    foreach ($students as $student) {
        $allowed[(int) $student['id']] = $student;
    }

    if ($method === 'GET') {
        $existing = [];
        if ($allowed !== []) {
            $ids = implode(',', array_map('intval', array_keys($allowed)));
            foreach (fetchRows(
                $db,
                "SELECT student_id, status, remarks FROM student_attendance
                 WHERE attendance_date = :date AND student_id IN ({$ids})",
                ['date' => $date]
            ) as $row) {
                $existing[(int) $row['student_id']] = [
                    'status' => (string) $row['status'],
                    'remarks' => (string) ($row['remarks'] ?? ''),
                ];
            }
        }
        $rows = [];
        foreach ($students as $student) {
            $id = (int) $student['id'];
            $saved = $existing[$id] ?? [];
            $rows[] = [
                'student_id' => $id,
                'roll' => (string) ($student['cls_roll'] ?? ''),
                'name' => trim((string) ($student['name_ban'] ?? '')) ?: (string) ($student['name_eng'] ?? ''),
                'status' => (string) ($saved['status'] ?? 'present'),
                'remarks' => (string) ($saved['remarks'] ?? ''),
            ];
        }
        mobileApiJson(['data' => ['date' => $date, 'class_id' => $classId, 'section_id' => $sectionId, 'session_year' => $sessionYear, 'students' => $rows]]);
    }

    $entries = $payload['students'] ?? null;
    if (!is_array($entries) || count($entries) < 1 || count($entries) > 400) {
        mobileApiJson(['error' => ['code' => 'invalid_attendance', 'message' => 'Provide attendance for 1 to 400 students.']], 422);
    }
    $seen = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            mobileApiJson(['error' => ['code' => 'invalid_attendance', 'message' => 'Each attendance entry must be an object.']], 422);
        }
        $studentId = filter_var($entry['student_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $entry['status'] ?? null;
        $remarksValue = $entry['remarks'] ?? '';
        if (!is_string($status) || !is_string($remarksValue)) {
            mobileApiJson(['error' => ['code' => 'invalid_attendance', 'message' => 'Attendance status and remarks must be text values.']], 422);
        }
        $remarks = trim($remarksValue);
        if (!is_int($studentId) || !isset($allowed[$studentId]) || isset($seen[$studentId])
            || !in_array($status, ['present', 'absent', 'late'], true) || mb_strlen($remarks, 'UTF-8') > 255
        ) {
            mobileApiJson(['error' => ['code' => 'invalid_attendance', 'message' => 'Attendance contains an invalid student, duplicate, status or remark.']], 422);
        }
        $seen[$studentId] = ['status' => $status, 'remarks' => $remarks];
    }

    $statement = $db->prepare(
        'INSERT INTO student_attendance (student_id, attendance_date, status, remarks, marked_by)
         VALUES (:student_id, :attendance_date, :status, :remarks, :marked_by)
         ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), marked_by = VALUES(marked_by)'
    );
    $db->beginTransaction();
    foreach ($seen as $studentId => $entry) {
        $statement->execute([
            'student_id' => $studentId,
            'attendance_date' => $date,
            'status' => $entry['status'],
            'remarks' => $entry['remarks'],
            'marked_by' => (string) ($teacher['teacher_mobile'] ?? ''),
        ]);
    }
    $db->commit();
    mobileApiJson(['data' => ['saved' => count($seen), 'date' => $date]]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiDatabaseFailure($exception, 'teacher attendance');
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mobile API teacher attendance failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The attendance request could not be completed.']], 500);
}
