<?php
declare(strict_types=1);

require_once __DIR__ . '/deployment-config.php';

function databaseBackupKeepDays(): int
{
    $env = getenv('ADMINLTE_BACKUP_KEEP_DAYS');
    $days = is_string($env) && ctype_digit($env) ? (int) $env : 30;

    return max(1, $days);
}

function databaseBackupDirectory(): string
{
    $envDir = getenv('ADMINLTE_BACKUP_DIR');
    if (is_string($envDir) && trim($envDir) !== '') {
        $backupDir = trim($envDir);
    } else {
        $private = deploymentPrivateConfig();
        $configured = $private['backup_path'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            $backupDir = trim($configured);
        } else {
            $projectRoot = dirname(__DIR__);
            $backupDir = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                ? 'C:' . DIRECTORY_SEPARATOR . 'xampp' . DIRECTORY_SEPARATOR . 'bmlhs-db-backups'
                : dirname($projectRoot) . DIRECTORY_SEPARATOR . 'bmlhs-db-backups';
        }
    }

    assertValidBackupDirectory($backupDir);
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Unable to create backup directory.');
    }
    $resolved = realpath($backupDir);
    if ($resolved === false) {
        throw new RuntimeException('Backup directory is unavailable.');
    }
    assertBackupDirOutsideDocumentRoot($resolved);

    return $resolved;
}

function assertValidBackupDirectory(string $backupDir): void
{
    $normalizedDir = str_replace('\\', '/', $backupDir);
    if ($backupDir === '' || preg_match('/[<>"|?*]/', $normalizedDir) === 1) {
        throw new RuntimeException('Invalid backup directory.');
    }
    if (str_contains($normalizedDir, ':') && preg_match('/^[A-Za-z]:\//', $normalizedDir) !== 1) {
        throw new RuntimeException('Invalid backup directory.');
    }
}

function assertBackupDirOutsideDocumentRoot(string $resolvedBackupDir): void
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot === false) {
        return;
    }
    $doc = rtrim(str_replace('\\', '/', $documentRoot), '/');
    $bak = str_replace('\\', '/', $resolvedBackupDir);
    if (strcasecmp($bak, $doc) === 0 || strncasecmp($bak, $doc . '/', strlen($doc) + 1) === 0) {
        throw new RuntimeException('Refusing to store backups inside the web document root.');
    }
}

function databaseBackupDatabaseName(): string
{
    return deploymentDatabaseSettings()['name'];
}

/**
 * @return list<array{name:string,path:string,bytes:int,mtime:int,age_days:int}>
 */
function listDatabaseBackups(): array
{
    $dir = databaseBackupDirectory();
    $name = databaseBackupDatabaseName();
    $files = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . $name . '_*.sql*') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $basename = basename($path);
        if (!isSafeBackupBasename($basename, $name)) {
            continue;
        }
        $mtime = (int) (filemtime($path) ?: 0);
        $files[] = [
            'name' => $basename,
            'path' => $path,
            'bytes' => (int) (filesize($path) ?: 0),
            'mtime' => $mtime,
            'age_days' => $mtime > 0 ? (int) floor(max(0, time() - $mtime) / 86400) : 0,
        ];
    }
    usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    return $files;
}

function isSafeBackupBasename(string $basename, ?string $databaseName = null): bool
{
    $databaseName ??= databaseBackupDatabaseName();
    if ($basename === '' || str_contains($basename, '..') || str_contains($basename, '/') || str_contains($basename, '\\')) {
        return false;
    }

    return (bool) preg_match(
        '/^' . preg_quote($databaseName, '/') . '_\d{8}_\d{6}\.sql(\.gz)?$/',
        $basename
    );
}

function resolveDatabaseBackupPath(string $basename): ?string
{
    if (!isSafeBackupBasename($basename)) {
        return null;
    }
    $dir = databaseBackupDirectory();
    $path = $dir . DIRECTORY_SEPARATOR . $basename;
    $resolvedDir = realpath($dir);
    $resolvedFile = realpath($path);
    if ($resolvedDir === false || $resolvedFile === false || !is_file($resolvedFile)) {
        return null;
    }
    if (!str_starts_with($resolvedFile, $resolvedDir . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $resolvedFile;
}

function signedBackupFileRef(string $basename): string
{
    $basename = basename(str_replace(["\0", '\\'], ['', '/'], $basename));
    $mac = substr(hash_hmac('sha256', 'backup.' . $basename, appSigningKey(), true), 0, 16);

    return rtrim(strtr(base64_encode($basename . "\n" . $mac), '+/', '-_'), '=');
}

function backupBasenameFromSignedRef(?string $ref): string
{
    $ref = trim((string) $ref);
    if ($ref === '') {
        return '';
    }
    $padded = strtr($ref, '-_', '+/');
    $pad = strlen($padded) % 4;
    if ($pad > 0) {
        $padded .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($padded, true);
    if ($decoded === false || !str_contains($decoded, "\n")) {
        return '';
    }
    [$basename, $mac] = explode("\n", $decoded, 2);
    $basename = basename(str_replace(["\0", '\\'], ['', '/'], $basename));
    $expected = substr(hash_hmac('sha256', 'backup.' . $basename, appSigningKey(), true), 0, 16);
    if (!hash_equals($expected, $mac) || !isSafeBackupBasename($basename)) {
        return '';
    }

    return $basename;
}

function pruneDatabaseBackups(?int $keepDays = null): int
{
    $keepDays ??= databaseBackupKeepDays();
    $dir = databaseBackupDirectory();
    $name = databaseBackupDatabaseName();
    $cutoff = time() - ($keepDays * 86400);
    $removed = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . $name . '_*.sql*') ?: [] as $file) {
        if (!is_file($file) || !isSafeBackupBasename(basename($file), $name)) {
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

function todaysDatabaseBackupExists(): bool
{
    $prefix = databaseBackupDatabaseName() . '_' . date('Ymd') . '_';
    foreach (listDatabaseBackups() as $file) {
        if (str_starts_with($file['name'], $prefix)) {
            return true;
        }
    }

    return false;
}

function databaseBackupLockPath(): string
{
    return databaseBackupDirectory() . DIRECTORY_SEPARATOR . '.backup.lock';
}

/**
 * @return array{ok:bool,file?:string,bytes?:int,pruned?:int,detail?:string}
 */
function createDatabaseBackup(bool $gzip = true): array
{
    $db = deploymentDatabaseSettings();
    $dir = databaseBackupDirectory();
    $stamp = date('Ymd_His');
    $sqlPath = $dir . DIRECTORY_SEPARATOR . $db['name'] . '_' . $stamp . '.sql';
    $lock = databaseBackupLockPath();
    $lockHandle = @fopen($lock, 'c+');
    if ($lockHandle === false) {
        return ['ok' => false, 'detail' => 'Unable to lock backup directory.'];
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);

        return ['ok' => false, 'detail' => 'A backup is already running.'];
    }

    try {
        $mysqldump = canRunShellBackupTools() ? findMysqlDumpBinary() : null;
        if ($mysqldump !== null) {
            runMysqlDumpBinary($mysqldump, $db['host'], $db['username'], $db['password'], $db['name'], $sqlPath);
        } else {
            phpFallbackDatabaseDump($db['host'], $db['username'], $db['password'], $db['name'], $sqlPath);
        }
        if (!is_file($sqlPath) || filesize($sqlPath) < 64) {
            throw new RuntimeException('Backup file was not created or is empty.');
        }

        $finalPath = $sqlPath;
        if ($gzip && function_exists('gzopen')) {
            $gzPath = $sqlPath . '.gz';
            gzipBackupFile($sqlPath, $gzPath);
            @unlink($sqlPath);
            $finalPath = $gzPath;
        }

        $pruned = pruneDatabaseBackups();

        return [
            'ok' => true,
            'file' => basename($finalPath),
            'bytes' => (int) (filesize($finalPath) ?: 0),
            'pruned' => $pruned,
        ];
    } catch (Throwable $exception) {
        if (is_file($sqlPath)) {
            @unlink($sqlPath);
        }

        return ['ok' => false, 'detail' => $exception->getMessage()];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/**
 * Runs the 2:00 AM daily backup if due (idempotent for the calendar day).
 *
 * @return array{ran:bool,result?:array{ok:bool,file?:string,bytes?:int,pruned?:int,detail?:string}}
 */
function databaseBackupMaybeRunScheduled(bool $forceAfterHour = true): array
{
    pruneDatabaseBackups();
    if (todaysDatabaseBackupExists()) {
        return ['ran' => false];
    }
    if ($forceAfterHour && (int) date('G') < 2) {
        return ['ran' => false];
    }

    return ['ran' => true, 'result' => createDatabaseBackup(true)];
}

function phpFunctionEnabled(string $functionName): bool
{
    if (!function_exists($functionName)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return !in_array($functionName, $disabled, true);
}

function canRunShellBackupTools(): bool
{
    return phpFunctionEnabled('proc_open');
}

function findMysqlDumpBinary(): ?string
{
    if (!canRunShellBackupTools()) {
        return null;
    }

    $candidates = [];
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump';
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    if (!phpFunctionEnabled('exec')) {
        return null;
    }

    $output = [];
    $code = 0;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        @exec('where mysqldump 2>NUL', $output, $code);
    } else {
        @exec('command -v mysqldump', $output, $code);
    }
    if ($code === 0 && isset($output[0]) && is_file(trim($output[0]))) {
        return trim($output[0]);
    }

    return null;
}

function runMysqlDumpBinary(string $binary, string $host, string $user, string $password, string $database, string $sqlPath): void
{
    if (!canRunShellBackupTools()) {
        throw new RuntimeException('Shell backup tools are disabled on this server.');
    }

    $command = [
        $binary,
        '--host=' . $host,
        '--user=' . $user,
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--default-character-set=utf8mb4',
        '--result-file=' . $sqlPath,
        $database,
    ];
    if ($password !== '') {
        array_splice($command, 3, 0, '--password=' . $password);
    }

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start mysqldump.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        $detail = trim($stderr !== '' ? $stderr : $stdout);
        throw new RuntimeException($detail !== '' ? $detail : 'mysqldump exited with code ' . $code);
    }
}

function phpFallbackDatabaseDump(string $host, string $user, string $password, string $database, string $sqlPath): void
{
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $handle = fopen($sqlPath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open backup file for writing.');
    }

    $write = static function (string $chunk) use ($handle): void {
        if (fwrite($handle, $chunk) === false) {
            throw new RuntimeException('Unable to write backup file.');
        }
    };

    $write("-- BMLHS PHP fallback dump\n");
    $write('-- Date: ' . date('c') . "\n");
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = (string) $tableRow[0];
        $safe = str_replace('`', '``', $table);
        $create = $pdo->query('SHOW CREATE TABLE `' . $safe . '`')->fetch(PDO::FETCH_NUM);
        $write('DROP TABLE IF EXISTS `' . $safe . "`;\n");
        $write((string) ($create[1] ?? '') . ";\n\n");

        $rows = $pdo->query('SELECT * FROM `' . $safe . '`');
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = [];
            $values = [];
            foreach ($row as $column => $value) {
                $columns[] = '`' . str_replace('`', '``', (string) $column) . '`';
                $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
            }
            $write(
                'INSERT INTO `' . $safe . '` (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', $values) . ");\n"
            );
        }
        $write("\n");
    }

    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
}

function gzipBackupFile(string $source, string $destination): void
{
    $input = fopen($source, 'rb');
    $output = gzopen($destination, 'wb9');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        throw new RuntimeException('Unable to compress backup file.');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        gzwrite($output, $chunk);
    }
    fclose($input);
    gzclose($output);
    if (!is_file($destination) || filesize($destination) < 1) {
        throw new RuntimeException('Compressed backup file is empty.');
    }
}

function formatBackupBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 2) . ' MB';
}

function formatBackupCreatedAt(int $mtime): string
{
    if ($mtime <= 0) {
        return '—';
    }

    return (new DateTimeImmutable('@' . $mtime))
        ->setTimezone(new DateTimeZone('Asia/Dhaka'))
        ->format('d/m/Y \a\t h:i:s A');
}

function databaseBackupCronSecret(): string
{
    $private = deploymentPrivateConfig();
    $configured = $private['backup_cron_key'] ?? null;
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }
    if (function_exists('appSigningKey')) {
        return hash('sha256', 'backup-cron|' . appSigningKey());
    }

    return hash('sha256', 'backup-cron|' . ($private['app_key'] ?? 'bmlhs'));
}
