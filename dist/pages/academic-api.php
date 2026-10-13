<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

header('Content-Type: application/json; charset=utf-8');

$db = getDatabaseConnection();
$type = (string) ($_GET['type'] ?? '');

if ($type === 'sections') {
    $classId = (int) ($_GET['class_id'] ?? 0);
    $forAttendance = (string) ($_GET['for'] ?? '') === 'attendance';
    $activeOnly = (string) ($_GET['active'] ?? '') === '1';
    $teacher = getAuthenticatedTeacher();
    $sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
    $rows = $forAttendance
        ? sectionsForAttendance($db, $classId, $teacher, $sessionYear)
        : schoolSections($db, $classId, $activeOnly);
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($type === 'subjects') {
    $classId = (int) ($_GET['class_id'] ?? 0);
    $sectionId = (int) ($_GET['section_id'] ?? 0);
    $rows = classSubjects($db, $classId, $sectionId);
    $forMarks = (string) ($_GET['for'] ?? '') === 'marks';
    $teacher = getAuthenticatedTeacher();
    if ($forMarks) {
        if ($teacher && !canViewAllMarksheets($teacher)) {
            $rows = filterSubjectsForTeacher($rows, (string) ($teacher['teacher_mobile'] ?? ''));
        }
    } elseif ($teacher && !canViewAllMarksheets($teacher) && !canAccessModule('subjects', $teacher)) {
        $rows = filterSubjectsForTeacher($rows, (string) ($teacher['teacher_mobile'] ?? ''));
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($type === 'classes') {
    $admissionOnly = (string) ($_GET['admission'] ?? '') === '1';
    echo json_encode($admissionOnly ? admissionClasses($db) : schoolClasses($db, true), JSON_UNESCAPED_UNICODE);
    exit;
}

$location = (string) ($_GET['location'] ?? '');
$parentId = filter_input(INPUT_GET, 'parent_id', FILTER_VALIDATE_INT);
$parents = [
    'district' => ['table' => 'districts', 'foreign_key' => 'division_id'],
    'upazila' => ['table' => 'upazilas', 'foreign_key' => 'district_id'],
];
if (isset($parents[$location]) && $parentId) {
    $foreign = $parents[$location]['foreign_key'];
    $table = $parents[$location]['table'];
    try {
        $query = $db->prepare("SELECT id, COALESCE(NULLIF(bn_name, ''), name) AS name FROM {$table} WHERE {$foreign} = :parent_id ORDER BY name");
        $query->execute(['parent_id' => $parentId]);
        echo json_encode($query->fetchAll(), JSON_UNESCAPED_UNICODE);
    } catch (PDOException $exception) {
        $alt = $foreign;
        try {
            $query = $db->prepare("SELECT id, COALESCE(bn_name, name) AS name FROM {$table} WHERE {$alt} = :parent_id ORDER BY id");
            $query->execute(['parent_id' => $parentId]);
            echo json_encode($query->fetchAll(), JSON_UNESCAPED_UNICODE);
        } catch (PDOException $ignored) {
            echo json_encode([]);
        }
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
