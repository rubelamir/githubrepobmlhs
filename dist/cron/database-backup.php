<?php
declare(strict_types=1);

/**
 * Hosting cron endpoint for daily 2:00 AM backups.
 * Example:
 *   curl "https://example.com/dist/cron/database-backup.php?key=YOUR_KEY"
 */

require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/backup.php';
require_once dirname(__DIR__, 2) . '/shared/school.php';

header('Content-Type: application/json; charset=utf-8');

$key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$expected = databaseBackupCronSecret();
if ($key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hour = (int) date('G');
$minute = (int) date('i');
// Allow the cron window 02:00–02:59, plus manual force=1 for testing.
$force = (string) ($_GET['force'] ?? '') === '1';
if (!$force && ($hour !== 2)) {
    echo json_encode([
        'ok' => true,
        'ran' => false,
        'reason' => 'outside_schedule_window',
        'server_time' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (todaysDatabaseBackupExists() && !$force) {
    pruneDatabaseBackups();
    echo json_encode([
        'ok' => true,
        'ran' => false,
        'reason' => 'already_backed_up_today',
        'server_time' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = createDatabaseBackup(true);
http_response_code(!empty($result['ok']) ? 200 : 500);
echo json_encode([
    'ok' => !empty($result['ok']),
    'ran' => true,
    'file' => $result['file'] ?? null,
    'bytes' => $result['bytes'] ?? null,
    'pruned' => $result['pruned'] ?? 0,
    'detail' => $result['detail'] ?? null,
    'server_time' => date('c'),
], JSON_UNESCAPED_UNICODE);
