<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use PDO;
use RuntimeException;
use School\Accounting\Audit\AuditTrail;

/**
 * Guardian login for the fee portal: class + section + roll identify the
 * student, and a one-time code goes by SMS to the mobile number on the
 * student's record. Codes are stored as HMACs, expire in 5 minutes, allow 5
 * tries, and requests are limited per student and per IP. Replies never say
 * whether a student/mobile pair exists.
 */
final class GuardianPortal
{
    public const SESSION_KEY = 'acc_guardian';
    public const PENDING_KEY = 'acc_guardian_pending';
    public const SESSION_MINUTES = 30;

    private const OTP_MINUTES = 5;
    private const OTP_MAX_TRIES = 5;
    private const OTP_PER_STUDENT_30MIN = 3;
    private const OTP_PER_IP_HOUR = 10;

    /** @var callable(string, string): bool */
    private $sendSms;

    /** @param callable(string, string): bool $sendSms (mobile, text) → sent */
    public function __construct(private PDO $db, callable $sendSms)
    {
        $this->sendSms = $sendSms;
    }

    /**
     * @return array{otp_id: int, student_id: int}|null null when the details do not match (caller shows a generic message)
     */
    public function requestOtp(int $classId, int $sectionId, string $roll, string $mobile, string $clientIp): ?array
    {
        $roll = (string) (int) preg_replace('/\D+/', '', \asciiDigits($roll));
        $normalized = \normalizeBdMobile($mobile);
        if ($classId < 1 || $roll === '0' || $normalized === '') {
            throw new RuntimeException('শ্রেণী, শাখা, রোল ও ১১ সংখ্যার মোবাইল নম্বর সঠিকভাবে দিন।');
        }
        $now = AuditTrail::now();
        $ip = mb_substr($clientIp, 0, 45);
        $ipCount = (int) $this->scalar('SELECT COUNT(*) FROM acc_guardian_otps WHERE client_ip = :ip AND created_at > :t', ['ip' => $ip, 't' => $now->modify('-1 hour')->format('Y-m-d H:i:s')]);
        if ($ipCount >= self::OTP_PER_IP_HOUR) {
            throw new RuntimeException('অনেকবার চেষ্টা করা হয়েছে। এক ঘণ্টা পর আবার চেষ্টা করুন।');
        }
        $sql = 'SELECT id, mobile FROM students_info WHERE cls = :class AND cls_roll = :roll AND status = 1';
        $params = ['class' => $classId, 'roll' => (int) $roll];
        if ($sectionId > 0) {
            $sql .= ' AND sections = :section';
            $params['section'] = (string) $sectionId;
        }
        $student = \fetchRows($this->db, $sql . ' ORDER BY session_year DESC, id DESC LIMIT 1', $params)[0] ?? null;
        $matches = $student !== null && \normalizeBdMobile((string) $student['mobile']) === $normalized;
        $studentId = $matches ? (int) $student['id'] : 0;
        if ($studentId > 0) {
            $recent = (int) $this->scalar('SELECT COUNT(*) FROM acc_guardian_otps WHERE student_id = :s AND created_at > :t', ['s' => $studentId, 't' => $now->modify('-30 minutes')->format('Y-m-d H:i:s')]);
            if ($recent >= self::OTP_PER_STUDENT_30MIN) {
                throw new RuntimeException('এই শিক্ষার্থীর জন্য অনেকবার কোড চাওয়া হয়েছে। ৩০ মিনিট পর আবার চেষ্টা করুন।');
            }
        }
        $code = $studentId > 0 ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : '';
        // Mismatches are stored too (unusable hash) so they count toward the IP limit.
        $this->db->prepare(
            'INSERT INTO acc_guardian_otps (student_id, code_hash, client_ip, created_at, expires_at) VALUES (:s, :h, :ip, :now, :exp)'
        )->execute([
            's' => $studentId,
            'h' => $studentId > 0 ? $this->hash($studentId, $code) : str_repeat('0', 64),
            'ip' => $ip,
            'now' => $now->format('Y-m-d H:i:s'),
            'exp' => $now->modify('+' . self::OTP_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
        ]);
        $otpId = (int) $this->db->lastInsertId();
        if ($studentId === 0) {
            return null;
        }
        $school = function_exists('schoolSettings') ? (string) (\schoolSettings($this->db)['short_name_en'] ?? '') : '';
        $text = ($school !== '' ? $school . ': ' : '') . 'ফি পোর্টালের কোড ' . $code . '। ' . \bnDigits((string) self::OTP_MINUTES) . ' মিনিট বৈধ। কাউকে জানাবেন না।';
        if (!($this->sendSms)((string) $student['mobile'], $text)) {
            throw new RuntimeException('এই মুহূর্তে SMS পাঠানো যাচ্ছে না। পরে আবার চেষ্টা করুন বা বিদ্যালয়ে যোগাযোগ করুন।');
        }

        return ['otp_id' => $otpId, 'student_id' => $studentId];
    }

    /** @return int student id, 0 when the code is wrong/expired */
    public function verifyOtp(int $otpId, int $studentId, string $code): int
    {
        $code = preg_replace('/\D+/', '', \asciiDigits($code)) ?? '';
        $row = \fetchRows($this->db, 'SELECT * FROM acc_guardian_otps WHERE id = :id AND student_id = :s', ['id' => $otpId, 's' => $studentId])[0] ?? null;
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        if ($row === null || $studentId < 1 || $row['used_at'] !== null || (string) $row['expires_at'] < $now || (int) $row['attempts'] >= self::OTP_MAX_TRIES) {
            throw new RuntimeException('কোডের মেয়াদ শেষ বা অনেকবার ভুল হয়েছে। নতুন কোড নিন।');
        }
        $this->db->prepare('UPDATE acc_guardian_otps SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $otpId]);
        if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], $this->hash($studentId, $code))) {
            return 0;
        }
        $used = $this->db->prepare('UPDATE acc_guardian_otps SET used_at = :now WHERE id = :id AND used_at IS NULL');
        $used->execute(['now' => $now, 'id' => $otpId]);

        return $used->rowCount() === 1 ? $studentId : 0;
    }

    /** Logged-in student id from the session (0 when absent or expired); extends the session. */
    public static function sessionStudentId(): int
    {
        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($session) || (int) ($session['expires'] ?? 0) < time() || (int) ($session['student_id'] ?? 0) < 1) {
            unset($_SESSION[self::SESSION_KEY]);

            return 0;
        }
        $_SESSION[self::SESSION_KEY]['expires'] = time() + self::SESSION_MINUTES * 60;

        return (int) $session['student_id'];
    }

    public static function startSession(int $studentId): void
    {
        session_regenerate_id(true);
        unset($_SESSION[self::PENDING_KEY]);
        $_SESSION[self::SESSION_KEY] = ['student_id' => $studentId, 'expires' => time() + self::SESSION_MINUTES * 60];
    }

    public static function endSession(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::PENDING_KEY]);
    }

    private function hash(int $studentId, string $code): string
    {
        return hash_hmac('sha256', 'guardian-otp|' . $studentId . '|' . $code, \appSigningKey());
    }

    /** @param array<string, mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
