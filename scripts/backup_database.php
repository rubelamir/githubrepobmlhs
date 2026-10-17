<?php
declare(strict_types=1);

/**
 * Database backup for BMLHS (CLI only).
 *
 * Usage:
 *   php scripts/backup_database.php
 *   php scripts/backup_database.php --keep-days=30 --dir="C:\xampp\bmlhs-db-backups"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/shared/security.php';
require_once dirname(__DIR__) . '/shared/backup.php';

$options = getopt('', ['dir::', 'keep-days::', 'no-gzip', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/backup_database.php [--dir=PATH] [--keep-days=N] [--no-gzip]\n");
    exit(0);
}

if (isset($options['dir']) && is_string($options['dir']) && trim($options['dir']) !== '') {
    putenv('ADMINLTE_BACKUP_DIR=' . trim($options['dir']));
}
if (isset($options['keep-days']) && is_string($options['keep-days']) && ctype_digit($options['keep-days'])) {
    putenv('ADMINLTE_BACKUP_KEEP_DAYS=' . $options['keep-days']);
}

$gzip = !isset($options['no-gzip']);
$name = databaseBackupDatabaseName();
$dir = databaseBackupDirectory();

fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Starting backup of `{$name}` to {$dir}\n");

$result = createDatabaseBackup($gzip);
if (!$result['ok']) {
    fwrite(STDERR, 'Backup failed: ' . ($result['detail'] ?? 'unknown error') . "\n");
    exit(1);
}

$bytes = (int) ($result['bytes'] ?? 0);
fwrite(
    STDOUT,
    '[' . date('Y-m-d H:i:s') . '] Backup OK: ' . ($result['file'] ?? '') . ' (' . formatBackupBytes($bytes) . ")\n"
);
$pruned = (int) ($result['pruned'] ?? 0);
if ($pruned > 0) {
    fwrite(STDOUT, "Removed {$pruned} backup(s) older than " . databaseBackupKeepDays() . " day(s).\n");
}

exit(0);
