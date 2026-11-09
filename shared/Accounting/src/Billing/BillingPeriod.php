<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use DateTimeImmutable;
use RuntimeException;

/**
 * What an invoice is for. The key is part of every invoice line's unique key,
 * so the same fee head can be billed only once per student and period:
 *   monthly  M:2026-03     session  S:2026
 *   exam     E:2026:12     other    O:2026-03   (exam ids repeat every session)
 */
final class BillingPeriod
{
    public const MONTHLY = 'monthly';
    public const SESSION = 'session';
    public const EXAM = 'exam';
    public const OTHER = 'other';

    public const KIND_LABELS = [
        self::MONTHLY => 'মাসিক',
        self::SESSION => 'সেশন / ভর্তি (বছরে একবার)',
        self::EXAM => 'পরীক্ষা',
        self::OTHER => 'অন্যান্য',
    ];

    public const MONTHS_BN = [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল', 5 => 'মে', 6 => 'জুন',
        7 => 'জুলাই', 8 => 'আগস্ট', 9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];

    private function __construct(
        private string $kind,
        private string $key,
        private string $label,
        private DateTimeImmutable $referenceDate
    ) {
    }

    /** $month = "YYYY-MM" */
    public static function monthly(string $month): self
    {
        $date = self::monthStart($month);

        return new self(self::MONTHLY, 'M:' . $date->format('Y-m'), self::monthLabel($date) . ' মাসের বেতন', $date);
    }

    public static function session(string $sessionYear): self
    {
        if (preg_match('/^\d{4}$/', $sessionYear) !== 1) {
            throw new RuntimeException('সেশন সঠিক নয়।');
        }

        return new self(self::SESSION, 'S:' . $sessionYear, 'সেশন ' . \bnDigits($sessionYear), new DateTimeImmutable($sessionYear . '-01-01'));
    }

    public static function exam(int $examId, string $examName, string $sessionYear, DateTimeImmutable $issueDate): self
    {
        if ($examId < 1) {
            throw new RuntimeException('পরীক্ষা নির্বাচন করুন।');
        }
        if (preg_match('/^\d{4}$/', $sessionYear) !== 1) {
            throw new RuntimeException('সেশন সঠিক নয়।');
        }
        $label = (trim($examName) !== '' ? trim($examName) : 'পরীক্ষা') . ' ' . \bnDigits($sessionYear);

        return new self(self::EXAM, 'E:' . $sessionYear . ':' . $examId, $label, $issueDate);
    }

    public static function other(string $month): self
    {
        $date = self::monthStart($month);

        return new self(self::OTHER, 'O:' . $date->format('Y-m'), self::monthLabel($date) . ' (অন্যান্য ফি)', $date);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** Date used to pick the applicable rate, fee category and concession. */
    public function referenceDate(): DateTimeImmutable
    {
        return $this->referenceDate;
    }

    /**
     * Monthly/other fees fall due on $dueDay of the billed month (clamped to month end).
     * Session and exam fees fall due $dueDay days after the issue date.
     */
    public function dueDate(int $dueDay, DateTimeImmutable $issueDate): DateTimeImmutable
    {
        $dueDay = max(1, min(31, $dueDay));
        if ($this->kind === self::MONTHLY || $this->kind === self::OTHER) {
            $day = min($dueDay, (int) $this->referenceDate->format('t'));
            $due = $this->referenceDate->setDate((int) $this->referenceDate->format('Y'), (int) $this->referenceDate->format('n'), $day);

            return $due < $issueDate ? $issueDate : $due;
        }

        return $issueDate->modify('+' . $dueDay . ' days');
    }

    public static function monthLabel(DateTimeImmutable $date): string
    {
        return self::MONTHS_BN[(int) $date->format('n')] . ' ' . \bnDigits($date->format('Y'));
    }

    private static function monthStart(string $month): DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $match) !== 1 || (int) $match[2] < 1 || (int) $match[2] > 12) {
            throw new RuntimeException('মাস সঠিক নয়।');
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $match[1], (int) $match[2]));
    }
}
