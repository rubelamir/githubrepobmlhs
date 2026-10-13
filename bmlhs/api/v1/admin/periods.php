<?php
declare(strict_types=1);

require_once __DIR__ . '/structure_helpers.php';

$method = mobileStructureRequestMethod();
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    mobileStructureRequireAccess('periods', $identity);
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $periods = fetchRows($db, 'SELECT * FROM class_periods ORDER BY sort_order ASC, period_no ASC');
        mobileApiJson(['data' => ['periods' => mobileStructureNormalizeActiveRows($periods, ['is_active'])]]);
    }

    $payload = mobileApiReadJson(65536);
    $action = mobileStructureAction($payload, ['create', 'update', 'toggle', 'delete']);
    if ($action === 'create' || $action === 'update') {
        $periodNo = mobileStructurePositiveId($payload['period_no'] ?? null, 'period_no must be a positive integer.');
        $periodTime = mobileStructureString($payload, 'period_time', 100, true);
        $periodLabel = mobileStructureString($payload, 'period_label', 100);
        $sortOrder = mobileStructureNonNegativeInt($payload['sort_order'] ?? null);
        $isActive = mobileStructureBoolean($payload, 'is_active', true);
        if ($periodTime === null || $periodLabel === null || $sortOrder === null || $isActive === null) {
            mobileApiJson(['error' => ['code' => 'invalid_period', 'message' => 'Provide period_no, period_time, optional period_label, non-negative sort_order, and boolean is_active.']], 422);
        }
        $periodLabel = $periodLabel !== '' ? $periodLabel : periodDisplayLabel($periodNo);
        $id = $action === 'update' ? mobileStructurePositiveId($payload['id'] ?? null, 'A valid period ID is required.') : 0;
        if ($action === 'create' && $sortOrder === 0) {
            $sortOrder = $periodNo * 10;
        }
        $hasLabel = columnExists($db, 'class_periods', 'period_label');
        $values = [
            'period_no' => $periodNo, 'period_time' => $periodTime, 'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
        if ($hasLabel) {
            $values['period_label'] = $periodLabel;
        }
        if ($action === 'create') {
            $columns = 'period_no, period_time' . ($hasLabel ? ', period_label' : '') . ', sort_order, is_active';
            $placeholders = ':period_no, :period_time' . ($hasLabel ? ', :period_label' : '') . ', :sort_order, :is_active';
            $db->prepare("INSERT INTO class_periods ({$columns}) VALUES ({$placeholders})")->execute($values);
            mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'created']], 201);
        }
        mobileStructureAssertExists($db, 'class_periods', $id, 'Period');
        $sets = 'period_no = :period_no, period_time = :period_time'
            . ($hasLabel ? ', period_label = :period_label' : '')
            . ', sort_order = :sort_order, is_active = :is_active';
        $db->prepare("UPDATE class_periods SET {$sets} WHERE id = :id")->execute($values + ['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'updated']]);
    }

    $id = mobileStructurePositiveId($payload['id'] ?? null, 'A valid period ID is required.');
    mobileStructureAssertExists($db, 'class_periods', $id, 'Period');
    if ($action === 'toggle') {
        $db->prepare('UPDATE class_periods SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'toggled']]);
    }
    $db->prepare('DELETE FROM class_periods WHERE id = :id')->execute(['id' => $id]);
    mobileApiJson(['data' => ['id' => $id, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileStructureHandleDatabaseError($exception, 'periods');
} catch (Throwable $exception) {
    error_log('Mobile API admin periods failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The periods request could not be completed.']], 500);
}
