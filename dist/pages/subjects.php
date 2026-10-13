<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Subjects | School Admin';

$classes = schoolClasses($db);
$teachers = schoolTeachers($db);
$firstClassId = $classes === [] ? 0 : (int) $classes[0]['id'];

function subjectsRedirect(int $classId, int $sectionId, string $type, string $message): void
{
    redirectWithMessage(
        'subjects.php?class_id=' . $classId . '&section_id=' . $sectionId,
        $type,
        $message
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    $classId = (int) ($_POST['class_id'] ?? 0);
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    try {
        if ($classId <= 0) {
            throw new RuntimeException('Pick a class first.');
        }

        if ($action === 'copy') {
            $sourceSelection = (string) ($_POST['source'] ?? '');
            if (!preg_match('/\A([1-9][0-9]*):(0|[1-9][0-9]*)\z/', $sourceSelection, $sourceParts)) {
                throw new RuntimeException('Pick a valid source class/section.');
            }
            $fromClassId = (int) $sourceParts[1];
            $fromSectionId = (int) $sourceParts[2];
            $classIds = array_map(static fn (array $class): int => (int) $class['id'], $classes);
            if (!in_array($classId, $classIds, true) || !in_array($fromClassId, $classIds, true)) {
                throw new RuntimeException('Pick a valid source and destination class.');
            }

            $destinationSectionIds = array_map(
                static fn (array $section): int => (int) $section['id'],
                schoolSections($db, $classId)
            );
            $sourceSectionIds = array_map(
                static fn (array $section): int => (int) $section['id'],
                schoolSections($db, $fromClassId)
            );
            if (
                ($sectionId !== 0 && !in_array($sectionId, $destinationSectionIds, true))
                || ($fromSectionId !== 0 && !in_array($fromSectionId, $sourceSectionIds, true))
            ) {
                throw new RuntimeException('Pick a valid source and destination section.');
            }
            if ($classId === $fromClassId && $sectionId === $fromSectionId) {
                throw new RuntimeException('Choose a different source class/section.');
            }

            $destinationCheck = $db->prepare(
                'SELECT COUNT(*) FROM class_subjects WHERE class_id = :class_id AND section_id = :section_id'
            );
            $destinationCheck->execute(['class_id' => $classId, 'section_id' => $sectionId]);
            if ((int) $destinationCheck->fetchColumn() > 0) {
                throw new RuntimeException('The destination class/section already has its own subjects.');
            }

            $source = classSubjects($db, $fromClassId, $fromSectionId, false);
            if ($source === []) {
                throw new RuntimeException('The source class/section has no subjects to copy.');
            }
            $insert = $db->prepare(
                'INSERT INTO class_subjects
                    (class_id, section_id, subject_name, subject_name_ban, subject_code, has_mcq, has_cq,
                     has_practical, full_marks, pass_marks, is_optional, sort_order, is_active, teacher_mobile)
                 VALUES
                    (:class_id, :section_id, :subject_name, :subject_name_ban, :subject_code, :has_mcq, :has_cq,
                     :has_practical, :full_marks, :pass_marks, :is_optional, :sort_order, :is_active, :teacher_mobile)'
            );
            $db->beginTransaction();
            try {
                foreach ($source as $row) {
                    $insert->execute([
                        'class_id' => $classId,
                        'section_id' => $sectionId,
                        'subject_name' => $row['subject_name'],
                        'subject_name_ban' => $row['subject_name_ban'],
                        'subject_code' => $row['subject_code'] ?: null,
                        'has_mcq' => (int) $row['has_mcq'],
                        'has_cq' => (int) $row['has_cq'],
                        'has_practical' => (int) $row['has_practical'],
                        'full_marks' => (int) $row['full_marks'],
                        'pass_marks' => (int) $row['pass_marks'],
                        'is_optional' => (int) $row['is_optional'],
                        'sort_order' => (int) $row['sort_order'],
                        'is_active' => (int) $row['is_active'],
                        'teacher_mobile' => $row['teacher_mobile'] ?: null,
                    ]);
                }
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollBack();
                throw $exception;
            }
            subjectsRedirect($classId, $sectionId, 'success', 'Subjects copied. You can now edit names, marks and teachers.');
        }

        if ($action === 'create' || $action === 'update') {
            $nameEn = trim((string) ($_POST['subject_name'] ?? ''));
            $nameBn = trim((string) ($_POST['subject_name_ban'] ?? ''));
            $code = trim((string) ($_POST['subject_code'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $fullMarks = (int) ($_POST['full_marks'] ?? 100);
            $passMarks = (int) ($_POST['pass_marks'] ?? 33);
            $teacherMobile = trim((string) ($_POST['teacher_mobile'] ?? ''));
            $hasMcq = isset($_POST['has_mcq']) ? 1 : 0;
            $hasCq = isset($_POST['has_cq']) ? 1 : 0;
            $hasPractical = isset($_POST['has_practical']) ? 1 : 0;
            $isOptional = isset($_POST['is_optional']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($nameEn === '' || $nameBn === '') {
                throw new RuntimeException('Both the English and Bangla subject name are required.');
            }
            if ($fullMarks < 1) {
                throw new RuntimeException('Full marks must be at least 1.');
            }

            if ($action === 'create') {
                if ($sortOrder === 0) {
                    $statement = $db->prepare(
                        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM class_subjects
                         WHERE class_id = :class_id AND section_id = :section_id'
                    );
                    $statement->execute(['class_id' => $classId, 'section_id' => $sectionId]);
                    $sortOrder = (int) $statement->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO class_subjects
                        (class_id, section_id, subject_name, subject_name_ban, subject_code, has_mcq, has_cq,
                         has_practical, full_marks, pass_marks, is_optional, sort_order, is_active, teacher_mobile)
                     VALUES
                        (:class_id, :section_id, :subject_name, :subject_name_ban, :subject_code, :has_mcq, :has_cq,
                         :has_practical, :full_marks, :pass_marks, :is_optional, :sort_order, :is_active, :teacher_mobile)'
                )->execute([
                    'class_id' => $classId,
                    'section_id' => $sectionId,
                    'subject_name' => $nameEn,
                    'subject_name_ban' => $nameBn,
                    'subject_code' => $code !== '' ? $code : null,
                    'has_mcq' => $hasMcq,
                    'has_cq' => $hasCq,
                    'has_practical' => $hasPractical,
                    'full_marks' => $fullMarks,
                    'pass_marks' => $passMarks,
                    'is_optional' => $isOptional,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'teacher_mobile' => $teacherMobile !== '' ? $teacherMobile : null,
                ]);
                subjectsRedirect($classId, $sectionId, 'success', 'Subject added.');
            }

            $id = postedRecordId('subject');
            if ($id <= 0) {
                throw new RuntimeException('Invalid subject.');
            }
            $db->prepare(
                'UPDATE class_subjects
                 SET class_id = :class_id, section_id = :section_id, subject_name = :subject_name,
                     subject_name_ban = :subject_name_ban, subject_code = :subject_code, has_mcq = :has_mcq,
                     has_cq = :has_cq, has_practical = :has_practical, full_marks = :full_marks,
                     pass_marks = :pass_marks, is_optional = :is_optional, sort_order = :sort_order,
                     is_active = :is_active, teacher_mobile = :teacher_mobile
                 WHERE id = :id'
            )->execute([
                'class_id' => $classId,
                'section_id' => $sectionId,
                'subject_name' => $nameEn,
                'subject_name_ban' => $nameBn,
                'subject_code' => $code !== '' ? $code : null,
                'has_mcq' => $hasMcq,
                'has_cq' => $hasCq,
                'has_practical' => $hasPractical,
                'full_marks' => $fullMarks,
                'pass_marks' => $passMarks,
                'is_optional' => $isOptional,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'teacher_mobile' => $teacherMobile !== '' ? $teacherMobile : null,
                'id' => $id,
            ]);
            subjectsRedirect($classId, $sectionId, 'success', 'Subject updated.');
        }

        if ($action === 'toggle') {
            $db->prepare('UPDATE class_subjects SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('subject')]);
            subjectsRedirect($classId, $sectionId, 'success', 'Subject availability updated.');
        }

        if ($action === 'delete') {
            $id = postedRecordId('subject');
            $usage = usageSummary(subjectUsageCounts($db, $id));
            if ($usage !== '') {
                subjectsRedirect(
                    $classId,
                    $sectionId,
                    'danger',
                    'This subject still has ' . $usage . '. Deactivate it instead of deleting, so existing results stay labelled.'
                );
            }
            $db->prepare('DELETE FROM class_subjects WHERE id = :id')->execute(['id' => $id]);
            subjectsRedirect($classId, $sectionId, 'success', 'Subject deleted.');
        }

        if ($action === 'move') {
            $id = postedRecordId('subject');
            $direction = (string) ($_POST['direction'] ?? '');
            $rows = fetchRows(
                $db,
                'SELECT id, sort_order FROM class_subjects
                 WHERE class_id = :class_id AND section_id = :section_id
                 ORDER BY sort_order ASC, id ASC',
                ['class_id' => $classId, 'section_id' => $sectionId]
            );
            $index = null;
            foreach ($rows as $i => $row) {
                if ((int) $row['id'] === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException('Subject not found in this class/section.');
            }
            $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
            if ($target === null || !isset($rows[$target])) {
                subjectsRedirect($classId, $sectionId, 'info', 'Already at the edge of the list.');
            }
            $a = $rows[$index];
            $b = $rows[$target];
            $swap = $db->prepare('UPDATE class_subjects SET sort_order = :sort WHERE id = :id');
            $swap->execute(['sort' => (int) $b['sort_order'], 'id' => (int) $a['id']]);
            $swap->execute(['sort' => (int) $a['sort_order'], 'id' => (int) $b['id']]);
            subjectsRedirect($classId, $sectionId, 'success', 'Subject order updated.');
        }
    } catch (Throwable $exception) {
        subjectsRedirect($classId > 0 ? $classId : $firstClassId, $sectionId, 'danger', userFacingError($exception));
    }
}

$selectedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : $firstClassId;
$classSections = $selectedClassId > 0 ? schoolSections($db, $selectedClassId) : [];
$selectedSectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : 0;
if ($selectedSectionId === 0 && $classSections !== []) {
    $selectedSectionId = (int) $classSections[0]['id'];
}

$editId = requestRecordId('subject');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM class_subjects WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
    if ($editRow) {
        $selectedClassId = (int) $editRow['class_id'];
        $selectedSectionId = (int) $editRow['section_id'];
        $classSections = schoolSections($db, $selectedClassId);
    }
}

$subjects = $selectedClassId > 0 ? classSubjects($db, $selectedClassId, $selectedSectionId, false) : [];
$targetOwnSubjectCount = 0;
if ($selectedClassId > 0) {
    $targetCheck = $db->prepare(
        'SELECT COUNT(*) FROM class_subjects WHERE class_id = :class_id AND section_id = :section_id'
    );
    $targetCheck->execute(['class_id' => $selectedClassId, 'section_id' => $selectedSectionId]);
    $targetOwnSubjectCount = (int) $targetCheck->fetchColumn();
}
$copySources = [];
$allSections = schoolSections($db);
foreach ($classes as $class) {
    $copyClassId = (int) $class['id'];
    $classLabel = trim((string) ($class['class_name_ban'] ?? '') . ' — ' . (string) ($class['class_name'] ?? ''));
    $copySources[] = [
        'class_id' => $copyClassId,
        'section_id' => 0,
        'label' => $classLabel . ' — Entire class (shared list)',
    ];
    foreach ($allSections as $section) {
        if ((int) $section['class_id'] !== $copyClassId) {
            continue;
        }
        $copySources[] = [
            'class_id' => $copyClassId,
            'section_id' => (int) $section['id'],
            'label' => $classLabel . ' — ' . (string) $section['section_name_ban'],
        ];
    }
}
$inherited = $subjects !== []
    && $selectedSectionId > 0
    && (int) ($subjects[0]['section_id'] ?? $selectedSectionId) === 0;
$teacherByMobile = [];
foreach ($teachers as $teacher) {
    $teacherByMobile[(string) $teacher['teacher_mobile']] = (string) $teacher['teacher_name'];
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Subjects</h3>
            <p class="text-secondary mb-0">
              Any number of papers, in any order, with their own MCQ / written / practical flags and full marks.
              Result entry and the class routine both read this list — there is no 14-subject ceiling.
            </p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <?php if ($classes === []): ?>
              <div class="alert alert-warning">
                No classes exist yet. <a href="classes.php">Add a class</a> before creating subjects.
              </div>
            <?php else: ?>
              <div class="card stat-card mb-3">
                <div class="card-body">
                  <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                      <label class="form-label">Class</label>
                      <select class="form-select" name="class_id" id="filterClass" onchange="this.form.submit()">
                        <?php foreach ($classes as $class): ?>
                          <option value="<?= (int) $class['id'] ?>" <?= (int) $class['id'] === $selectedClassId ? 'selected' : '' ?>>
                            <?= h((string) $class['class_name_ban']) ?> — <?= h((string) $class['class_name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Section / group</label>
                      <select class="form-select" name="section_id">
                        <option value="0" <?= $selectedSectionId === 0 ? 'selected' : '' ?>>Entire class (no section)</option>
                        <?php foreach ($classSections as $section): ?>
                          <option value="<?= (int) $section['id'] ?>" <?= (int) $section['id'] === $selectedSectionId ? 'selected' : '' ?>>
                            <?= h((string) $section['section_name_ban']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <button class="btn btn-outline-secondary">Show</button>
                    </div>
                  </form>
                </div>
              </div>

              <?php if ($inherited): ?>
                <div class="alert alert-info">
                  This section has no subjects of its own, so it is currently using the class-wide list.
                  Add a subject below to give this section its own curriculum (Science / Arts / Hifz, etc.).
                </div>
              <?php endif; ?>

              <div class="card stat-card mb-3">
                <div class="card-header">
                  <h5 class="mb-0"><?= $editRow ? 'Edit subject' : 'Add subject' ?></h5>
                </div>
                <div class="card-body">
                  <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                    <?php if ($editRow): ?>
                      <?= signedRecordField((int) $editRow['id'], 'subject') ?>
                    <?php endif; ?>
                    <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                    <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                    <div class="col-md-4">
                      <label class="form-label">Name (Bangla)</label>
                      <input class="form-control" name="subject_name_ban" required
                             value="<?= h((string) ($editRow['subject_name_ban'] ?? '')) ?>" placeholder="যেমন: বাংলা">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Name (English)</label>
                      <input class="form-control" name="subject_name" required
                             value="<?= h((string) ($editRow['subject_name'] ?? '')) ?>" placeholder="Bangla">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Code (optional)</label>
                      <input class="form-control" name="subject_code"
                             value="<?= h((string) ($editRow['subject_code'] ?? '')) ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Assigned teacher</label>
                      <select class="form-select" name="teacher_mobile">
                        <option value="">— None yet —</option>
                        <?php foreach ($teachers as $teacher): ?>
                          <option value="<?= h((string) $teacher['teacher_mobile']) ?>"
                            <?= (string) ($editRow['teacher_mobile'] ?? '') === (string) $teacher['teacher_mobile'] ? 'selected' : '' ?>>
                            <?= h((string) $teacher['teacher_name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Full marks</label>
                      <input class="form-control" type="number" name="full_marks" min="1"
                             value="<?= h((string) ($editRow['full_marks'] ?? '100')) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Pass marks</label>
                      <input class="form-control" type="number" name="pass_marks" min="0"
                             value="<?= h((string) ($editRow['pass_marks'] ?? '33')) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Sort order</label>
                      <input class="form-control" type="number" name="sort_order"
                             value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                    </div>
                    <div class="col-md-6 d-flex flex-wrap align-items-end gap-3 pb-1">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="has_mcq" id="has_mcq"
                               <?= !$editRow || (int) ($editRow['has_mcq'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="has_mcq">MCQ</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="has_cq" id="has_cq"
                               <?= !$editRow || (int) ($editRow['has_cq'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="has_cq">Written / CQ</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="has_practical" id="has_practical"
                               <?= $editRow && (int) ($editRow['has_practical'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="has_practical">Practical</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_optional" id="is_optional"
                               <?= $editRow && (int) ($editRow['is_optional'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_optional">Optional</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                               <?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                      </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                      <button class="btn btn-primary"><?= $editRow ? 'Update subject' : 'Add subject' ?></button>
                      <?php if ($editRow): ?>
                        <a class="btn btn-outline-secondary" href="subjects.php?class_id=<?= $selectedClassId ?>&amp;section_id=<?= $selectedSectionId ?>">Cancel</a>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>

              <?php if ($targetOwnSubjectCount === 0): ?>
                <div class="card stat-card mb-3">
                  <div class="card-header"><h5 class="mb-0">Copy from another class / section</h5></div>
                  <div class="card-body">
                    <form method="post" class="row g-2 align-items-end">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="copy">
                      <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                      <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                      <div class="col-md-8">
                        <label class="form-label">Source class / section</label>
                        <select class="form-select" name="source" required>
                          <?php foreach ($copySources as $sourceOption): ?>
                            <?php if (
                                (int) $sourceOption['class_id'] === $selectedClassId
                                && (int) $sourceOption['section_id'] === $selectedSectionId
                            ) { continue; } ?>
                            <option value="<?= (int) $sourceOption['class_id'] ?>:<?= (int) $sourceOption['section_id'] ?>">
                              <?= h($sourceOption['label']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-4">
                        <button class="btn btn-outline-primary">Copy subjects</button>
                      </div>
                    </form>
                  </div>
                </div>
              <?php endif; ?>

              <div class="card stat-card">
                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Order</th>
                        <th>Subject</th>
                        <th>Marks</th>
                        <th>Components</th>
                        <th>Teacher</th>
                        <th>Status</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($subjects === []): ?>
                        <tr><td colspan="7" class="text-secondary">No subjects yet. Add the first paper above.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($subjects as $subject): ?>
                        <?php
                          $id = (int) $subject['id'];
                          $locked = usageSummary(subjectUsageCounts($db, $id)) !== '';
                          $parts = [];
                          if ((int) $subject['has_mcq'] === 1) { $parts[] = 'MCQ'; }
                          if ((int) $subject['has_cq'] === 1) { $parts[] = 'CQ'; }
                          if ((int) $subject['has_practical'] === 1) { $parts[] = 'Practical'; }
                          $teacherName = $teacherByMobile[(string) ($subject['teacher_mobile'] ?? '')] ?? '';
                        ?>
                        <tr>
                          <td class="text-nowrap">
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="up">
                              <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                              <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                              <?= signedRecordField((int) $id, 'subject') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move up">&uarr;</button>
                            </form>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="down">
                              <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                              <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                              <?= signedRecordField((int) $id, 'subject') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move down">&darr;</button>
                            </form>
                          </td>
                          <td>
                            <?= h((string) $subject['subject_name_ban']) ?>
                            <div class="small text-secondary">
                              <?= h((string) $subject['subject_name']) ?>
                              <?php if (!empty($subject['subject_code'])): ?>
                                · <?= h((string) $subject['subject_code']) ?>
                              <?php endif; ?>
                              <?php if ((int) $subject['is_optional'] === 1): ?>
                                <span class="badge text-bg-light">Optional</span>
                              <?php endif; ?>
                            </div>
                          </td>
                          <td><?= (int) $subject['full_marks'] ?> <span class="text-secondary small">(pass <?= (int) $subject['pass_marks'] ?>)</span></td>
                          <td><?= h(implode(' · ', $parts) ?: '—') ?></td>
                          <td><?= $teacherName !== '' ? h($teacherName) : '—' ?></td>
                          <td>
                            <?php if ((int) $subject['is_active'] === 1): ?>
                              <span class="badge text-bg-success">Active</span>
                            <?php else: ?>
                              <span class="badge text-bg-secondary">Hidden</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('subjects.php', (int) $id, 'subject')) ?>">Edit</a>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="toggle">
                              <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                              <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                              <?= signedRecordField((int) $id, 'subject') ?>
                              <button class="btn btn-sm btn-outline-secondary"><?= (int) $subject['is_active'] === 1 ? 'Hide' : 'Show' ?></button>
                            </form>
                            <?php if (!$locked): ?>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this subject?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                                <input type="hidden" name="section_id" value="<?= $selectedSectionId ?>">
                                <?= signedRecordField((int) $id, 'subject') ?>
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                              </form>
                            <?php else: ?>
                              <button class="btn btn-sm btn-outline-danger" disabled title="Has saved marks">Delete</button>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
