<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use DateTimeImmutable;
use RuntimeException;

/**
 * What an invoice is for. The key is part of every invoice line's unique key,
 * so the same fee head can be billed only once per student and period:
 *   monthly  M:2026-03     session  S:2026     admission A:2026
 *   exam     E:2026:12     other    O:2026-03   (exam ids repeat every session)
 */
final class BillingPeriod
{
    public const MONTHLY = 'monthly';
    public const SESSION = 'session';
    /** Invoice-form choice only. Admission heads stay stored as billing_kind session. */
    public const ADMISSION = 'admission';
    public const EXAM = 'exam';
    public const OTHER = 'other';

    public const KIND_LABELS = [
        self::MONTHLY => 'মাসিক',
        self::SESSION => 'সেশন / ভর্তি (বছরে একবার)',
        self::EXAM => 'পরীক্ষা',
        self::OTHER => 'অন্যান্য',
    ];

    /** Bill-creation choices. Session and admission are separate even though both bill once a year. */
    public const INVOICE_KIND_LABELS = [
        self::MONTHLY => 'মাসিক',
        self::SESSION => 'সেশন',
        self::ADMISSION => 'ভর্তি',
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

    /**
     * Month to offer for a new monthly bill: January, then the next month through
     * December once that month already has a live bill. $existingKeys are M:YYYY-MM keys.
     *
     * @param array<int|string, mixed> $existingKeys
     */
    public static function nextOpenMonth(string $sessionYear, array $existingKeys): string
    {
        if (preg_match('/^\d{4}$/', $sessionYear) !== 1) {
            throw new RuntimeException('সেশন সঠিক নয়।');
        }
        $have = [];
        foreach ($existingKeys as $key => $value) {
            $have[is_int($key) ? (string) $value : (string) $key] = true;
        }
        for ($month = 1; $month <= 12; $month++) {
            $value = sprintf('%s-%02d', $sessionYear, $month);
            if (!isset($have['M:' . $value])) {
                return $value;
            }
        }

        return $sessionYear . '-12';
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

    /**
     * Its own yearly key, so an admission bill is not the same bill as the session fee.
     * The stored fee-head kind stays session; the invoice is still collected only once a year.
     */
    public static function admission(string $sessionYear): self
    {
        $session = self::session($sessionYear);

        return new self(self::SESSION, 'A:' . $sessionYear, 'ভর্তি ' . \bnDigits($sessionYear), $session->referenceDate());
    }

    /**
     * A stipend or scholarship monthly rate covers this bill when its effective
     * date falls in the billed month or earlier. 15 March covers March.
     */
    public static function coversMonthlyRate(string $billingKey, string $effectiveFrom, ?string $effectiveTo = null): bool
    {
        if (preg_match('/^M:(\d{4})-(\d{2})$/', $billingKey, $bill) !== 1 || (int) $bill[2] < 1 || (int) $bill[2] > 12) {
            return false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $effectiveFrom, $from) !== 1 || (int) $from[2] < 1 || (int) $from[2] > 12) {
            return false;
        }
        $billMonth = $bill[1] . '-' . $bill[2];
        if ($billMonth < $from[1] . '-' . $from[2]) {
            return false;
        }
        if ($effectiveTo !== null && $effectiveTo !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $effectiveTo, $to) !== 1 || (int) $to[2] < 1 || (int) $to[2] > 12) {
                return false;
            }
            if ($billMonth > $to[1] . '-' . $to[2]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Date used to decide whether a concession covers an existing bill.
     * Monthly and other bills use the 1st of that month. Session and admission use 1 January.
     * An exam bill uses its issue date.
     */
    public static function referenceDateForKey(string $billingKey, string $issueDate): ?string
    {
        if (preg_match('/^[MO]:(\d{4})-(\d{2})$/', $billingKey, $match) === 1 && (int) $match[2] >= 1 && (int) $match[2] <= 12) {
            return $match[1] . '-' . $match[2] . '-01';
        }
        if (preg_match('/^[SA]:(\d{4})$/', $billingKey, $match) === 1) {
            return $match[1] . '-01-01';
        }
        if (preg_match('/^E:\d{4}:\d+$/', $billingKey) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) === 1) {
            return $issueDate;
        }

        return null;
    }

    /**
     * Whether an approved concession covers this bill.
     * A session or admission fee is one bill for the year, so a concession
     * that is active on any day of that year covers it. Monthly and other
     * bills use the 1st of the month. An exam bill uses its issue date.
     */
    public static function concessionCovers(string $billingKey, string $issueDate, string $from, ?string $to): bool
    {
        if (preg_match('/^[SA]:(\d{4})$/', $billingKey, $match) === 1) {
            return $from <= $match[1] . '-12-31' && ($to === null || $to >= $match[1] . '-01-01');
        }
        $reference = self::referenceDateForKey($billingKey, $issueDate);

        return $reference !== null && $reference >= $from && ($to === null || $reference <= $to);
    }

    /** Session, then admission, then later months before earlier ones. */
    public static function compareKeys(string $left, string $right): int
    {
        return self::keyRank($left) <=> self::keyRank($right);
    }

    /** @return array{0: string, 1: int, 2: int, 3: int, 4: string} */
    private static function keyRank(string $key): array
    {
        if (preg_match('/^S:(\d{4})$/', $key, $match) === 1) {
            return [$match[1], 0, 0, 0, $key];
        }
        if (preg_match('/^A:(\d{4})$/', $key, $match) === 1) {
            return [$match[1], 1, 0, 0, $key];
        }
        if (preg_match('/^([MO]):(\d{4})-(\d{2})$/', $key, $match) === 1) {
            return [$match[2], 2, -((int) $match[3]), $match[1] === 'M' ? 0 : 1, $key];
        }
        if (preg_match('/^E:(\d{4}):(\d+)$/', $key, $match) === 1) {
            return [$match[1], 3, 0, (int) $match[2], $key];
        }

        return ['9999', 9, 0, 0, $key];
    }

    /** @param array<string, mixed> $head */
    public static function isAdmissionHead(array $head): bool
    {
        $code = strtoupper(trim((string) ($head['code'] ?? $head['head_code'] ?? '')));
        $name = (string) ($head['name_bn'] ?? $head['head_name'] ?? '');

        return $code === 'ADMISSION' || str_contains($name, 'ভর্তি');
    }

    /** @param array<string, mixed> $head */
    public static function headMatchesInvoiceKind(array $head, string $invoiceKind): bool
    {
        $stored = (string) ($head['billing_kind'] ?? '');
        if ($invoiceKind === self::ADMISSION) {
            return $stored === self::SESSION && self::isAdmissionHead($head);
        }
        if ($invoiceKind === self::SESSION) {
            return $stored === self::SESSION && !self::isAdmissionHead($head);
        }

        return $stored === $invoiceKind;
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
     * Date used to pick a stipend or scholarship monthly rate.
     * Any effective date in the billed month counts for that whole month.
     */
    public function collectionDate(): DateTimeImmutable
    {
        if ($this->kind === self::MONTHLY) {
            return $this->referenceDate->modify('last day of this month');
        }

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
