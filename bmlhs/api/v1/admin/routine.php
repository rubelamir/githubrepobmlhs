<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileAdminRoutineError(string $code, string $message, int $status = 422): never
{
    mobileApiJson(['error' => ['code' => $code, 'message' => $message]], $status);
}

function mobileAdminRoutineClass(PDO $db, int $classId, int $sectionId): array
{
    $class = null;
    foreach (schoolClasses($db) as $row) {
        if ((int) $row['id'] === $classId) {
            $class = $row;
            break;
        }
    }
    if ($class === null) {
        mobileAdminRoutineError('invalid_class', 'The selected class is unavailable.');
    }

    $sections = schoolSections($db, $classId);
    if ($sectionId > 0 && !in_array(
        $sectionId,
        array_map(static fn (array $row): int => (int) $row['id'], $sections),
        true
    )) {
        mobileAdminRoutineError('invalid_section', 'The selected section is not valid for this class.');
    }

    return [$class, $sections];
}

function mobileAdminRoutineString(mixed $value, string $field, int $maxLength, bool $required = false): string
{
    if (!is_string($value)) {
        mobileAdminRoutineError('invalid_routine', ucfirst($field) . ' must be text.');
    }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $maxLength) {
        mobileAdminRoutineError('invalid_routine', ucfirst($field) . ' is missing or too long.');
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
    if ($identity['account_type'] !== 'staff' || !canAccessModule('routine', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage class routines.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $classId = filter_var($_GET['class_id'] ?? null, FILTER_VALIDATE_INT);
        $sectionId = filter_var($_GET['section_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!is_int($classId) || $classId < 1 || !is_int($sectionId) || $sectionId < 0) {
            mobileAdminRoutineError('invalid_filter', 'A valid class_id and non-negative section_id are required.');
        }
        [$class, $sections] = mobileAdminRoutineClass($db, $classId, $sectionId);
        $periods = schoolPeriodMeta($db);
        $days = weekDays();
        $grid = classRoutineGrid($db, $classId, $sectionId);
        $daysData = [];
        foreach ($days as $day => $label) {
            $dayRows = [];
            foreach (($grid[$day] ?? []) as $periodNo => $entries) {
                foreach ($entries as $entry) {
                    $dayRows[] = [
                        'period_no' => (int) $periodNo,
                        'subject' => (string) ($entry['subject_name'] ?? ''),
                        'teacher_mobile' => (string) ($entry['teacher_mobile'] ?? ''),
                        'room' => (string) ($entry['room'] ?? ''),
                    ];
                }
            }
            $daysData[] = ['day' => (int) $day, 'label' => $label, 'entries' => $dayRows];
        }

        $classLabel = trim((string) ($class['class_name_ban'] ?? '')) ?: (string) ($class['class_name'] ?? '');
        mobileApiJson(['data' => [
            'class' => ['id' => $classId, 'name' => $classLabel],
            'section_id' => $sectionId,
            'sections' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => trim((string) ($row['section_name_ban'] ?? '')) ?: (string) ($row['section_name'] ?? ''),
            ], $sections),
            'days' => $daysData,
            'periods' => array_map(static fn (array $row): array => [
                'period_no' => (int) $row['no'],
                'label' => (string) $row['label'],
                'time' => (string) $row['time'],
            ], array_values($periods)),
            'settings' => routineSettings($db),
        ]]);
    }

    $payload = mobileApiReadJson(262144);
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['save_day', 'clear_day', 'copy_day', 'copy_week', 'save_settings'], true)) {
        mobileAdminRoutineError('invalid_action', 'Action must be save_day, clear_day, copy_day, copy_week or save_settings.');
    }

    if ($action === 'save_settings') {
        $input = $payload['settings'] ?? null;
        if (!is_array($input)) {
            mobileAdminRoutineError('invalid_settings', 'Provide a settings object.');
        }
        $current = routineSettings($db);
        $values = [];
        foreach ([
            'assembly_label' => 80,
            'assembly_time' => 50,
            'break_label' => 80,
            'break_time' => 50,
            'session_label' => 40,
            'footer_note' => 65535,
        ] as $field => $maxLength) {
            $values[$field] = array_key_exists($field, $input)
                ? mobileAdminRoutineString($input[$field], str_replace('_', ' ', $field), $maxLength)
                : (string) ($current[$field] ?? '');
        }
        $published = $input['is_published'] ?? $current['is_published'];
        if (!in_array($published, [true, false, 0, 1, '0', '1'], true)) {
            mobileAdminRoutineError('invalid_settings', 'is_published must be a boolean or 0/1.');
        }
        $values['is_published'] = in_array($published, [true, 1, '1'], true) ? 1 : 0;
        $db->beginTransaction();
        saveRoutineSettings($db, $values);
        $db->commit();
        mobileApiJson(['data' => ['status' => 'saved', 'settings' => routineSettings($db)]]);
    }

    $classId = filter_var($payload['class_id'] ?? null, FILTER_VALIDATE_INT);
    $sectionId = filter_var($payload['section_id'] ?? 0, FILTER_VALIDATE_INT);
    $day = filter_var($payload['day'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($classId) || $classId < 1 || !is_int($sectionId) || $sectionId < 0
        || !is_int($day) || !isset(weekDays()[$day])
    ) {
        mobileAdminRoutineError('invalid_filter', 'A valid class_id, section_id and weekday (0-4) are required.');
    }
    mobileAdminRoutineClass($db, $classId, $sectionId);

    if ($action === 'clear_day') {
        $db->beginTransaction();
        clearClassRoutineDay($db, $classId, $sectionId, $day);
        $db->commit();
        mobileApiJson(['data' => ['status' => 'cleared', 'class_id' => $classId, 'section_id' => $sectionId, 'day' => $day]]);
    }

    if ($action === 'copy_day') {
        $toDay = filter_var($payload['to_day'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($toDay) || !isset(weekDays()[$toDay]) || $toDay === $day) {
            mobileAdminRoutineError('invalid_day', 'to_day must be a different valid weekday (0-4).');
        }
        $db->beginTransaction();
        $copied = copyClassRoutineDay($db, $classId, $sectionId, $day, $toDay);
        $db->commit();
        mobileApiJson(['data' => ['status' => 'copied', 'copied' => $copied, 'from_day' => $day, 'to_day' => $toDay]]);
    }
    if ($action === 'copy_week') {
        $toClassId = filter_var($payload['to_class_id'] ?? null, FILTER_VALIDATE_INT);
        $toSectionId = filter_var($payload['to_section_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!is_int($toClassId) || $toClassId < 1 || !is_int($toSectionId) || $toSectionId < 0) {
            mobileAdminRoutineError('invalid_destination', 'A valid destination class_id and section_id are required.');
        }
        mobileAdminRoutineClass($db, $toClassId, $toSectionId);
        $db->beginTransaction();
        $copied = copyClassRoutineWeek($db, $classId, $sectionId, $toClassId, $toSectionId);
        $db->commit();
        mobileApiJson(['data' => [
            'status' => 'copied',
            'copied' => $copied,
            'from' => ['class_id' => $classId, 'section_id' => $sectionId],
            'to' => ['class_id' => $toClassId, 'section_id' => $toSectionId],
        ]]);
    }

    $rawEntries = $payload['entries'] ?? null;
    $periodMeta = schoolPeriodMeta($db);
    if (!is_array($rawEntries) || count($rawEntries) > max(1, count($periodMeta)) * 12) {
        mobileAdminRoutineError('invalid_routine', 'Provide a valid list of routine entries.');
    }
    $subjects = classSubjects($db, $classId, $sectionId);
    $teachers = schoolTeachers($db);
    $periods = [];
    $subjectValues = [];
    $teacherValues = [];
    $roomValues = [];
    $seenEntries = [];
    foreach ($rawEntries as $entry) {
        if (!is_array($entry)) {
            mobileAdminRoutineError('invalid_routine', 'Each routine entry must be an object.');
        }
        $periodNo = filter_var($entry['period_no'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($periodNo) || !isset($periodMeta[$periodNo])) {
            mobileAdminRoutineError('invalid_routine', 'Each entry must use an active period_no.');
        }
        $subjectName = mobileAdminRoutineString($entry['subject'] ?? null, 'subject', 150, true);
        $teacherMobile = mobileAdminRoutineString($entry['teacher_mobile'] ?? '', 'teacher mobile', 50);
        $room = mobileAdminRoutineString($entry['room'] ?? '', 'room', 50);
        $subject = null;
        foreach ($subjects as $candidate) {
            $label = trim((string) ($candidate['subject_name_ban'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($candidate['subject_name'] ?? ''));
            }
            if ($label === $subjectName) {
                $subject = $candidate;
                break;
            }
        }
        if ($subject === null) {
            mobileAdminRoutineError('invalid_subject', 'The subject is not valid for this class and section.');
        }
        if ($teacherMobile !== '') {
            $teacherExists = false;
            foreach ($teachers as $candidate) {
                if (teacherMobilesMatch((string) ($candidate['teacher_mobile'] ?? ''), $teacherMobile)) {
                    $teacherExists = true;
                    break;
                }
            }
            if (!$teacherExists) {
                mobileAdminRoutineError('invalid_teacher', 'The selected teacher is unavailable.');
            }
        }
        $key = $periodNo . '|' . $subjectName . '|' . teacherMobileKey($teacherMobile);
        if (isset($seenEntries[$key])) {
            mobileAdminRoutineError('duplicate_entry', 'A duplicate subject and teacher entry was provided for one period.');
        }
        $seenEntries[$key] = true;
        $periods[$periodNo] = (string) $periodMeta[$periodNo]['time'];
        $index = count($subjectValues[$periodNo] ?? []);
        $subjectValues[$periodNo][$index] = $subjectName;
        $teacherValues[$periodNo][$index] = $teacherMobile;
        $roomValues[$periodNo][$index] = $room;
    }
    foreach ($periodMeta as $periodNo => $meta) {
        $periods[(int) $periodNo] = (string) $meta['time'];
    }

    $conflicts = saveClassRoutineDay($db, $classId, $sectionId, $day, $periods, $subjectValues, $teacherValues, $roomValues);
    mobileApiJson(['data' => [
        'status' => 'saved',
        'saved_entries' => count($rawEntries),
        'class_id' => $classId,
        'section_id' => $sectionId,
        'day' => $day,
        'conflicts' => array_map(static fn (array $row): array => [
            'period_no' => (int) $row['period'],
            'teacher_mobile' => (string) $row['teacher'],
            'class_id' => (int) $row['class_id'],
            'section_id' => (int) $row['section_id'],
            'subject' => (string) $row['subject'],
        ], $conflicts),
    ]]);
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiDatabaseFailure($exception, 'admin routine');
} catch (RuntimeException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiJson(['error' => ['code' => 'invalid_routine', 'message' => $exception->getMessage()]], 422);
} catch (Throwable $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mobile API admin routine failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The routine request could not be completed.']], 500);
}
