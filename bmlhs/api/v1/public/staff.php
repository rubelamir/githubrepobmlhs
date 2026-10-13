<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $db = getDatabaseConnection();
    $staff = fetchRows(
        $db,
        'SELECT id, teacher_name AS name, teacher_subject AS subject,
                teacher_designation AS designation, teacher_pic AS photo
         FROM teacher_info
         WHERE teacher_status = 1
         ORDER BY ' . teacherListOrderSql($db)
    );
    foreach ($staff as &$person) {
        $photo = trim((string) ($person['photo'] ?? ''));
        $person['photo_url'] = $photo !== '' ? schoolMediaUrl($photo, 'teacher_pic') : null;
        unset($person['photo']);
    }
    unset($person);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public staff');
}

mobileApiJson(['data' => ['staff' => $staff]]);
