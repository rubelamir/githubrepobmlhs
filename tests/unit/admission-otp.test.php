<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/shared/school.php';
require_once $root . '/shared/security.php';
require_once $root . '/shared/sms.php';
require_once $root . '/shared/admission-otp.php';

$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        $failed++;
    }
};

putenv('ADMINLTE_APP_SECRET=unit-test-admission-otp-secret');
$_ENV['ADMINLTE_APP_SECRET'] = 'unit-test-admission-otp-secret';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE students_info (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mobile TEXT NOT NULL DEFAULT \'\'
    )'
);

ensureAdmissionMobileOtpTable($db);
$check(
    (bool) $db->query("SELECT name FROM sqlite_master WHERE name = 'admission_mobile_otps'")->fetchColumn(),
    'admission_mobile_otps table is created'
);

$check(admissionOtpNormalizeMobile('০১৭১২৩৪৫৬৭৮') === '01712345678', 'Bangla mobile normalizes to local 01 form');

$sentSms = [];
$sent = admissionOtpRequest(
    $db,
    '01799887766',
    '203.0.113.50',
    static function (string $mobile, string $text) use (&$sentSms): bool {
        $sentSms[] = [$mobile, $text];

        return true;
    }
);
$check(($sent['otp_id'] ?? 0) > 0, 'request returns an otp id');
$check(count($sentSms) === 1, 'one SMS is sent');
preg_match('/(\d{6})/u', $sentSms[0][1] ?? '', $match);
$code = $match[1] ?? '';
$check(strlen($code) === 6, 'SMS body contains a 6-digit code');

$check(
    admissionOtpVerifyAndConsume($db, (int) $sent['otp_id'], '01799887766', '000000') === false,
    'wrong code is rejected'
);
$check(
    admissionOtpVerifyAndConsume($db, (int) $sent['otp_id'], '01799887766', $code) === true,
    'correct code is accepted once'
);
$reused = false;
try {
    admissionOtpVerifyAndConsume($db, (int) $sent['otp_id'], '01799887766', $code);
} catch (RuntimeException $exception) {
    $reused = true;
}
$check($reused, 'a used code cannot be reused');

$db->exec("INSERT INTO students_info (mobile) VALUES ('01711112222')");
$blocked = false;
try {
    admissionOtpRequest(
        $db,
        '01711112222',
        '203.0.113.51',
        static fn (): bool => true
    );
} catch (RuntimeException $exception) {
    $blocked = str_contains($exception->getMessage(), 'ব্যবহৃত');
}
$check($blocked, 'an already-registered mobile cannot request a code');

$beforeFail = (int) $db->query('SELECT COUNT(*) FROM admission_mobile_otps')->fetchColumn();
$failedSend = false;
try {
    admissionOtpRequest(
        $db,
        '01755667788',
        '203.0.113.52',
        static fn (): bool => false
    );
} catch (RuntimeException $exception) {
    $failedSend = str_contains($exception->getMessage(), 'SMS');
}
$afterFail = (int) $db->query('SELECT COUNT(*) FROM admission_mobile_otps')->fetchColumn();
$check($failedSend, 'a failed SMS send raises a clear error');
$check($afterFail === $beforeFail, 'failed SMS sends do not leave OTP rows that burn the rate limit');
$check(
    str_contains(admissionOtpSmsFailureMessage('Insufficient Balance'), 'ব্যালেন্স'),
    'low-balance gateway errors map to a Bangla balance message'
);

$admissionPhp = (string) file_get_contents($root . '/bmlhs/admission.php');
$check(
    str_contains($admissionPhp, 'admissionOtpVerifyAndConsume')
    && str_contains($admissionPhp, 'mobile_otp_code')
    && str_contains($admissionPhp, 'admissionOtpSend'),
    'admission form wires SMS OTP fields and verification'
);
$check(
    is_file($root . '/bmlhs/admission_otp.php'),
    'public admission_otp.php endpoint exists'
);
$validationJs = (string) file_get_contents($root . '/bmlhs/js/admission-validation.js');
$check(
    str_contains($validationJs, '/admission_otp.php')
    && str_contains($validationJs, 'admissionOtpSend'),
    'client validation can request an SMS code'
);

if ($failed > 0) {
    fwrite(STDERR, $failed . " check(s) failed\n");
    exit(1);
}
echo "ok\n";
