<?php
declare(strict_types=1);

/**
 * Six-digit SMS verification for the public online admission form.
 * Codes are stored as HMACs, expire in 5 minutes, allow 5 tries, and are
 * rate-limited per mobile and per IP (same shape as the fee-portal OTP).
 */

const ADMISSION_OTP_MINUTES = 5;
const ADMISSION_OTP_MAX_TRIES = 5;
const ADMISSION_OTP_PER_MOBILE_30MIN = 3;
const ADMISSION_OTP_PER_IP_HOUR = 10;

function ensureAdmissionMobileOtpTable(PDO $db): void
{
    $driver = '';
    try {
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $exception) {
        $driver = '';
    }
    if ($driver === 'sqlite') {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS admission_mobile_otps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mobile TEXT NOT NULL,
                code_hash TEXT NOT NULL,
                client_ip TEXT NOT NULL DEFAULT '',
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL
            )"
        );

        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS admission_mobile_otps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mobile VARCHAR(20) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            client_ip VARCHAR(45) NOT NULL DEFAULT '',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            KEY idx_admission_otp_mobile (mobile, created_at),
            KEY idx_admission_otp_ip (client_ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function admissionOtpRequired(PDO $db): bool
{
    return function_exists('smsGatewayReady') && smsGatewayReady($db);
}

/** Local 01XXXXXXXXX form used on the admission form and in the OTP table. */
function admissionOtpNormalizeMobile(string $raw): string
{
    $normalized = normalizeBdMobile($raw);
    if ($normalized === '') {
        return '';
    }

    return smsLocalBdMobile($normalized);
}

function admissionOtpHash(string $mobile, string $code): string
{
    return hash_hmac('sha256', 'admission-otp|' . $mobile . '|' . $code, appSigningKey());
}

function admissionOtpNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka'));
}

/**
 * @return array{otp_id: int}
 */
function admissionOtpRequest(PDO $db, string $mobile, string $clientIp, ?callable $sendSms = null): array
{
    ensureAdmissionMobileOtpTable($db);
    $local = admissionOtpNormalizeMobile($mobile);
    if ($local === '' || strlen($local) !== 11) {
        throw new RuntimeException('সঠিক ১১ সংখ্যার মোবাইল নম্বর লিখুন।');
    }
    // Injected senders (unit tests) skip the live gateway readiness check.
    if ($sendSms === null && !admissionOtpRequired($db)) {
        throw new RuntimeException('এই মুহূর্তে SMS পাঠানো যাচ্ছে না। বিদ্যালয়ে যোগাযোগ করুন।');
    }

    $mobileVariants = array_values(array_unique(array_filter([
        $local,
        function_exists('bnDigits') ? bnDigits($local) : '',
    ])));
    $placeholders = [];
    $params = [];
    foreach ($mobileVariants as $index => $variant) {
        $key = 'm' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $variant;
    }
    $existing = fetchRows(
        $db,
        'SELECT id FROM students_info WHERE mobile IN (' . implode(', ', $placeholders) . ') LIMIT 1',
        $params
    );
    if ($existing !== []) {
        throw new RuntimeException('ইতি পূর্বে এই মোবাইল নাম্বার ব্যবহৃত হয়েছে।');
    }

    $now = admissionOtpNow();
    $ip = mb_substr($clientIp !== '' ? $clientIp : '0.0.0.0', 0, 45);
    $ipCount = (int) admissionOtpScalar(
        $db,
        'SELECT COUNT(*) FROM admission_mobile_otps WHERE client_ip = :ip AND created_at > :t',
        ['ip' => $ip, 't' => $now->modify('-1 hour')->format('Y-m-d H:i:s')]
    );
    if ($ipCount >= ADMISSION_OTP_PER_IP_HOUR) {
        throw new RuntimeException('অনেকবার চেষ্টা করা হয়েছে। এক ঘণ্টা পর আবার চেষ্টা করুন।');
    }
    $recent = (int) admissionOtpScalar(
        $db,
        'SELECT COUNT(*) FROM admission_mobile_otps WHERE mobile = :m AND created_at > :t',
        ['m' => $local, 't' => $now->modify('-30 minutes')->format('Y-m-d H:i:s')]
    );
    if ($recent >= ADMISSION_OTP_PER_MOBILE_30MIN) {
        throw new RuntimeException('এই নম্বরে অনেকবার কোড চাওয়া হয়েছে। ৩০ মিনিট পর আবার চেষ্টা করুন।');
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare(
        'INSERT INTO admission_mobile_otps (mobile, code_hash, client_ip, created_at, expires_at)
         VALUES (:m, :h, :ip, :now, :exp)'
    )->execute([
        'm' => $local,
        'h' => admissionOtpHash($local, $code),
        'ip' => $ip,
        'now' => $now->format('Y-m-d H:i:s'),
        'exp' => $now->modify('+' . ADMISSION_OTP_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
    ]);
    $otpId = (int) $db->lastInsertId();

    // ASCII-only OTP text delivers more reliably on BD GSM routes than Bangla unicode.
    $school = 'BMLHS';
    try {
        if (function_exists('schoolSettings')) {
            $short = trim((string) (schoolSettings($db)['short_name_en'] ?? ''));
            if ($short !== '' && preg_match('/^[\x20-\x7E]+$/', $short) === 1) {
                $school = $short;
            }
        }
    } catch (Throwable $exception) {
        // keep default
    }
    $text = $school . ' admission code ' . $code . '. Valid ' . ADMISSION_OTP_MINUTES . ' min.';

    $smsDetail = '';
    try {
        if ($sendSms !== null) {
            if (!$sendSms($local, $text)) {
                $smsDetail = 'injected_sender_failed';
                throw new RuntimeException(admissionOtpSmsFailureMessage($smsDetail));
            }
        } else {
            $sent = sendSchoolSms($db, $local, $text, 'admission_otp');
            if (empty($sent['ok'])) {
                $smsDetail = (string) ($sent['detail'] ?? '');
                error_log('admission OTP SMS failed for ' . $local . ': ' . $smsDetail);
                throw new RuntimeException(admissionOtpSmsFailureMessage($smsDetail));
            }
        }
    } catch (Throwable $exception) {
        // Do not burn the per-mobile / per-IP quota on gateway failures.
        try {
            $db->prepare('DELETE FROM admission_mobile_otps WHERE id = :id AND used_at IS NULL')
                ->execute(['id' => $otpId]);
        } catch (Throwable $cleanup) {
            error_log('admission OTP cleanup failed: ' . $cleanup->getMessage());
        }
        if ($exception instanceof RuntimeException) {
            throw $exception;
        }
        error_log('admission OTP SMS error: ' . $exception->getMessage());
        throw new RuntimeException(admissionOtpSmsFailureMessage($smsDetail !== '' ? $smsDetail : $exception->getMessage()));
    }

    return ['otp_id' => $otpId];
}

/** Map SMS gateway detail text to a short Bangla message for applicants. */
function admissionOtpSmsFailureMessage(string $detail): string
{
    $detail = strtolower(trim($detail));
    if (
        str_contains($detail, 'balance')
        || str_contains($detail, 'insufficient')
        || str_contains($detail, 'no credit')
        || str_contains($detail, 'recharge')
    ) {
        return 'SMS ব্যালেন্স শেষ বা অপর্যাপ্ত। বিদ্যালয় অফিসে জানান।';
    }
    if (
        str_contains($detail, 'timeout')
        || str_contains($detail, 'timed out')
        || str_contains($detail, 'connection')
    ) {
        return 'SMS সার্ভার সাড়া দিচ্ছে না। একটু পর আবার চেষ্টা করুন।';
    }
    if (
        str_contains($detail, 'invalid')
        || str_contains($detail, 'unauthorized')
        || str_contains($detail, 'token')
        || str_contains($detail, 'forbidden')
    ) {
        return 'SMS সেটিংস ঠিক নেই। বিদ্যালয় অফিসে জানান।';
    }
    if ($detail === 'not_configured') {
        return 'SMS গেটওয়ে সেট করা নেই। বিদ্যালয় অফিসে জানান।';
    }

    return 'এই মুহূর্তে SMS পাঠানো যাচ্ছে না। পরে আবার চেষ্টা করুন।';
}

function admissionOtpVerifyAndConsume(PDO $db, int $otpId, string $mobile, string $code): bool
{
    ensureAdmissionMobileOtpTable($db);
    $local = admissionOtpNormalizeMobile($mobile);
    $code = preg_replace('/\D+/', '', function_exists('asciiDigits') ? asciiDigits($code) : $code) ?? '';
    if ($otpId < 1 || $local === '' || strlen($code) !== 6) {
        return false;
    }

    $row = fetchRows(
        $db,
        'SELECT * FROM admission_mobile_otps WHERE id = :id AND mobile = :m LIMIT 1',
        ['id' => $otpId, 'm' => $local]
    )[0] ?? null;
    $now = admissionOtpNow()->format('Y-m-d H:i:s');
    if (
        $row === null
        || $row['used_at'] !== null
        || (string) $row['expires_at'] < $now
        || (int) $row['attempts'] >= ADMISSION_OTP_MAX_TRIES
    ) {
        throw new RuntimeException('কোডের মেয়াদ শেষ বা অনেকবার ভুল হয়েছে। নতুন কোড নিন।');
    }

    $db->prepare('UPDATE admission_mobile_otps SET attempts = attempts + 1 WHERE id = :id')
        ->execute(['id' => $otpId]);

    if (!hash_equals((string) $row['code_hash'], admissionOtpHash($local, $code))) {
        return false;
    }

    $used = $db->prepare(
        'UPDATE admission_mobile_otps SET used_at = :now WHERE id = :id AND used_at IS NULL'
    );
    $used->execute(['now' => $now, 'id' => $otpId]);

    return $used->rowCount() === 1;
}

/** @param array<string, mixed> $params */
function admissionOtpScalar(PDO $db, string $sql, array $params): mixed
{
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchColumn();
}
