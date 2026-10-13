<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Sections | School Admin';

$classes = schoolClasses($db);
$firstClassId = $classes === [] ? 0 : (int) $classes[0]['id'];

function sectionsRedirect(int $classId, string $type, string $message): void
{
    redirectWithMessage('sections.php?class_id=' . $classId, $type, $message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    $classId = (int) ($_POST['class_id'] ?? 0);
    try {
        if ($classId <= 0) {
            throw new RuntimeException('Pick a class first.');
        }

        if ($action === 'create' || $action === 'update') {
            $nameEn = trim((string) ($_POST['section_name'] ?? ''));
            $nameBn = trim((string) ($_POST['section_name_ban'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($nameEn === '' || $nameBn === '') {
                throw new RuntimeException('Both the English and Bangla section name are required.');
            }

            $duplicate = fetchRows(
                $db,
                'SELECT id FROM sections
                 WHERE class_id = :class_id AND (section_name = :en OR section_name_ban = :bn) AND id <> :id
                 LIMIT 1',
                ['class_id' => $classId, 'en' => $nameEn, 'bn' => $nameBn, 'id' => postedRecordId('section')]
            );
            if ($duplicate !== []) {
                throw new RuntimeException('That section already exists in this class.');
            }

            if ($action === 'create') {
                if ($sortOrder === 0) {
                    $statement = $db->prepare(
                        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM sections WHERE class_id = :class_id'
                    );
                    $statement->execute(['class_id' => $classId]);
                    $sortOrder = (int) $statement->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO sections (class_id, section_name, section_name_ban, sort_order, is_active)
                     VALUES (:class_id, :section_name, :section_name_ban, :sort_order, :is_active)'
                )->execute([
                    'class_id' => $classId,
                    'section_name' => $nameEn,
                    'section_name_ban' => $nameBn,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]);
                sectionsRedirect($classId, 'success', 'Section added.');
            }

            $id = postedRecordId('section');
            if ($id <= 0) {
                throw new RuntimeException('Invalid section.');
            }
            $db->prepare(
                'UPDATE sections
                 SET class_id = :class_id, section_name = :section_name, section_name_ban = :section_name_ban,
                     sort_order = :sort_order, is_active = :is_active
                 WHERE id = :id'
            )->execute([
                'class_id' => $classId,
                'section_name' => $nameEn,
                'section_name_ban' => $nameBn,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'id' => $id,
            ]);
            sectionsRedirect($classId, 'success', 'Section updated.');
        }

        if ($action === 'toggle') {
            $db->prepare('UPDATE sections SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('section')]);
            sectionsRedirect($classId, 'success', 'Section availability updated.');
        }

        if ($action === 'delete') {
            $id = postedRecordId('section');
            $usage = usageSummary(sectionUsageCounts($db, $id));
            if ($usage !== '') {
                sectionsRedirect(
                    $classId,
                    'danger',
                    'This section is still in use (' . $usage . '). Deactivate it instead of deleting, '
                    . 'so existing records keep their section name.'
                );
            }
            $db->prepare('DELETE FROM sections WHERE id = :id')->execute(['id' => $id]);
            sectionsRedirect($classId, 'success', 'Section deleted.');
        }

        if ($action === 'move') {
            $id = postedRecordId('section');
            $direction = (string) ($_POST['direction'] ?? '');
            $rows = fetchRows(
                $db,
                'SELECT id, sort_order FROM sections WHERE class_id = :class_id ORDER BY sort_order ASC, id ASC',
                ['class_id' => $classId]
            );
            $index = null;
            foreach ($rows as $i => $row) {
                if ((int) $row['id'] === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException('Section not found in this class.');
            }

            $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
            if ($target === null || !isset($rows[$target])) {
                sectionsRedirect($classId, 'info', 'Already at the edge of the list.');
            }

            $a = $rows[$index];
            $b = $rows[$target];
            $swap = $db->prepare('UPDATE sections SET sort_order = :sort WHERE id = :id');
            $swap->execute(['sort' => (int) $b['sort_order'], 'id' => (int) $a['id']]);
            $swap->execute(['sort' => (int) $a['sort_order'], 'id' => (int) $b['id']]);
            sectionsRedirect($classId, 'success', 'Section order updated.');
        }
    } catch (Throwable $exception) {
        sectionsRedirect($classId > 0 ? $classId : $firstClassId, 'danger', userFacingError($exception));
    }
}

$selectedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : $firstClassId;

$editId = requestRecordId('section');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM sections WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
    if ($editRow) {
        $selectedClassId = (int) $editRow['class_id'];
    }
}

$sections = $selectedClassId > 0 ? schoolSections($db, $selectedClassId) : [];
$usage = [];
foreach ($sections as $section) {
    $usage[(int) $section['id']] = sectionUsageCounts($db, (int) $section['id']);
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Sections</h3>
            <p class="text-secondary mb-0">
              Sections belong to a class. Every dropdown that asks for a section — admissions, student records,
              results, routine — reads this list.
            </p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <?php if ($classes === []): ?>
              <div class="alert alert-warning">
                No classes exist yet. <a href="classes.php">Add a class</a> before creating sections.
              </div>
            <?php else: ?>
              <div class="card stat-card mb-3">
                <div class="card-body">
                  <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                      <label class="form-label">Class</label>
                      <select class="form-select" name="class_id" onchange="this.form.submit()">
                        <?php foreach ($classes as $class): ?>
                          <option value="<?= (int) $class['id'] ?>"
                            <?= (int) $class['id'] === $selectedClassId ? 'selected' : '' ?>>
                            <?= h((string) $class['class_name_ban']) ?> — <?= h((string) $class['class_name']) ?>
                            <?= (int) $class['is_active'] === 1 ? '' : '(closed)' ?>
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

              <div class="card stat-card mb-3">
                <div class="card-header">
                  <h5 class="mb-0"><?= $editRow ? 'Edit section' : 'Add section' ?></h5>
                </div>
                <div class="card-body">
                  <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                    <?php if ($editRow): ?>
                      <?= signedRecordField((int) $editRow['id'], 'section') ?>
                    <?php endif; ?>
                    <div class="col-md-3">
                      <label class="form-label">Class</label>
                      <select class="form-select" name="class_id" required>
                        <?php foreach ($classes as $class): ?>
                          <option value="<?= (int) $class['id'] ?>"
                            <?= (int) $class['id'] === $selectedClassId ? 'selected' : '' ?>>
                            <?= h((string) $class['class_name_ban']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Name (Bangla)</label>
                      <input class="form-control" name="section_name_ban" required
                             value="<?= h((string) ($editRow['section_name_ban'] ?? '')) ?>" placeholder="যেমন: শাপলা">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Name (English)</label>
                      <input class="form-control" name="section_name" required
                             value="<?= h((string) ($editRow['section_name'] ?? '')) ?>" placeholder="Shapla">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Sort order</label>
                      <input class="form-control" type="number" name="sort_order"
                             value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-start">
                      <div class="form-check mt-4 pt-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                               <?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                      </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                      <button class="btn btn-primary"><?= $editRow ? 'Update section' : 'Add section' ?></button>
                      <?php if ($editRow): ?>
                        <a class="btn btn-outline-secondary" href="sections.php?class_id=<?= $selectedClassId ?>">Cancel</a>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>

              <div class="card stat-card">
                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Order</th>
                        <th>Section</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($sections === []): ?>
                        <tr><td colspan="5" class="text-secondary">This class has no sections yet.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($sections as $section): ?>
                        <?php
                          $id = (int) $section['id'];
                          $counts = $usage[$id];
                          $locked = usageSummary($counts) !== '';
                        ?>
                        <tr>
                          <td class="text-nowrap">
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="up">
                              <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                              <?= signedRecordField((int) $id, 'section') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move up">&uarr;</button>
                            </form>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="down">
                              <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                              <?= signedRecordField((int) $id, 'section') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move down">&darr;</button>
                            </form>
                            <span class="text-secondary small ms-1"><?= (int) $section['sort_order'] ?></span>
                          </td>
                          <td>
                            <?= h((string) $section['section_name_ban']) ?>
                            <div class="small text-secondary"><?= h((string) $section['section_name']) ?></div>
                          </td>
                          <td><?= (int) $counts['students'] ?></td>
                          <td>
                            <?php if ((int) $section['is_active'] === 1): ?>
                              <span class="badge text-bg-success">Active</span>
                            <?php else: ?>
                              <span class="badge text-bg-secondary">Closed</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('sections.php', (int) $id, 'section')) ?>">Edit</a>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="toggle">
                              <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                              <?= signedRecordField((int) $id, 'section') ?>
                              <button class="btn btn-sm btn-outline-secondary">
                                <?= (int) $section['is_active'] === 1 ? 'Close' : 'Reopen' ?>
                              </button>
                            </form>
                            <?php if (!$locked): ?>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this section?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                                <?= signedRecordField((int) $id, 'section') ?>
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                              </form>
                            <?php else: ?>
                              <button class="btn btn-sm btn-outline-danger" disabled
                                      title="In use by <?= h(usageSummary($counts)) ?>">Delete</button>
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
