<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use POST.']], 405);
}

$token = mobileApiBearerToken();
if ($token === '') {
    mobileApiJson(['error' => ['code' => 'unauthorized', 'message' => 'Authentication is required.']], 401);
}

try {
    $db = getDatabaseConnection();
    if (!tableExists($db, 'mobile_api_tokens')) {
        mobileApiJson(['data' => ['logged_out' => true]]);
    }
    $db->prepare(
        'UPDATE mobile_api_tokens SET revoked_at = UTC_TIMESTAMP()
         WHERE token_hash = :hash AND revoked_at IS NULL'
    )->execute(['hash' => hash('sha256', $token)]);
    if (tableExists($db, 'mobile_api_refresh_tokens')) {
        $db->prepare(
            'UPDATE mobile_api_refresh_tokens SET revoked_at = UTC_TIMESTAMP()
             WHERE access_token_hash = :hash AND revoked_at IS NULL'
        )->execute(['hash' => hash('sha256', $token)]);
    }
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'logout');
}

mobileApiJson(['data' => ['logged_out' => true]]);
