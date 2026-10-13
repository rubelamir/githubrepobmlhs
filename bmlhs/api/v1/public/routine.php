<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $db = getDatabaseConnection();
    $settings = routineSettings($db);
    if ((int) ($settings['is_published'] ?? 1) !== 1) {
        mobileApiJson(['error' => ['code' => 'not_published', 'message' => 'Routine is not currently published.']], 404);
    }

    $classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
    $sectionId = filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT);
    if (!is_int($classId) || $classId < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_filter', 'message' => 'A valid class_id is required.']], 422);
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
    $sectionId = is_int($sectionId) && $sectionId > 0 ? $sectionId : 0;
    $sections = schoolSections($db, $classId, true);
    if ($sectionId > 0 && !in_array($sectionId, array_map(static fn (array $row): int => (int) $row['id'], $sections), true)) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Section was not found for the selected class.']], 404);
    }
    if (!tableExists($db, 'class_routine')) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Routine is not available.']], 404);
    }

    $params = ['class_id' => $classId];
    $where = 'class_id = :class_id';
    if ($sectionId > 0) {
        $where .= ' AND section_id IN (:section_id, 0)';
        $params['section_id'] = $sectionId;
    }
    $rows = fetchRows(
        $db,
        "SELECT day_of_week, period_no, subject_name, teacher_mobile, room
         FROM class_routine
         WHERE {$where} AND TRIM(subject_name) <> ''
         ORDER BY day_of_week ASC, period_no ASC, id ASC",
        $params
    );
    $teacherMap = teacherNameMap($db);
    foreach ($rows as &$row) {
        $row['day_of_week'] = (int) $row['day_of_week'];
        $row['period_no'] = (int) $row['period_no'];
        $row['teacher_name'] = teacherDisplayName($teacherMap, (string) ($row['teacher_mobile'] ?? ''));
        unset($row['teacher_mobile']);
    }
    unset($row);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public routine');
}

mobileApiJson([
    'data' => [
        'class_id' => $classId,
        'section_id' => $sectionId,
        'class_name' => classLabel($db, $classId),
        'section_name' => $sectionId > 0 ? sectionLabel($db, $sectionId) : '',
        'session_label' => (string) ($settings['session_label'] ?? ''),
        'periods' => array_values(schoolPeriodMeta($db)),
        'days' => weekDays(),
        'routine' => $rows,
        'assembly' => [
            'label' => (string) ($settings['assembly_label'] ?? ''),
            'time' => (string) ($settings['assembly_time'] ?? ''),
        ],
        'break' => [
            'label' => (string) ($settings['break_label'] ?? ''),
            'time' => (string) ($settings['break_time'] ?? ''),
        ],
    ],
]);
