<?php
declare(strict_types=1);

require_once __DIR__ . '/structure_helpers.php';

$method = mobileStructureRequestMethod();
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    mobileStructureRequireAccess('classes', $identity);
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $classes = mobileStructureNormalizeActiveRows(schoolClasses($db), ['is_active', 'open_for_admission']);
        mobileApiJson(['data' => ['classes' => $classes]]);
    }

    $payload = mobileApiReadJson(65536);
    $action = mobileStructureAction($payload, ['create', 'update', 'toggle', 'toggle_admission', 'delete', 'move']);
    if ($action === 'create' || $action === 'update') {
        $nameEn = mobileStructureString($payload, 'class_name', 100, true);
        $nameBn = mobileStructureString($payload, 'class_name_ban', 100, true);
        $sortOrder = mobileStructureNonNegativeInt($payload['sort_order'] ?? null);
        $isActive = mobileStructureBoolean($payload, 'is_active', true);
        $openForAdmission = mobileStructureBoolean($payload, 'open_for_admission');
        if ($nameEn === null || $nameBn === null || $sortOrder === null || $isActive === null || $openForAdmission === null) {
            mobileApiJson(['error' => ['code' => 'invalid_class', 'message' => 'Provide English and Bangla names, a non-negative sort_order, and boolean status fields.']], 422);
        }
        $id = $action === 'update' ? mobileStructurePositiveId($payload['id'] ?? null, 'A valid class ID is required.') : 0;
        $duplicate = fetchRows(
            $db,
            'SELECT id FROM cls WHERE (class_name = :en OR class_name_ban = :bn) AND id <> :id LIMIT 1',
            ['en' => $nameEn, 'bn' => $nameBn, 'id' => $id]
        );
        if ($duplicate !== []) {
            mobileApiJson(['error' => ['code' => 'duplicate_class', 'message' => 'A class with that name already exists.']], 409);
        }

        if ($action === 'create') {
            if ($sortOrder === 0) {
                $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cls')->fetchColumn();
            }
            $db->prepare(
                'INSERT INTO cls (class_name, class_name_ban, sort_order, is_active, open_for_admission)
                 VALUES (:class_name, :class_name_ban, :sort_order, :is_active, :open_for_admission)'
            )->execute([
                'class_name' => $nameEn, 'class_name_ban' => $nameBn, 'sort_order' => $sortOrder,
                'is_active' => $isActive, 'open_for_admission' => $openForAdmission,
            ]);
            mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'created']], 201);
        }

        mobileStructureAssertExists($db, 'cls', $id, 'Class');
        $db->prepare(
            'UPDATE cls
             SET class_name = :class_name, class_name_ban = :class_name_ban,
                 sort_order = :sort_order, is_active = :is_active, open_for_admission = :open_for_admission
             WHERE id = :id'
        )->execute([
            'class_name' => $nameEn, 'class_name_ban' => $nameBn, 'sort_order' => $sortOrder,
            'is_active' => $isActive, 'open_for_admission' => $openForAdmission, 'id' => $id,
        ]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'updated']]);
    }

    $id = mobileStructurePositiveId($payload['id'] ?? null, 'A valid class ID is required.');
    mobileStructureAssertExists($db, 'cls', $id, 'Class');
    if ($action === 'toggle' || $action === 'toggle_admission') {
        $column = $action === 'toggle' ? 'is_active' : 'open_for_admission';
        $db->prepare("UPDATE cls SET `{$column}` = IF(`{$column}` = 1, 0, 1) WHERE id = :id")->execute(['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => $action === 'toggle' ? 'toggled' : 'admission_toggled']]);
    }
    if ($action === 'move') {
        mobileStructureMove($db, 'cls', $id, (string) ($payload['direction'] ?? ''));
        mobileApiJson(['data' => ['id' => $id, 'status' => 'moved']]);
    }

    $usage = usageSummary(classUsageCounts($db, $id));
    if ($usage !== '') {
        mobileApiJson(['error' => ['code' => 'record_in_use', 'message' => 'This class is still in use (' . $usage . '). Deactivate it instead.']], 409);
    }
    $db->prepare('DELETE FROM cls WHERE id = :id')->execute(['id' => $id]);
    mobileApiJson(['data' => ['id' => $id, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileStructureHandleDatabaseError($exception, 'classes');
} catch (Throwable $exception) {
    error_log('Mobile API admin classes failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The classes request could not be completed.']], 500);
}
