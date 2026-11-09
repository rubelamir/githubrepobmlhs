<?php

declare(strict_types=1);

namespace School\Accounting\Support;

use RuntimeException;

/**
 * Money is handled as integer paisa (1 taka = 100 paisa) everywhere in PHP and
 * stored as DECIMAL(14,2). Floats are never used for amounts.
 */
final class Money
{
    private const MAX_TAKA_DIGITS = 10;
    private const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    private function __construct()
    {
    }

    /**
     * Parses user input such as "1,250", "১২৫০.৫০" or "0.5" into paisa.
     * Negative values, more than two decimals and non-numeric text are rejected.
     */
    public static function parse(string $input, string $fieldLabel = 'টাকার পরিমাণ'): int
    {
        $value = str_replace(self::BN_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], trim($input));
        $value = str_replace([',', '৳', ' ', "\u{00A0}"], '', $value);
        if (preg_match('/^(\d{1,' . self::MAX_TAKA_DIGITS . '})(?:\.(\d{1,2}))?$/', $value, $match) !== 1) {
            throw new RuntimeException($fieldLabel . ' সঠিক নয় (যেমন ১২৫০ বা ১২৫০.৫০)।');
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    }

    /** Optional input: '' means "not set". */
    public static function parseOptional(string $input, string $fieldLabel = 'টাকার পরিমাণ'): ?int
    {
        return trim($input) === '' ? null : self::parse($input, $fieldLabel);
    }

    /** Exact conversion of a DECIMAL column value ("-12.30") to paisa. */
    public static function fromDb(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $text = trim((string) $value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/', $text, $match) !== 1) {
            throw new RuntimeException('Invalid stored amount: ' . $text);
        }
        $paisa = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '0', 2, '0');

        return $match[1] === '-' ? -$paisa : $paisa;
    }

    public static function toDb(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $paisa = abs($paisa);

        return $sign . intdiv($paisa, 100) . '.' . str_pad((string) ($paisa % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Plain editable value for form inputs: 125000 → "1250", 125050 → "1250.50". */
    public static function toInput(int $paisa): string
    {
        return $paisa % 100 === 0 ? (string) intdiv($paisa, 100) : self::toDb($paisa);
    }

    /**
     * "৳ ১২,৩৪,৫৬৭.০০" — South Asian grouping (thousand, lakh, crore) with Bengali digits.
     */
    public static function format(int $paisa, bool $symbol = true, bool $bengali = true): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $paisa = abs($paisa);
        $taka = (string) intdiv($paisa, 100);
        $fraction = str_pad((string) ($paisa % 100), 2, '0', STR_PAD_LEFT);
        if (strlen($taka) > 3) {
            $head = substr($taka, 0, -3);
            $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head) ?? $head;
            $taka = $head . ',' . substr($taka, -3);
        }
        $text = $sign . $taka . '.' . $fraction;
        if ($bengali) {
            $text = str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::BN_DIGITS, $text);
        }

        return ($symbol ? '৳ ' : '') . $text;
    }

    /**
     * Percentage of an amount, rounded half up to the paisa.
     * $basisPoints: 100% = 10000, 12.5% = 1250.
     */
    public static function percentOf(int $paisa, int $basisPoints): int
    {
        if ($paisa < 0 || $basisPoints < 0) {
            throw new RuntimeException('Negative percentage input.');
        }

        return intdiv($paisa * $basisPoints + 5000, 10000);
    }

    /** "12.5" / "১০০" → basis points; accepts 0–100 with up to two decimals. */
    public static function parsePercent(string $input): int
    {
        $basisPoints = self::parse($input, 'শতাংশ');
        if ($basisPoints > 10000) {
            throw new RuntimeException('শতাংশ ০ থেকে ১০০-এর মধ্যে হতে হবে।');
        }

        return $basisPoints;
    }
}
