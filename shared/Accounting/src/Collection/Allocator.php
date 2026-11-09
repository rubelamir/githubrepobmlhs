<?php

declare(strict_types=1);

namespace School\Accounting\Collection;

use RuntimeException;

/**
 * Splits a received amount over outstanding invoice lines in the given order
 * (callers pass oldest due first). Whatever is left over is the advance.
 */
final class Allocator
{
    private function __construct()
    {
    }

    /**
     * @param list<array{id: int, outstanding: int}> $lines amounts in paisa
     * @return array{lines: array<int, int>, remainder: int} line id => paisa
     */
    public static function allocate(array $lines, int $amount): array
    {
        if ($amount < 0) {
            throw new RuntimeException('Negative amount.');
        }
        $allocated = [];
        foreach ($lines as $line) {
            if ($amount === 0) {
                break;
            }
            $outstanding = (int) $line['outstanding'];
            if ($outstanding <= 0) {
                continue;
            }
            $take = min($outstanding, $amount);
            $allocated[(int) $line['id']] = $take;
            $amount -= $take;
        }

        return ['lines' => $allocated, 'remainder' => $amount];
    }
}
