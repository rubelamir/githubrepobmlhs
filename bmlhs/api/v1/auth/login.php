<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use POST.']], 405);
}

$input = mobileApiReadJson();
$accountType = trim((string) ($input['account_type'] ?? ''));
$mobile = trim((string) ($input['mobile'] ?? ''));
$password = (string) ($input['password'] ?? '');
$deviceName = trim((string) ($input['device_name'] ?? ''));
$mobileCore = mobileApiNormalizeMobile($mobile);

if (!in_array($accountType, ['staff', 'student'], true)
    || $mobile === ''
    || $password === ''
    || strlen($mobile) > 40
    || strlen($password) > 1024
    || strlen($mobileCore) < 6
) {
    mobileApiJson(['error' => ['code' => 'invalid_credentials', 'message' => 'Mobile number and password are required.']], 422);
}

if (isLoginRateLimited($accountType === 'staff' ? 'teacher' : 'admission', $mobileCore)) {
    mobileApiJson(['error' => ['code' => 'rate_limited', 'message' => 'Too many login attempts. Try again later.']], 429);
}

$account = null;
$storedPassword = '';
$category = $accountType === 'staff' ? 'teacher' : 'admission';
try {
    $db = getDatabaseConnection();
    if ($accountType === 'staff') {
        $normalizedMobile = mobileApiMobileSql('teacher_mobile');
        $statement = $db->prepare(
            "SELECT id, teacher_mobile, teacher_password, teacher_status
             FROM teacher_info
             WHERE teacher_mobile = :mobile
                OR {$normalizedMobile} = :mobile_core
             LIMIT 1"
        );
        $statement->execute(['mobile' => $mobile, 'mobile_core' => $mobileCore]);
        $account = $statement->fetch() ?: null;
        $storedPassword = (string) ($account['teacher_password'] ?? '');
        $valid = is_array($account)
            && verifyStoredPassword($password, $storedPassword)
            && isTeacherActiveStatus($account['teacher_status'] ?? 0);
    } else {
        $normalizedMobile = mobileApiMobileSql('mobile');
        $statement = $db->prepare(
            "SELECT id, mobile, pass, status FROM students_info
             WHERE mobile = :mobile OR {$normalizedMobile} = :mobile_core
             ORDER BY id"
        );
        $statement->execute(['mobile' => $mobile, 'mobile_core' => $mobileCore]);
        $matchedCredentials = mobileApiMatchStudentCredentials($statement->fetchAll(), $password);
        $account = $matchedCredentials['account'] ?? null;
        $storedPassword = $matchedCredentials['stored_password'] ?? '';
        $valid = is_array($account);
    }

    if ($valid && isLegacyPlaintextPassword($storedPassword)) {
        $column = $accountType === 'staff' ? 'teacher_password' : 'pass';
        $table = $accountType === 'staff' ? 'teacher_info' : 'students_info';
        $db->prepare("UPDATE {$table} SET {$column} = :password WHERE id = :id")
            ->execute(['password' => hashPassword($password), 'id' => (int) $account['id']]);
    }

    $issued = $valid && is_array($account)
        ? mobileApiIssueSession($db, $accountType, (int) $account['id'], substr($deviceName, 0, 120))
        : null;
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'login');
}

if (!$valid || !is_array($account) || !is_array($issued)) {
    recordLoginFailure($category, $mobileCore);
    mobileApiJson(['error' => ['code' => 'invalid_credentials', 'message' => 'Mobile number or password is incorrect.']], 401);
}

clearLoginFailures($category, $mobileCore);
mobileApiJson([
    'data' => [
        'access_token' => $issued['access_token'],
        'refresh_token' => $issued['refresh_token'],
        'token_type' => $issued['token_type'],
        'expires_at' => $issued['expires_at'],
        'account_type' => $accountType,
    ],
]);
