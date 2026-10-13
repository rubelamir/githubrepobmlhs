<?php
declare(strict_types=1);

require_once __DIR__ . '/structure_helpers.php';

$method = mobileStructureRequestMethod();
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    mobileStructureRequireAccess('subjects', $identity);
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $classes = schoolClasses($db);
        $classId = isset($_GET['class_id'])
            ? mobileStructurePositiveId($_GET['class_id'], 'class_id must be a positive integer.')
            : null;
        $sectionId = isset($_GET['section_id'])
            ? mobileStructureNonNegativeInt($_GET['section_id'])
            : 0;
        if ($sectionId === null) {
            mobileApiJson(['error' => ['code' => 'invalid_request', 'message' => 'section_id must be a non-negative integer.']], 422);
        }
        $sections = $classId === null ? [] : schoolSections($db, $classId);
        if ($classId !== null && $sectionId > 0) {
            $validSection = fetchRows(
                $db,
                'SELECT id FROM sections WHERE id = :section_id AND class_id = :class_id LIMIT 1',
                ['section_id' => $sectionId, 'class_id' => $classId]
            );
            if ($validSection === []) {
                mobileApiJson(['error' => ['code' => 'invalid_section', 'message' => 'section_id does not belong to class_id.']], 422);
            }
        }
        $subjects = [];
        if ($classId !== null) {
            $subjects = fetchRows(
                $db,
                'SELECT * FROM class_subjects
                 WHERE class_id = :class_id AND section_id = :section_id
                 ORDER BY sort_order ASC, id ASC',
                ['class_id' => $classId, 'section_id' => $sectionId]
            );
        }
        mobileApiJson([
            'data' => [
                'classes' => $classes,
                'sections' => $sections,
                'subjects' => mobileStructureNormalizeActiveRows($subjects, ['has_mcq', 'has_cq', 'has_practical', 'is_optional', 'is_active']),
                'class_id' => $classId,
                'section_id' => $sectionId,
            ],
        ]);
    }

    $payload = mobileApiReadJson(65536);
    $action = mobileStructureAction($payload, ['create', 'update', 'toggle', 'delete', 'move']);
    if ($action === 'create' || $action === 'update') {
        $classId = mobileStructurePositiveId($payload['class_id'] ?? null, 'A valid class_id is required.');
        mobileStructureAssertExists($db, 'cls', $classId, 'Class');
        $sectionId = mobileStructureNonNegativeInt($payload['section_id'] ?? null);
        if ($sectionId === null) {
            mobileApiJson(['error' => ['code' => 'invalid_subject', 'message' => 'section_id must be a non-negative integer.']], 422);
        }
        if ($sectionId > 0) {
            $validSection = fetchRows(
                $db,
                'SELECT id FROM sections WHERE id = :section_id AND class_id = :class_id LIMIT 1',
                ['section_id' => $sectionId, 'class_id' => $classId]
            );
            if ($validSection === []) {
                mobileApiJson(['error' => ['code' => 'invalid_section', 'message' => 'section_id does not belong to class_id.']], 422);
            }
        }
        $nameEn = mobileStructureString($payload, 'subject_name', 100, true);
        $nameBn = mobileStructureString($payload, 'subject_name_ban', 100, true);
        $code = mobileStructureString($payload, 'subject_code', 32);
        $teacherMobile = mobileStructureString($payload, 'teacher_mobile', 32);
        $sortOrder = mobileStructureNonNegativeInt($payload['sort_order'] ?? null);
        $fullMarks = mobileStructurePositiveId($payload['full_marks'] ?? 100, 'full_marks must be a positive integer.');
        $passMarks = mobileStructureNonNegativeInt($payload['pass_marks'] ?? 33);
        $hasMcq = mobileStructureBoolean($payload, 'has_mcq');
        $hasCq = mobileStructureBoolean($payload, 'has_cq');
        $hasPractical = mobileStructureBoolean($payload, 'has_practical');
        $isOptional = mobileStructureBoolean($payload, 'is_optional');
        $isActive = mobileStructureBoolean($payload, 'is_active', true);
        if (
            $nameEn === null || $nameBn === null || $code === null || $teacherMobile === null
            || $sortOrder === null || $passMarks === null || $passMarks > $fullMarks
            || $hasMcq === null || $hasCq === null || $hasPractical === null || $isOptional === null || $isActive === null
        ) {
            mobileApiJson(['error' => ['code' => 'invalid_subject', 'message' => 'Provide both subject names, valid optional text, valid marks (pass_marks ≤ full_marks), sort_order, and boolean flags.']], 422);
        }
        $id = $action === 'update' ? mobileStructurePositiveId($payload['id'] ?? null, 'A valid subject ID is required.') : 0;
        if ($action === 'create' && $sortOrder === 0) {
            $statement = $db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM class_subjects
                 WHERE class_id = :class_id AND section_id = :section_id'
            );
            $statement->execute(['class_id' => $classId, 'section_id' => $sectionId]);
            $sortOrder = (int) $statement->fetchColumn();
        }
        $values = [
            'class_id' => $classId, 'section_id' => $sectionId, 'subject_name' => $nameEn,
            'subject_name_ban' => $nameBn, 'subject_code' => $code !== '' ? $code : null,
            'has_mcq' => $hasMcq, 'has_cq' => $hasCq, 'has_practical' => $hasPractical,
            'full_marks' => $fullMarks, 'pass_marks' => $passMarks, 'is_optional' => $isOptional,
            'sort_order' => $sortOrder, 'is_active' => $isActive,
            'teacher_mobile' => $teacherMobile !== '' ? $teacherMobile : null,
        ];
        if ($action === 'create') {
            $db->prepare(
                'INSERT INTO class_subjects
                    (class_id, section_id, subject_name, subject_name_ban, subject_code, has_mcq, has_cq,
                     has_practical, full_marks, pass_marks, is_optional, sort_order, is_active, teacher_mobile)
                 VALUES
                    (:class_id, :section_id, :subject_name, :subject_name_ban, :subject_code, :has_mcq, :has_cq,
                     :has_practical, :full_marks, :pass_marks, :is_optional, :sort_order, :is_active, :teacher_mobile)'
            )->execute($values);
            mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'created']], 201);
        }
        mobileStructureAssertExists($db, 'class_subjects', $id, 'Subject');
        $db->prepare(
            'UPDATE class_subjects
             SET class_id = :class_id, section_id = :section_id, subject_name = :subject_name,
                 subject_name_ban = :subject_name_ban, subject_code = :subject_code, has_mcq = :has_mcq,
                 has_cq = :has_cq, has_practical = :has_practical, full_marks = :full_marks,
                 pass_marks = :pass_marks, is_optional = :is_optional, sort_order = :sort_order,
                 is_active = :is_active, teacher_mobile = :teacher_mobile
             WHERE id = :id'
        )->execute($values + ['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'updated']]);
    }

    $id = mobileStructurePositiveId($payload['id'] ?? null, 'A valid subject ID is required.');
    mobileStructureAssertExists($db, 'class_subjects', $id, 'Subject');
    $row = fetchRows($db, 'SELECT class_id, section_id FROM class_subjects WHERE id = :id', ['id' => $id])[0];
    if ($action === 'toggle') {
        $db->prepare('UPDATE class_subjects SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
        mobileApiJson(['data' => ['id' => $id, 'status' => 'toggled']]);
    }
    if ($action === 'move') {
        mobileStructureMove(
            $db,
            'class_subjects',
            $id,
            (string) ($payload['direction'] ?? ''),
            ['class_id' => (int) $row['class_id'], 'section_id' => (int) $row['section_id']]
        );
        mobileApiJson(['data' => ['id' => $id, 'status' => 'moved']]);
    }
    if (usageSummary(subjectUsageCounts($db, $id)) !== '') {
        mobileApiJson(['error' => ['code' => 'record_in_use', 'message' => 'This subject has saved marks. Deactivate it instead.']], 409);
    }
    $db->prepare('DELETE FROM class_subjects WHERE id = :id')->execute(['id' => $id]);
    mobileApiJson(['data' => ['id' => $id, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileStructureHandleDatabaseError($exception, 'subjects');
} catch (Throwable $exception) {
    error_log('Mobile API admin subjects failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The subjects request could not be completed.']], 500);
}
