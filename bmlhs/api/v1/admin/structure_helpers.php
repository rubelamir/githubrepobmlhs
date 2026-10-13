<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileStructureModuleKey(string $resource): ?string
{
    return in_array($resource, ['classes', 'sections', 'subjects', 'exams', 'periods'], true)
        ? $resource
        : null;
}

function mobileStructureRequireAccess(string $resource, array $identity): void
{
    $module = mobileStructureModuleKey($resource);
    if (
        $module === null
        || ($identity['account_type'] ?? null) !== 'staff'
        || !is_array($identity['account'] ?? null)
        || !canAccessModule($module, $identity['account'])
    ) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage this module.']], 403);
    }
}

function mobileStructureRequestMethod(): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
    }

    return $method;
}

function mobileStructurePositiveId(mixed $value, string $label = 'A valid ID is required.'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($id) || $id < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_request', 'message' => $label]], 422);
    }

    return $id;
}

function mobileStructureNonNegativeInt(mixed $value, int $default = 0): ?int
{
    if ($value === null || $value === '') {
        return $default;
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);

    return is_int($number) && $number >= 0 ? $number : null;
}

function mobileStructureString(array $payload, string $key, int $maxLength, bool $required = false): ?string
{
    $value = $payload[$key] ?? '';
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $maxLength) {
        return null;
    }

    return $value;
}

function mobileStructureBoolean(array $payload, string $key, bool $default = false): ?int
{
    if (!array_key_exists($key, $payload)) {
        return $default ? 1 : 0;
    }
    $value = $payload[$key];
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if ($value === 1 || $value === '1') {
        return 1;
    }
    if ($value === 0 || $value === '0') {
        return 0;
    }

    return null;
}

function mobileStructureAction(array $payload, array $allowed): string
{
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, $allowed, true)) {
        mobileApiJson([
            'error' => [
                'code' => 'invalid_action',
                'message' => 'Choose one of: ' . implode(', ', $allowed) . '.',
            ],
        ], 422);
    }

    return $action;
}

function mobileStructureAssertExists(PDO $db, string $table, int $id, string $label): void
{
    $allowedTables = ['cls', 'sections', 'class_subjects', 'exam_details', 'class_periods'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unknown structure table.');
    }
    $rows = fetchRows($db, "SELECT id FROM `{$table}` WHERE id = :id LIMIT 1", ['id' => $id]);
    if ($rows === []) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => $label . ' not found.']], 404);
    }
}

function mobileStructureMove(PDO $db, string $table, int $id, string $direction, array $filters = []): void
{
    $allowedTables = ['cls', 'sections', 'class_subjects'];
    if (!in_array($table, $allowedTables, true) || !in_array($direction, ['up', 'down'], true)) {
        mobileApiJson(['error' => ['code' => 'invalid_request', 'message' => 'A valid ID and up/down direction are required.']], 422);
    }
    $where = [];
    foreach ($filters as $column => $value) {
        if (!in_array($column, ['class_id', 'section_id'], true)) {
            throw new InvalidArgumentException('Invalid structure filter.');
        }
        $where[] = "`{$column}` = :{$column}";
    }
    $whereSql = $where === [] ? '' : ' AND ' . implode(' AND ', $where);
    $rows = fetchRows(
        $db,
        "SELECT id, sort_order FROM `{$table}` WHERE 1 = 1{$whereSql} ORDER BY sort_order ASC, id ASC",
        $filters
    );
    $index = null;
    foreach ($rows as $position => $row) {
        if ((int) $row['id'] === $id) {
            $index = $position;
            break;
        }
    }
    if ($index === null) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Record not found in this list.']], 404);
    }
    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if (!isset($rows[$target])) {
        return;
    }
    $update = $db->prepare("UPDATE `{$table}` SET sort_order = :sort WHERE id = :id");
    $update->execute(['sort' => (int) $rows[$target]['sort_order'], 'id' => (int) $rows[$index]['id']]);
    $update->execute(['sort' => (int) $rows[$index]['sort_order'], 'id' => (int) $rows[$target]['id']]);
}

function mobileStructureNormalizeActiveRows(array $rows, array $flags = ['is_active']): array
{
    foreach ($rows as &$row) {
        foreach ($flags as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (bool) $row[$field];
            }
        }
    }
    unset($row);

    return $rows;
}

function mobileStructureHandleDatabaseError(PDOException $exception, string $resource): never
{
    mobileApiDatabaseFailure($exception, 'admin ' . $resource);
}
