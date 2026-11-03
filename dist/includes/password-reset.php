<?php
declare(strict_types=1);

/**
 * SMS one-time-code password reset for panel users (teacher_info).
 *
 * Codes are stored only as HMAC hashes, expire quickly, allow few guesses and
 * are rate limited per account and per IP address.
 */

const PASSWORD_RESET_CODE_TTL = 600;
const PASSWORD_RESET_VERIFIED_TTL = 600;
const PASSWORD_RESET_MAX_GUESSES = 5;
const PASSWORD_RESET_RESEND_SECONDS = 60;
const PASSWORD_RESET_MAX_PER_ACCOUNT_HOUR = 3;
const PASSWORD_RESET_MAX_PER_IP_HOUR = 10;
const PASSWORD_RESET_MIN_LENGTH = 6;
const PASSWORD_RESET_MAX_LENGTH = 72;

function ensurePasswordResetSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS teacher_password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            code_hash CHAR(64) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            used_at INT UNSIGNED DEFAULT NULL,
            KEY idx_reset_teacher (teacher_id, created_at),
            KEY idx_reset_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Accepts only Bangladeshi mobile formats: 01XXXXXXXXX, 8801XXXXXXXXX or +8801XXXXXXXXX (operator digit 3-9). */
function passwordResetIsBdMobile(string $input): bool
{
    $clean = (string) preg_replace('/[\s\-]+/', '', asciiDigits(trim($input)));

    return preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $clean) === 1;
}

/** Same number matching as the sign-in form: dashes, spaces, +88 and missing leading zero are tolerated. */
function passwordResetMobileCore(string $input): string
{
    $digits = (string) preg_replace('/\D+/', '', asciiDigits(trim($input)));
    if (strlen($digits) === 13 && str_starts_with($digits, '88')) {
        $digits = substr($digits, 2);
    }

    return ltrim($digits, '0');
}

function passwordResetFindTeacher(PDO $db, string $mobileInput): ?array
{
    $core = passwordResetMobileCore($mobileInput);
    if (strlen($core) < 6) {
        return null;
    }

    $normalized = "REPLACE(REPLACE(REPLACE(teacher_mobile, '-', ''), ' ', ''), '+', '')";
    foreach (['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'] as $bn => $en) {
        $normalized = "REPLACE({$normalized}, '{$bn}', '{$en}')";
    }
    $statement = $db->prepare(
        "SELECT id, teacher_mobile, teacher_status
         FROM teacher_info
         WHERE TRIM(LEADING '0' FROM CASE
                WHEN LEFT({$normalized}, 3) = '880' THEN SUBSTRING({$normalized}, 4)
                WHEN LEFT({$normalized}, 2) = '88' THEN SUBSTRING({$normalized}, 3)
                ELSE {$normalized}
            END) = :core
         LIMIT 1"
    );
    $statement->execute(['core' => $core]);
    $teacher = $statement->fetch();

    if (!is_array($teacher) || !isTeacherActiveStatus($teacher['teacher_status'] ?? 0)) {
        return null;
    }

    return $teacher;
}

function passwordResetCodeHash(int $teacherId, string $code): string
{
    return hash_hmac('sha256', $teacherId . '|' . $code, appSigningKey());
}

/**
 * @return string sms_unavailable | sent | throttled | failed
 */
function passwordResetSendCode(PDO $db, array $teacher): string
{
    if (!smsGatewayReady($db)) {
        return 'sms_unavailable';
    }

    ensurePasswordResetSchema($db);
    $now = time();
    $teacherId = (int) $teacher['id'];
    $ip = substr(loginClientIp(), 0, 45);

    $perAccount = $db->prepare('SELECT COUNT(*), MAX(created_at) FROM teacher_password_resets WHERE teacher_id = :id AND created_at > :since');
    $perAccount->execute(['id' => $teacherId, 'since' => $now - 3600]);
    [$accountCount, $lastSent] = array_map('intval', $perAccount->fetch(PDO::FETCH_NUM));

    $perIp = $db->prepare('SELECT COUNT(*) FROM teacher_password_resets WHERE ip = :ip AND created_at > :since');
    $perIp->execute(['ip' => $ip, 'since' => $now - 3600]);

    if (
        $accountCount >= PASSWORD_RESET_MAX_PER_ACCOUNT_HOUR
        || $now - $lastSent < PASSWORD_RESET_RESEND_SECONDS
        || (int) $perIp->fetchColumn() >= PASSWORD_RESET_MAX_PER_IP_HOUR
    ) {
        return 'throttled';
    }

    $code = (string) random_int(100000, 999999);
    $db->prepare('UPDATE teacher_password_resets SET used_at = :now WHERE teacher_id = :id AND used_at IS NULL')
        ->execute(['now' => $now, 'id' => $teacherId]);
    $db->prepare(
        'INSERT INTO teacher_password_resets (teacher_id, code_hash, ip, created_at, expires_at)
         VALUES (:id, :hash, :ip, :created, :expires)'
    )->execute([
        'id' => $teacherId,
        'hash' => passwordResetCodeHash($teacherId, $code),
        'ip' => $ip,
        'created' => $now,
        'expires' => $now + PASSWORD_RESET_CODE_TTL,
    ]);

    $school = schoolSettings($db);
    $schoolName = trim((string) ($school['school_name_bn'] ?? 'বিদ্যালয়'));
    $text = $schoolName . ' পাসওয়ার্ড রিসেট কোড: ' . $code
        . '। ' . bnDigits((string) (PASSWORD_RESET_CODE_TTL / 60)) . ' মিনিট কার্যকর। কাউকে জানাবেন না।';
    $result = sendSchoolSms($db, (string) $teacher['teacher_mobile'], $text, 'password_reset');

    return $result['ok'] ? 'sent' : 'failed';
}

/**
 * @return string ok | invalid | locked
 */
function passwordResetCheckCode(PDO $db, int $teacherId, string $code): string
{
    $code = trim(asciiDigits($code));
    if ($teacherId < 1 || !preg_match('/^\d{6}$/', $code)) {
        return 'invalid';
    }

    ensurePasswordResetSchema($db);
    $now = time();
    $statement = $db->prepare(
        'SELECT id, code_hash, attempts FROM teacher_password_resets
         WHERE teacher_id = :id AND used_at IS NULL AND expires_at > :now
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute(['id' => $teacherId, 'now' => $now]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return 'invalid';
    }
    if ((int) $row['attempts'] >= PASSWORD_RESET_MAX_GUESSES) {
        return 'locked';
    }

    // Count the guess first so concurrent requests cannot exceed the limit.
    $db->prepare('UPDATE teacher_password_resets SET attempts = attempts + 1 WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);

    if (!hash_equals((string) $row['code_hash'], passwordResetCodeHash($teacherId, $code))) {
        return 'invalid';
    }

    $db->prepare('UPDATE teacher_password_resets SET used_at = :now WHERE id = :id')
        ->execute(['now' => $now, 'id' => (int) $row['id']]);

    return 'ok';
}

function passwordResetSaveNewPassword(PDO $db, int $teacherId, string $mobile, string $password): void
{
    $db->prepare('UPDATE teacher_info SET teacher_password = :password WHERE id = :id LIMIT 1')
        ->execute(['password' => hashPassword($password), 'id' => $teacherId]);

    clearLoginFailures('teacher', passwordResetMobileCore($mobile));
}
