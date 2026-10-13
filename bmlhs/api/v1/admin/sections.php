<?php
declare(strict_types=1);

require_once __DIR__ . '/structure_helpers.php';

$method = mobileStructureRequestMethod();
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    mobileStructureRequireAccess('sections', $identity);
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $classId = isset($_GET['class_id'])
            ? mobileStructurePositiveId($_GET['class_id'], 'class_id must be a positive integer.')
            : null;
        $sections = mobileStructureNormalizeActiveRows(schoolSections($db, $classId), ['is_active']);
        mobileApiJson(['data' => ['sections' => $sections, 'class_id' => $classId]]);
    }

    $payload = mobileApiReadJson(65536);
    $action = mobileStructureAction($payload, ['create', 'update', 'toggle', 'delete', 'move']);
    if ($action === 'create' || $action === 'update') {
        $classId = mobileStructurePositiveId($payload['class_id'] ?? null, 'A valid class_id is required.');
        mobileStructureAssertExists($db, 'cls', $classId, 'Class');
        $nameEn = mobileStructureString($payload, 'section_name', 100, true);
        $nameBn = mobileStructureString($payload, 'section_name_ban', 100, true);
        $sortOrder = mobileStructureNonNegativeInt($payload['sort_order'] ?? null);
        $isActive = mobileStructureBoolean($payload, 'is_active', true);
        if ($nameEn === null || $nameBn === null || $sortOrder === null || $isActive === null) {
            mobileApiJson(['error' => ['code' => 'invalid_section', 'message' => 'Provide English and Bangla names, a non-negative sort_order, and boolean is_active.']], 422);
        }
        $id = $action === 'update' ? mobileStructurePositiveId($payload['id'] ?? null, 'A valid section ID is required.') : 0;
        $duplicate = fetchRows(
            $db,
            'SELECT id FROM sections
             WHERE class_id = :class_id AND (section_name = :en OR section_name_ban = :bn) AND id <> :id
             LIMIT 1',
            ['class_id' => $classId, 'en' => $nameEn, 'bn' => $nameBn, 'id' => $id]
        );
        if ($duplicate !== []) {
            mobileApiJson(['error' => ['code' => 'duplicate_section', 'message' => 'That section already exists in this class.']], 409);
        }
        if ($action === 'create') {
            if ($sortOrder === 0) {
                $statement = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM sections WHERE class_id = :class_id');
                $statement->execute(['class_id' => $classId]);
                $sortOrder = (int) $statement->fetchColumn();
            }
            $db->prepare(
                'INSERT INTO sections (class_id, section_name, section_name_ban, sort_order, is_active)
                 VALUES (:class_id, :section_name, :section_name_ban, :sort_order, :is_active)'
            )->execute([
                'class_id' => $classId, 'section_name' => $nameEn, 'section_name_ban' => $nameBn,
                'sort_order' => $sortOrder, 'is_active' => $isActive,
            ]);
            mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'class_id' => $classId, 'status' => 'created']], 201);
        }

        mobileStructureAssertExists($db, 'sections', $id, 'Section');
        $db->prepare(
            'UPDATE sections
             SET class_id = :class_id, section_name = :section_name, section_name_ban = :section_name_ban,
                 sort_order = :sort_order, is_active = :is_active
             WHERE id = :id'
        )->execute([
            'class_id' => $classId, 'section_name' => $nameEn, 'section_name_ban' => $nameBn,
            'sort_order' => $sortOrder, 'is_active' => $isActive, 'id' => $id,
        ]);
        mobileApiJson(['data' => ['id' => $id, 'class_id' => $classId, 'status' => 'updated']]);
    }

    $id = mobileStructurePositiveId($payload['id'] ?? null, 'A valid section ID is required.');
    mobileStructureAssertExists($db, 'sections', $id, 'Section');
    $row = fetchRows($db, 'SELECT class_id FROM sections WHERE id = :id', ['id' => $id])[0];
    $classId = (int) $row['class_id'];
    if ($action === 'toggle') {
        $db->prepare('UPDATE sections SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'toggled']]);
    }
    if ($action === 'move') {
        mobileStructureMove($db, 'sections', $id, (string) ($payload['direction'] ?? ''), ['class_id' => $classId]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'moved']]);
    }
    $usage = usageSummary(sectionUsageCounts($db, $id));
    if ($usage !== '') {
        mobileApiJson(['error' => ['code' => 'record_in_use', 'message' => 'This section is still in use (' . $usage . '). Deactivate it instead.']], 409);
    }
    $db->prepare('DELETE FROM sections WHERE id = :id')->execute(['id' => $id]);
    mobileApiJson(['data' => ['id' => $id, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileStructureHandleDatabaseError($exception, 'sections');
} catch (Throwable $exception) {
    error_log('Mobile API admin sections failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The sections request could not be completed.']], 500);
}
