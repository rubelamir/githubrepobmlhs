<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$teacher = getAuthenticatedTeacher();
$kind = (string) ($_GET['kind'] ?? '');
$scope = $kind === 'student-birth' ? 'student' : ($kind === 'teacher-nid' ? 'teacher' : '');
$recordId = $scope !== '' ? requestRecordId($scope) : 0;
if ($recordId < 1 || $teacher === null) {
    http_response_code(404);
    exit;
}

$directory = '';
$filename = '';
$authorized = false;
if ($kind === 'student-birth') {
    $rows = fetchRows(
        $db,
        'SELECT id, cls, sections, session_year, birth_reg_pic
         FROM students_info WHERE id = :id LIMIT 1',
        ['id' => $recordId]
    );
    $student = $rows[0] ?? [];
    if ($student !== []) {
        $directory = 'birth_reg_pic';
        $filename = (string) ($student['birth_reg_pic'] ?? '');
        $authorized = canManageModule('admissions', $teacher)
            || canManageModule('students', $teacher)
            || classTeacherScopeAllows(
                classTeacherScope($db, $teacher, (string) ($student['session_year'] ?? '')),
                (int) ($student['cls'] ?? 0),
                (int) ($student['sections'] ?? 0)
            );
    }
} elseif ($kind === 'teacher-nid') {
    $rows = fetchRows(
        $db,
        'SELECT id, teacher_nid_pic FROM teacher_info WHERE id = :id LIMIT 1',
        ['id' => $recordId]
    );
    $staffRecord = $rows[0] ?? [];
    if ($staffRecord !== []) {
        $directory = 'teacher_nid_pic';
        $filename = (string) ($staffRecord['teacher_nid_pic'] ?? '');
        $authorized = (int) ($teacher['id'] ?? 0) === $recordId
            || canManageModule('teachers', $teacher);
    }
}

if (!$authorized || $filename === '') {
    http_response_code(404);
    exit;
}

$path = privateMediaFilePath($directory, $filename);
if ($path === null || !is_readable($path)) {
    http_response_code(404);
    exit;
}

$image = @getimagesize($path);
$mimeType = is_array($image) ? (string) ($image['mime'] ?? '') : '';
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="private-image.' . match ($mimeType) {
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'jpg',
} . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($path);
