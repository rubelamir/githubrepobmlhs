<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';
startAppSession();
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'শুধু POST অনুমোদিত।'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!verifyCsrfToken()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'নিরাপত্তা টোকেন ভুল। পাতা রিফ্রেশ করে আবার চেষ্টা করুন।'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string) ($_POST['action'] ?? 'send'));
$db = publicDatabase();

try {
    if ($action === 'send') {
        $sent = admissionOtpRequest($db, (string) ($_POST['mobile'] ?? ''), loginClientIp());
        echo json_encode([
            'ok' => true,
            'otp_id' => $sent['otp_id'],
            'message' => 'মোবাইলে ৬ সংখ্যার কোড পাঠানো হয়েছে।',
            'expires_minutes' => ADMISSION_OTP_MINUTES,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'অজানা অনুরোধ।'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(400);
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'কোড পাঠানো যায়নি। পরে আবার চেষ্টা করুন।';
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}
