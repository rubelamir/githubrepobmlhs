<?php
declare(strict_types=1);

/**
 * Shared password, CSRF, and path-safety helpers for dist + bmlhs.
 */

function verifyStoredPassword(string $password, string $storedPassword): bool
{
    if ($storedPassword !== '' && password_verify($password, $storedPassword)) {
        return true;
    }

    // Legacy plaintext values are accepted once so existing users can migrate.
    return $storedPassword !== '' && hash_equals($storedPassword, $password);
}

function isLegacyPlaintextPassword(string $storedPassword): bool
{
    if ($storedPassword === '') {
        return false;
    }

    // PHP 8 reports `algo` as null for anything that is not a recognised hash;
    // PHP 7 reported 0. Both mean the value is still stored in the clear.
    $algo = password_get_info($storedPassword)['algo'];

    return $algo === null || $algo === 0;
}

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function getRequestCsrfToken(): ?string
{
    $fromPost = $_POST['csrf_token'] ?? null;
    if (is_string($fromPost) && $fromPost !== '') {
        return $fromPost;
    }

    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null;
    if (is_string($header) && $header !== '') {
        return $header;
    }

    return null;
}

function verifyCsrfToken(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $expected = $_SESSION['_csrf_token'] ?? null;
    if (!is_string($expected) || $expected === '') {
        return false;
    }
    $token ??= getRequestCsrfToken();
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

function requireValidCsrf(): void
{
    if (!verifyCsrfToken()) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

/**
 * Unlink a file by basename only, confined to $directory.
 */
function safeUnlinkUnderDirectory(string $directory, ?string $filename): bool
{
    if ($filename === null || $filename === '') {
        return false;
    }

    $basename = basename(str_replace(["\0", '\\'], ['', '/'], $filename));
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return false;
    }

    $baseDir = realpath($directory);
    if ($baseDir === false || !is_dir($baseDir)) {
        return false;
    }

    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $basename;
    if (!is_file($fullPath)) {
        return false;
    }

    $resolved = realpath($fullPath);
    if ($resolved === false || !str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)) {
        return false;
    }

    return @unlink($resolved);
}

/**
 * File-based login rate limiting keyed by IP + identifier (e.g. mobile).
 * Locks after $maxAttempts failures for $lockSeconds (default 5 / 5 minutes).
 */
function loginClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

function loginRateLimitBucket(string $scope, string $identifier): string
{
    return hash(
        'sha256',
        strtolower($scope) . '|' . loginClientIp() . '|' . strtolower(trim($identifier))
    );
}

function loginRateLimitDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adminlte_login_rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

function loginRateLimitPath(string $bucket): string
{
    return loginRateLimitDir() . DIRECTORY_SEPARATOR . $bucket . '.json';
}

/**
 * @return array{failures:int,locked_until:int}
 */
function readLoginRateLimit(string $bucket): array
{
    $path = loginRateLimitPath($bucket);
    if (!is_file($path)) {
        return ['failures' => 0, 'locked_until' => 0];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['failures' => 0, 'locked_until' => 0];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['failures' => 0, 'locked_until' => 0];
    }

    return [
        'failures' => (int) ($data['failures'] ?? 0),
        'locked_until' => (int) ($data['locked_until'] ?? 0),
    ];
}

/**
 * @param array{failures?:int,locked_until?:int} $data
 */
function writeLoginRateLimit(string $bucket, array $data): void
{
    @file_put_contents(
        loginRateLimitPath($bucket),
        json_encode([
            'failures' => (int) ($data['failures'] ?? 0),
            'locked_until' => (int) ($data['locked_until'] ?? 0),
        ]),
        LOCK_EX
    );
}

function isLoginRateLimited(string $scope, string $identifier, int $maxAttempts = 5, int $lockSeconds = 300): bool
{
    $bucket = loginRateLimitBucket($scope, $identifier);
    $data = readLoginRateLimit($bucket);
    $now = time();

    if ($data['locked_until'] > $now) {
        return true;
    }

    if ($data['locked_until'] > 0 && $data['locked_until'] <= $now) {
        writeLoginRateLimit($bucket, ['failures' => 0, 'locked_until' => 0]);
    }

    return false;
}

function recordLoginFailure(string $scope, string $identifier, int $maxAttempts = 5, int $lockSeconds = 300): void
{
    $bucket = loginRateLimitBucket($scope, $identifier);
    $data = readLoginRateLimit($bucket);
    $now = time();

    if ($data['locked_until'] > $now) {
        return;
    }

    if ($data['locked_until'] > 0 && $data['locked_until'] <= $now) {
        $data = ['failures' => 0, 'locked_until' => 0];
    }

    $data['failures'] = $data['failures'] + 1;
    if ($data['failures'] >= $maxAttempts) {
        $data['locked_until'] = $now + $lockSeconds;
        $data['failures'] = 0;
    }

    writeLoginRateLimit($bucket, $data);
}

function clearLoginFailures(string $scope, string $identifier): void
{
    $path = loginRateLimitPath(loginRateLimitBucket($scope, $identifier));
    if (is_file($path)) {
        @unlink($path);
    }
}

function loginRateLimitSecondsRemaining(string $scope, string $identifier): int
{
    $data = readLoginRateLimit(loginRateLimitBucket($scope, $identifier));

    return max(0, $data['locked_until'] - time());
}

function loginRateLimitMinutesRemaining(string $scope, string $identifier): int
{
    return max(1, (int) ceil(loginRateLimitSecondsRemaining($scope, $identifier) / 60));
}

function loginRateLimitMessage(string $scope, string $identifier): string
{
    $minutes = loginRateLimitMinutesRemaining($scope, $identifier);

    return "Too many failed login attempts. Please try again in {$minutes} minute(s).";
}

function configureSessionCookie(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => (string) ($params['domain'] ?? ''),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    configureSessionCookie();
    if (!headers_sent()) {
        session_start();
    }
}

function appSigningKey(): string
{
    static $key = null;
    if (is_string($key) && $key !== '') {
        return $key;
    }

    $env = getenv('ADMINLTE_APP_SECRET');
    if (is_string($env) && strlen($env) >= 16) {
        $key = $env;
        return $key;
    }

    if (!function_exists('deploymentPrivateConfig')) {
        require_once __DIR__ . '/deployment-config.php';
    }

    $privateConfig = deploymentPrivateConfig();
    $configured = $privateConfig['app_secret'] ?? '';
    if (is_string($configured) && strlen($configured) >= 16) {
        $key = $configured;
        return $key;
    }

    $secretDirectory = dirname(deploymentPrivateConfigPath());
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $resolvedDirectory = realpath($secretDirectory);
    if ($documentRoot !== false && $resolvedDirectory !== false) {
        $root = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $directory = str_replace('\\', '/', $resolvedDirectory);
        if (strcasecmp($directory, $root) === 0 || strncasecmp($directory, $root . '/', strlen($root) + 1) === 0) {
            $secretDirectory = dirname($documentRoot);
        }
    }
    $candidates = [$secretDirectory, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache'];
    foreach ($candidates as $directory) {
        $secretPath = $directory . DIRECTORY_SEPARATOR . 'bmlhs-app-secret';
        if (!is_file($secretPath)) {
            continue;
        }
        $stored = trim((string) file_get_contents($secretPath));
        if (strlen($stored) >= 32) {
            $key = $stored;
            return $key;
        }
    }

    $generated = bin2hex(random_bytes(32));
    foreach ($candidates as $directory) {
        if (!is_dir($directory) || !is_writable($directory)) {
            continue;
        }
        $secretPath = $directory . DIRECTORY_SEPARATOR . 'bmlhs-app-secret';
        if (file_put_contents($secretPath, $generated . PHP_EOL, LOCK_EX) === false) {
            continue;
        }
        @chmod($secretPath, 0600);
        $key = $generated;
        return $key;
    }

    throw new RuntimeException('Application signing secret is not configured.');
}

function signedRecordRef(int $id, string $scope): string
{
    $id = max(0, $id);
    $scope = preg_replace('/[^a-z0-9._-]/i', '', $scope) ?: 'record';
    $payload = $scope . '.' . $id;
    $mac = substr(hash_hmac('sha256', $payload, appSigningKey(), true), 0, 16);

    return rtrim(strtr(base64_encode($payload . "\n" . $mac), '+/', '-_'), '=');
}

function idFromSignedRecordRef(?string $ref, string $scope): int
{
    $ref = trim((string) $ref);
    if ($ref === '') {
        return 0;
    }

    $scope = preg_replace('/[^a-z0-9._-]/i', '', $scope) ?: 'record';
    $padded = strtr($ref, '-_', '+/');
    $pad = strlen($padded) % 4;
    if ($pad > 0) {
        $padded .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($padded, true);
    if (!is_string($raw) || !str_contains($raw, "\n")) {
        return 0;
    }

    [$payload, $mac] = explode("\n", $raw, 2);
    $expected = substr(hash_hmac('sha256', $payload, appSigningKey(), true), 0, 16);
    if (!hash_equals($expected, $mac)) {
        return 0;
    }

    $prefix = $scope . '.';
    if (!str_starts_with($payload, $prefix)) {
        return 0;
    }

    $id = (int) substr($payload, strlen($prefix));

    return $id > 0 ? $id : 0;
}

function requestRecordId(string $scope, string $postKey = 'ref'): int
{
    $candidates = [
        $_POST[$postKey] ?? null,
        $_GET['ref'] ?? null,
        $_POST['id'] ?? null,
    ];
    foreach ($candidates as $raw) {
        if (!is_string($raw) && !is_int($raw)) {
            continue;
        }
        $id = idFromSignedRecordRef(trim((string) $raw), $scope);
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function postedRecordId(string $scope, string $postKey = 'ref'): int
{
    return requestRecordId($scope, $postKey);
}

function signedRecordUrl(string $path, int $id, string $scope, array $query = []): string
{
    $hash = '';
    $hashPos = strpos($path, '#');
    if ($hashPos !== false) {
        $hash = substr($path, $hashPos);
        $path = substr($path, 0, $hashPos);
    }

    $query = ['ref' => signedRecordRef($id, $scope)] + $query;
    $sep = str_contains($path, '?') ? '&' : '?';

    return $path . $sep . http_build_query($query) . $hash;
}

function signedRecordField(int $id, string $scope, string $name = 'ref'): string
{
    return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        . '" value="' . htmlspecialchars(signedRecordRef($id, $scope), ENT_QUOTES, 'UTF-8') . '">';
}

function idsFromSignedRecordRefs(array $refs, string $scope): array
{
    $ids = [];
    foreach ($refs as $raw) {
        $id = idFromSignedRecordRef(is_scalar($raw) ? (string) $raw : '', $scope);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return $ids;
}

function legacyOpaqueId(?string $raw): int
{
    $raw = trim((string) $raw);
    if ($raw === '' || ctype_digit($raw)) {
        return 0;
    }

    $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
    if ($decoded === false) {
        return 0;
    }

    $decoded = trim(urldecode($decoded));
    if (ctype_digit($decoded)) {
        return (int) $decoded;
    }

    $id = (int) filter_var($decoded, FILTER_VALIDATE_INT);

    return $id > 0 ? $id : 0;
}

function publicRecordId(string $scope): int
{
    $id = requestRecordId($scope);
    if ($id > 0) {
        return $id;
    }

    return legacyOpaqueId(isset($_GET['id']) ? (string) $_GET['id'] : null);
}
