<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $db = getDatabaseConnection();
    $rows = fetchRows(
        $db,
        'SELECT id, files_text AS title, files_file AS file, files_creation_time AS published_at
         FROM files
         WHERE files_status = 1 AND files_file IS NOT NULL AND files_file <> \'\'
         ORDER BY ' . downloadListOrderSql($db)
         LIMIT 100'
    );
    foreach ($rows as &$row) {
        $file = basename((string) ($row['file'] ?? ''));
        $row['file_url'] = $file !== '' ? publicSiteUrl('files_file/' . $file) : null;
        $row['file_type'] = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        unset($row['file']);
    }
    unset($row);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public downloads');
}

mobileApiJson(['data' => ['downloads' => $rows, 'generated_at' => gmdate(DATE_ATOM)]]);
