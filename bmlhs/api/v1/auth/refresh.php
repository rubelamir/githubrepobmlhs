<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use POST.']], 405);
}

$input = mobileApiReadJson();
$refreshToken = $input['refresh_token'] ?? null;
if (!mobileApiRefreshTokenIsValid($refreshToken)) {
    mobileApiJson(['error' => ['code' => 'invalid_refresh_token', 'message' => 'A valid refresh token is required.']], 401);
}

try {
    $db = getDatabaseConnection();
    mobileApiEnsureTokenSchema($db);
    mobileApiEnsureRefreshTokenSchema($db);
    if (!tableExists($db, 'mobile_api_refresh_tokens')) {
        mobileApiJson(['error' => ['code' => 'invalid_refresh_token', 'message' => 'The refresh token is invalid or expired.']], 401);
    }

    $db->beginTransaction();
    $rows = fetchRows(
        $db,
        'SELECT id, access_token_hash, account_type, account_id, device_name
         FROM mobile_api_refresh_tokens
         WHERE token_hash = :hash AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
         LIMIT 1 FOR UPDATE',
        ['hash' => hash('sha256', $refreshToken)]
    );
    if ($rows === []) {
        $db->rollBack();
        mobileApiJson(['error' => ['code' => 'invalid_refresh_token', 'message' => 'The refresh token is invalid or expired.']], 401);
    }

    $row = $rows[0];
    $accountType = (string) $row['account_type'];
    $accountId = (int) $row['account_id'];
    if ($accountType === 'staff') {
        $account = fetchRows(
            $db,
            'SELECT id, teacher_status FROM teacher_info WHERE id = :id LIMIT 1',
            ['id' => $accountId]
        )[0] ?? null;
        $eligible = is_array($account) && isTeacherActiveStatus($account['teacher_status'] ?? 0);
    } else {
        $account = fetchRows(
            $db,
            'SELECT id, status FROM students_info WHERE id = :id LIMIT 1',
            ['id' => $accountId]
        )[0] ?? null;
        $eligible = is_array($account)
            && in_array((int) ($account['status'] ?? 0), [studentStatusAdmitted(), studentStatusSscCandidate()], true);
    }
    if (!$eligible) {
        $db->prepare(
            'UPDATE mobile_api_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute(['id' => (int) $row['id']]);
        $db->prepare(
            'UPDATE mobile_api_tokens SET revoked_at = UTC_TIMESTAMP()
             WHERE token_hash = :hash AND revoked_at IS NULL'
        )->execute(['hash' => (string) $row['access_token_hash']]);
        $db->commit();
        mobileApiJson(['error' => ['code' => 'account_unavailable', 'message' => 'This account is unavailable.']], 401);
    }

    $issued = mobileApiIssueToken($db, $accountType, $accountId, (string) $row['device_name']);
    $nextRefreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    mobileApiIssueRefreshToken(
        $db,
        $nextRefreshToken,
        $issued['access_token'],
        $accountType,
        $accountId,
        (string) $row['device_name']
    );
    $db->prepare(
        'UPDATE mobile_api_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id'
    )->execute(['id' => (int) $row['id']]);
    $db->prepare(
        'UPDATE mobile_api_tokens SET revoked_at = UTC_TIMESTAMP()
         WHERE token_hash = :hash AND revoked_at IS NULL'
    )->execute(['hash' => (string) $row['access_token_hash']]);
    $db->commit();
} catch (PDOException $exception) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    mobileApiDatabaseFailure($exception, 'token refresh');
}

mobileApiJson([
    'data' => [
        'access_token' => $issued['access_token'],
        'refresh_token' => $nextRefreshToken,
        'token_type' => $issued['token_type'],
        'expires_at' => $issued['expires_at'],
    ],
]);
