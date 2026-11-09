<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use DateTimeImmutable;
use PDO;

final class ApprovalWorkflow
{
    private function __construct()
    {
    }

    /**
     * Before approving a dated assignment, closes the approved ones it overlaps:
     * those that started earlier end the day before; those starting on/after it are ended.
     *
     * @param array<string, int|string> $scope column => value identifying the same subject
     */
    public static function supersede(PDO $db, string $table, array $scope, string $newFrom, int $exceptId): void
    {
        $where = [];
        $params = ['status' => ApprovalStatus::APPROVED, 'except' => $exceptId, 'from' => $newFrom];
        foreach ($scope as $column => $value) {
            $where[] = $column . ' = :scope_' . $column;
            $params['scope_' . $column] = $value;
        }
        $statement = $db->prepare(
            'SELECT id, effective_from FROM ' . $table . '
             WHERE ' . implode(' AND ', $where) . ' AND status = :status AND id <> :except
               AND (effective_to IS NULL OR effective_to >= :from)
             FOR UPDATE'
        );
        $statement->execute($params);
        $dayBefore = (new DateTimeImmutable($newFrom))->modify('-1 day')->format('Y-m-d');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string) $row['effective_from'] < $newFrom) {
                $db->prepare('UPDATE ' . $table . ' SET effective_to = :to WHERE id = :id')
                    ->execute(['to' => $dayBefore, 'id' => (int) $row['id']]);
            } else {
                $db->prepare('UPDATE ' . $table . ' SET status = :ended, effective_to = effective_from WHERE id = :id')
                    ->execute(['ended' => ApprovalStatus::ENDED, 'id' => (int) $row['id']]);
            }
        }
    }
}
