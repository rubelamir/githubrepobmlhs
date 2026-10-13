<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $identity = mobileApiRequireIdentity(getDatabaseConnection());
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'identity lookup');
}
$account = $identity['account'];
if ($identity['account_type'] === 'staff') {
    $account['permissions'] = [
        'modules' => decodedAdminModules($account),
        'can_view_all_attendance' => canViewAllAttendance($account),
        'can_view_all_marksheets' => canViewAllMarksheets($account),
        'is_head_admin' => isHeadAdmin($account),
    ];
    unset($account['admin_modules']);
    unset($account['teacher_status']);
} else {
    unset($account['status']);
}

mobileApiJson([
    'data' => [
        'account_type' => $identity['account_type'],
        'account' => $account,
    ],
]);
