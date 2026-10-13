<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/dist/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Content-Type-Options: nosniff');

function mobileApiJson(array $payload, int $status = 200): never
{
    $idempotency = $GLOBALS['mobile_api_idempotency'] ?? null;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_array($idempotency) && $status < 500 && is_string($body)) {
        try {
            $statement = $idempotency['db']->prepare(
                "UPDATE mobile_api_idempotent_requests
                 SET state = 'completed', response_status = :status, response_body = :body,
                     completed_at = UTC_TIMESTAMP()
                 WHERE account_type = :account_type AND account_id = :account_id
                   AND idempotency_key = :idempotency_key AND state = 'processing'"
            );
            $statement->execute([
                'status' => $status,
                'body' => $body,
                'account_type' => $idempotency['account_type'],
                'account_id' => $idempotency['account_id'],
                'idempotency_key' => $idempotency['key'],
            ]);
            if ($statement->rowCount() !== 1) {
                error_log('Mobile API idempotency response could not be persisted.');
            }
        } catch (Throwable $exception) {
            error_log('Mobile API idempotency persistence failed: ' . $exception->getMessage());
        }
    }
    http_response_code($status);
    echo $body;
    exit;
}

function mobileApiEnsureIdempotencySchema(PDO $db): void
{
    static $ready = [];
    $key = spl_object_id($db);
    if (isset($ready[$key])) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS mobile_api_idempotent_requests (
            account_type ENUM('staff', 'student') NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            idempotency_key CHAR(36) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            state ENUM('processing', 'completed') NOT NULL DEFAULT 'processing',
            response_status SMALLINT UNSIGNED DEFAULT NULL,
            response_body LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (account_type, account_id, idempotency_key),
            INDEX idx_mobile_idempotency_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ready[$key] = true;
}

function mobileApiRequestFingerprint(): string
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    $requestTarget = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'))
        . ':' . (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($contentType === 'application/json') {
        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            $raw = '';
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $raw = json_encode(
                mobileApiCanonicalizeRequestValue($decoded),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        return hash('sha256', $requestTarget . ':application/json:' . $raw);
    }

    $normalizeUpload = static function (mixed $name, mixed $type, mixed $temporaryPath, mixed $size, mixed $error) use (&$normalizeUpload): mixed {
        if (is_array($name)) {
            $normalized = [];
            foreach ($name as $key => $itemName) {
                $normalized[$key] = $normalizeUpload(
                    $itemName,
                    is_array($type) ? ($type[$key] ?? '') : $type,
                    is_array($temporaryPath) ? ($temporaryPath[$key] ?? '') : $temporaryPath,
                    is_array($size) ? ($size[$key] ?? 0) : $size,
                    is_array($error) ? ($error[$key] ?? UPLOAD_ERR_NO_FILE) : $error
                );
            }
            return $normalized;
        }
        $fileHash = is_string($temporaryPath) && is_file($temporaryPath)
            ? hash_file('sha256', $temporaryPath)
            : '';
        return [
            'name' => (string) $name,
            'type' => (string) $type,
            'size' => (int) $size,
            'error' => (int) $error,
            'sha256' => $fileHash,
        ];
    };
    $uploads = [];
    foreach ($_FILES as $field => $file) {
        if (is_array($file)) {
            $uploads[$field] = $normalizeUpload(
                $file['name'] ?? '',
                $file['type'] ?? '',
                $file['tmp_name'] ?? '',
                $file['size'] ?? 0,
                $file['error'] ?? UPLOAD_ERR_NO_FILE
            );
        }
    }
    return hash(
        'sha256',
        $requestTarget . ':' . $contentType . ':' .
            json_encode($_POST, JSON_INVALID_UTF8_SUBSTITUTE) . ':' . json_encode($uploads, JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

function mobileApiCanonicalizeRequestValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $isList = true;
    foreach (array_keys($value) as $index => $key) {
        if ($key !== $index) {
            $isList = false;
            break;
        }
    }
    if (!$isList) {
        ksort($value);
    }
    foreach ($value as $key => $child) {
        $value[$key] = mobileApiCanonicalizeRequestValue($child);
    }
    return $value;
}

function mobileApiIdempotencyKeyIsValid(mixed $key): bool
{
    return is_string($key)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) === 1;
}

function mobileApiBeginIdempotentWrite(PDO $db, array $identity): void
{
    $header = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string) ($headers['Idempotency-Key'] ?? $headers['idempotency-key'] ?? '');
    }
    if ($header === '') {
        return;
    }
    if (!mobileApiIdempotencyKeyIsValid($header)) {
        mobileApiJson(
            ['error' => ['code' => 'invalid_idempotency_key', 'message' => 'The Idempotency-Key format is invalid.']],
            400
        );
    }

    mobileApiEnsureIdempotencySchema($db);
    $accountType = (string) $identity['account_type'];
    $accountId = (int) ($identity['account']['id'] ?? 0);
    if ($accountId < 1 || !in_array($identity['account_type'] ?? null, ['staff', 'student'], true)) {
        throw new RuntimeException('The authenticated account cannot be scoped for idempotency.');
    }
    $requestHash = mobileApiRequestFingerprint();
    $statement = $db->prepare(
        "INSERT IGNORE INTO mobile_api_idempotent_requests
            (account_type, account_id, idempotency_key, request_hash, state, created_at)
         VALUES (:account_type, :account_id, :idempotency_key, :request_hash, 'processing', UTC_TIMESTAMP())"
    );
    $statement->execute([
        'account_type' => $accountType,
        'account_id' => $accountId,
        'idempotency_key' => $header,
        'request_hash' => $requestHash,
    ]);

    $lookup = $db->prepare(
        'SELECT request_hash, state, response_status, response_body
         FROM mobile_api_idempotent_requests
         WHERE account_type = :account_type AND account_id = :account_id
           AND idempotency_key = :idempotency_key LIMIT 1'
    );
    $lookup->execute([
        'account_type' => $accountType,
        'account_id' => $accountId,
        'idempotency_key' => $header,
    ]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) {
        throw new RuntimeException('The idempotency record could not be read.');
    }
    if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
        mobileApiJson(
            ['error' => ['code' => 'idempotency_key_reused', 'message' => 'This request key was already used for different data.']],
            409
        );
    }
    if ((string) $existing['state'] === 'completed') {
        $response = json_decode((string) $existing['response_body'], true);
        if (!is_array($response)) {
            throw new RuntimeException('The saved idempotency response is invalid.');
        }
        http_response_code((int) $existing['response_status']);
        echo (string) $existing['response_body'];
        exit;
    }
    if ($statement->rowCount() !== 1) {
        mobileApiJson(
            ['error' => ['code' => 'write_needs_confirmation', 'message' => 'This write may still be processing; check its result before retrying.']],
            409
        );
    }

    $GLOBALS['mobile_api_idempotency'] = [
        'db' => $db,
        'account_type' => $accountType,
        'account_id' => $accountId,
        'key' => $header,
    ];
}

function mobileApiDatabaseFailure(PDOException $exception, string $operation): never
{
    error_log('Mobile API ' . $operation . ' failed: ' . $exception->getMessage());
    mobileApiJson(
        ['error' => ['code' => 'service_unavailable', 'message' => 'The service is temporarily unavailable.']],
        503
    );
}

function mobileApiReadJson(int $maxBytes = 16384): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        mobileApiJson(['error' => ['code' => 'unsupported_media_type', 'message' => 'Send JSON request data.']], 415);
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > $maxBytes) {
        mobileApiJson(['error' => ['code' => 'invalid_request', 'message' => 'Request body is missing or too large.']], 400);
    }

    try {
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        mobileApiJson(['error' => ['code' => 'invalid_json', 'message' => 'Request body must be valid JSON.']], 400);
    }
    if (!is_array($payload)) {
        mobileApiJson(['error' => ['code' => 'invalid_json', 'message' => 'Request body must be a JSON object.']], 400);
    }

    return $payload;
}

function mobileApiEnsureTokenSchema(PDO $db): void
{
    static $ready = [];
    $key = spl_object_id($db);
    if (isset($ready[$key])) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS mobile_api_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            account_type ENUM('staff', 'student') NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            device_name VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            INDEX idx_mobile_api_owner (account_type, account_id, revoked_at),
            INDEX idx_mobile_api_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ready[$key] = true;
}

function mobileApiEnsureRefreshTokenSchema(PDO $db): void
{
    static $ready = [];
    $key = spl_object_id($db);
    if (isset($ready[$key])) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS mobile_api_refresh_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            access_token_hash CHAR(64) NOT NULL,
            account_type ENUM('staff', 'student') NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            device_name VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            INDEX idx_mobile_refresh_owner (account_type, account_id, revoked_at),
            INDEX idx_mobile_refresh_access (access_token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ready[$key] = true;
}

function mobileApiIssueToken(PDO $db, string $accountType, int $accountId, string $deviceName): array
{
    mobileApiEnsureTokenSchema($db);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expiresAt = new DateTimeImmutable('+30 days', new DateTimeZone('UTC'));
    $statement = $db->prepare(
        'INSERT INTO mobile_api_tokens
            (token_hash, account_type, account_id, device_name, created_at, expires_at)
         VALUES (:hash, :type, :account_id, :device, UTC_TIMESTAMP(), :expires_at)'
    );
    $statement->execute([
        'hash' => hash('sha256', $token),
        'type' => $accountType,
        'account_id' => $accountId,
        'device' => mb_substr(trim($deviceName), 0, 120, 'UTF-8'),
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_at' => $expiresAt->format(DATE_ATOM)];
}

function mobileApiIssueRefreshToken(
    PDO $db,
    string $refreshToken,
    string $accessToken,
    string $accountType,
    int $accountId,
    string $deviceName
): void {
    mobileApiEnsureRefreshTokenSchema($db);
    $expiresAt = new DateTimeImmutable('+30 days', new DateTimeZone('UTC'));
    $db->prepare(
        'INSERT INTO mobile_api_refresh_tokens
            (token_hash, access_token_hash, account_type, account_id, device_name, created_at, expires_at)
         VALUES (:hash, :access_hash, :type, :account_id, :device, UTC_TIMESTAMP(), :expires_at)'
    )->execute([
        'hash' => hash('sha256', $refreshToken),
        'access_hash' => hash('sha256', $accessToken),
        'type' => $accountType,
        'account_id' => $accountId,
        'device' => mb_substr(trim($deviceName), 0, 120, 'UTF-8'),
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);
}

function mobileApiIssueSession(PDO $db, string $accountType, int $accountId, string $deviceName): array
{
    mobileApiEnsureTokenSchema($db);
    mobileApiEnsureRefreshTokenSchema($db);
    $db->beginTransaction();
    try {
        $issued = mobileApiIssueToken($db, $accountType, $accountId, $deviceName);
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        mobileApiIssueRefreshToken(
            $db,
            $refreshToken,
            $issued['access_token'],
            $accountType,
            $accountId,
            $deviceName
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return $issued + ['refresh_token' => $refreshToken];
}

function mobileApiRefreshTokenIsValid(mixed $token): bool
{
    return is_string($token) && preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token) === 1;
}

function mobileApiBearerToken(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    return preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,64})$/i', trim($header), $matches) === 1
        ? $matches[1]
        : '';
}

function mobileApiRequireIdentity(PDO $db): array
{
    $token = mobileApiBearerToken();
    if ($token === '') {
        mobileApiJson(['error' => ['code' => 'unauthorized', 'message' => 'Authentication is required.']], 401);
    }
    if (!tableExists($db, 'mobile_api_tokens')) {
        mobileApiJson(['error' => ['code' => 'unauthorized', 'message' => 'The access token is invalid or expired.']], 401);
    }
    $statement = $db->prepare(
        'SELECT id, account_type, account_id, expires_at
         FROM mobile_api_tokens
         WHERE token_hash = :hash AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $statement->execute(['hash' => hash('sha256', $token)]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        mobileApiJson(['error' => ['code' => 'unauthorized', 'message' => 'The access token is invalid or expired.']], 401);
    }

    $accountType = (string) $row['account_type'];
    $accountId = (int) $row['account_id'];
    if ($accountType === 'staff') {
        $account = fetchRows(
            $db,
            'SELECT id, teacher_name, teacher_mobile, teacher_designation, teacher_status, panel_role, admin_modules
             FROM teacher_info WHERE id = :id LIMIT 1',
            ['id' => $accountId]
        )[0] ?? null;
        if (!is_array($account) || !isTeacherActiveStatus($account['teacher_status'] ?? 0)) {
            mobileApiJson(['error' => ['code' => 'unauthorized', 'message' => 'This staff account is unavailable.']], 401);
        }
        $account['role'] = currentPanelRole($account);
    } else {
        $account = fetchRows(
            $db,
            'SELECT id, name_ban, name_eng, mobile, cls, sections, cls_roll, session_year, status
             FROM students_info WHERE id = :id LIMIT 1',
            ['id' => $accountId]
        )[0] ?? null;
        if (!is_array($account) || !in_array((int) ($account['status'] ?? 0), [studentStatusAdmitted(), studentStatusSscCandidate()], true)) {
            $db->prepare('UPDATE mobile_api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['id' => (int) $row['id']]);
            mobileApiJson(['error' => ['code' => 'unauthorized', 'message' => 'This student account is unavailable.']], 401);
        }
        $account['role'] = 'student';
    }

    $db->prepare('UPDATE mobile_api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);

    return [
        'token_id' => (int) $row['id'],
        'account_type' => $accountType,
        'account' => $account,
    ];
}

function mobileApiNormalizeMobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', asciiDigits($mobile)) ?? '';
    if (str_starts_with($digits, '880')) {
        $digits = substr($digits, 3);
    } elseif (str_starts_with($digits, '88') && strlen($digits) > 11) {
        $digits = substr($digits, 2);
    }

    return ltrim($digits, '0');
}

function mobileApiMatchStudentCredentials(array $accounts, string $password): ?array
{
    foreach ($accounts as $account) {
        if (
            !is_array($account)
            || !in_array((int) ($account['status'] ?? 0), [studentStatusAdmitted(), studentStatusSscCandidate()], true)
        ) {
            continue;
        }
        $storedPassword = (string) ($account['pass'] ?? '');
        if (verifyStoredPassword($password, $storedPassword)) {
            return ['account' => $account, 'stored_password' => $storedPassword];
        }
    }

    return null;
}

function mobileApiMobileSql(string $column): string
{
    if (preg_match('/^[a-z_]+$/i', $column) !== 1) {
        throw new InvalidArgumentException('Invalid mobile column.');
    }

    $normalized = "REPLACE(REPLACE(REPLACE({$column}, '-', ''), ' ', ''), '+', '')";
    foreach (['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'] as $bnDigit => $asciiDigit) {
        $normalized = "REPLACE({$normalized}, '{$bnDigit}', '{$asciiDigit}')";
    }

    return "TRIM(LEADING '0' FROM CASE
        WHEN LEFT({$normalized}, 3) = '880' THEN SUBSTRING({$normalized}, 4)
        WHEN LEFT({$normalized}, 2) = '88' THEN SUBSTRING({$normalized}, 3)
        ELSE {$normalized}
    END)";
}
