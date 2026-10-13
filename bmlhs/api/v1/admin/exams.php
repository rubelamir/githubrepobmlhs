<?php
declare(strict_types=1);

require_once __DIR__ . '/structure_helpers.php';

$method = mobileStructureRequestMethod();
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    mobileStructureRequireAccess('exams', $identity);
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        mobileApiJson(['data' => ['exams' => mobileStructureNormalizeActiveRows(schoolExams($db), ['is_active'])]]);
    }

    $payload = mobileApiReadJson(65536);
    $action = mobileStructureAction($payload, ['create', 'update', 'toggle', 'delete']);
    if ($action === 'create' || $action === 'update') {
        $nameBn = mobileStructureString($payload, 'exam_name_bangla', 100, true);
        $nameEn = mobileStructureString($payload, 'exam_name_english', 100);
        $sortOrder = mobileStructureNonNegativeInt($payload['sort_order'] ?? null);
        $isActive = mobileStructureBoolean($payload, 'is_active', true);
        if ($nameBn === null || $nameEn === null || $sortOrder === null || $isActive === null) {
            mobileApiJson(['error' => ['code' => 'invalid_exam', 'message' => 'Provide exam_name_bangla, optional exam_name_english, non-negative sort_order, and boolean is_active.']], 422);
        }
        if ($nameEn === '') {
            $nameEn = $nameBn;
        }
        $id = $action === 'update' ? mobileStructurePositiveId($payload['id'] ?? null, 'A valid exam ID is required.') : 0;
        if ($action === 'create') {
            if ($sortOrder === 0) {
                $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM exam_details')->fetchColumn();
            }
            $db->prepare(
                'INSERT INTO exam_details (exam_name_bangla, exam_name_english, sort_order, is_active)
                 VALUES (:bn, :en, :sort_order, :is_active)'
            )->execute(['bn' => $nameBn, 'en' => $nameEn, 'sort_order' => $sortOrder, 'is_active' => $isActive]);
            mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'created']], 201);
        }
        mobileStructureAssertExists($db, 'exam_details', $id, 'Exam');
        $db->prepare(
            'UPDATE exam_details
             SET exam_name_bangla = :bn, exam_name_english = :en, sort_order = :sort_order, is_active = :is_active
             WHERE id = :id'
        )->execute(['bn' => $nameBn, 'en' => $nameEn, 'sort_order' => $sortOrder, 'is_active' => $isActive, 'id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'updated']]);
    }

    $id = mobileStructurePositiveId($payload['id'] ?? null, 'A valid exam ID is required.');
    mobileStructureAssertExists($db, 'exam_details', $id, 'Exam');
    if ($action === 'toggle') {
        $db->prepare('UPDATE exam_details SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'toggled']]);
    }
    $used = tableCount($db, 'student_marks', 'exam_id = ' . $id)
        + tableCount($db, 'marks', 'exam_details_id = ' . $id);
    if ($used > 0) {
        mobileApiJson(['error' => ['code' => 'record_in_use', 'message' => 'This exam has saved results. Deactivate it instead.']], 409);
    }
    $db->prepare('DELETE FROM exam_details WHERE id = :id')->execute(['id' => $id]);
    mobileApiJson(['data' => ['id' => $id, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileStructureHandleDatabaseError($exception, 'exams');
} catch (Throwable $exception) {
    error_log('Mobile API admin exams failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The exams request could not be completed.']], 500);
}
