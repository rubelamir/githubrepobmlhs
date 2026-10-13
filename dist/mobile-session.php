<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}

$cookieParameters = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => (string) ($cookieParameters['domain'] ?? ''),
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../bmlhs/api/v1/bootstrap.php';

$identity = mobileApiRequireIdentity(getDatabaseConnection());
if (($identity['account_type'] ?? '') !== 'staff') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Only an authenticated staff account can access the Admin Panel.');
}

$teacher = $identity['account'] ?? [];
$teacherId = (int) ($teacher['id'] ?? 0);
$teacherMobile = trim((string) ($teacher['teacher_mobile'] ?? ''));
if ($teacherId < 1 || $teacherMobile === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The staff account is not eligible for the Admin Panel.');
}

establishAdminTeacherSession($teacherId, $teacherMobile);
$_SESSION['mobile_admin_dark_theme'] = true;
header('Location: /dist/index.php', true, 303);
exit;
