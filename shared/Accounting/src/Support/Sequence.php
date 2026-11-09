<?php

declare(strict_types=1);

namespace School\Accounting\Support;

use PDO;
use RuntimeException;

/**
 * Gap-free serial numbers (invoice, receipt, voucher …) per key and period.
 * Must be called inside the caller's transaction: the row stays locked until
 * commit, and a rollback returns the number.
 */
final class Sequence
{
    private function __construct()
    {
    }

    public static function next(PDO $db, string $key, string $period): int
    {
        if (!$db->inTransaction()) {
            throw new RuntimeException('Sequence::next() requires an open transaction.');
        }
        $db->prepare(
            'INSERT INTO acc_sequences (seq_key, period, next_no) VALUES (:seq_key, :period, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE next_no = LAST_INSERT_ID(next_no + 1)'
        )->execute(['seq_key' => $key, 'period' => $period]);

        return (int) $db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    /** e.g. format('INV', '2026', 42) → "INV-2026-000042" */
    public static function format(string $prefix, string $period, int $number, int $width = 6): string
    {
        return $prefix . '-' . $period . '-' . str_pad((string) $number, $width, '0', STR_PAD_LEFT);
    }
}
