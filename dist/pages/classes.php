<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Classes | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $nameEn = trim((string) ($_POST['class_name'] ?? ''));
            $nameBn = trim((string) ($_POST['class_name_ban'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $openForAdmission = isset($_POST['open_for_admission']) ? 1 : 0;

            if ($nameEn === '' || $nameBn === '') {
                throw new RuntimeException('Both the English and Bangla class name are required.');
            }

            $duplicate = fetchRows(
                $db,
                'SELECT id FROM cls WHERE (class_name = :en OR class_name_ban = :bn) AND id <> :id LIMIT 1',
                ['en' => $nameEn, 'bn' => $nameBn, 'id' => postedRecordId('class')]
            );
            if ($duplicate !== []) {
                throw new RuntimeException('A class with that name already exists.');
            }

            if ($action === 'create') {
                if ($sortOrder === 0) {
                    $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cls')->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO cls (class_name, class_name_ban, sort_order, is_active, open_for_admission)
                     VALUES (:class_name, :class_name_ban, :sort_order, :is_active, :open_for_admission)'
                )->execute([
                    'class_name' => $nameEn,
                    'class_name_ban' => $nameBn,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'open_for_admission' => $openForAdmission,
                ]);
                redirectWithMessage('classes.php', 'success', 'Class added. Now add its sections.');
            }

            $id = postedRecordId('class');
            if ($id <= 0) {
                throw new RuntimeException('Invalid class.');
            }
            $db->prepare(
                'UPDATE cls
                 SET class_name = :class_name, class_name_ban = :class_name_ban,
                     sort_order = :sort_order, is_active = :is_active, open_for_admission = :open_for_admission
                 WHERE id = :id'
            )->execute([
                'class_name' => $nameEn,
                'class_name_ban' => $nameBn,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'open_for_admission' => $openForAdmission,
                'id' => $id,
            ]);
            redirectWithMessage('classes.php', 'success', 'Class updated.');
        }

        if ($action === 'toggle') {
            $id = postedRecordId('class');
            $db->prepare('UPDATE cls SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('classes.php', 'success', 'Class availability updated.');
        }

        if ($action === 'toggle_admission') {
            $id = postedRecordId('class');
            $db->prepare('UPDATE cls SET open_for_admission = IF(open_for_admission = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('classes.php', 'success', 'Admission form class list updated.');
        }

        if ($action === 'delete') {
            $id = postedRecordId('class');
            $usage = usageSummary(classUsageCounts($db, $id));
            if ($usage !== '') {
                redirectWithMessage(
                    'classes.php',
                    'danger',
                    'This class is still in use (' . $usage . '). Deactivate it instead of deleting, '
                    . 'so existing records keep their class name.'
                );
            }
            $db->prepare('DELETE FROM cls WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('classes.php', 'success', 'Class deleted.');
        }

        if ($action === 'move') {
            $id = postedRecordId('class');
            $direction = (string) ($_POST['direction'] ?? '');
            $rows = fetchRows($db, 'SELECT id, sort_order FROM cls ORDER BY sort_order ASC, id ASC');
            $index = null;
            foreach ($rows as $i => $row) {
                if ((int) $row['id'] === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException('Class not found.');
            }

            $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
            if ($target === null || !isset($rows[$target])) {
                redirectWithMessage('classes.php', 'info', 'Already at the edge of the list.');
            }

            $a = $rows[$index];
            $b = $rows[$target];
            $swap = $db->prepare('UPDATE cls SET sort_order = :sort WHERE id = :id');
            $swap->execute(['sort' => (int) $b['sort_order'], 'id' => (int) $a['id']]);
            $swap->execute(['sort' => (int) $a['sort_order'], 'id' => (int) $b['id']]);
            redirectWithMessage('classes.php', 'success', 'Class order updated.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('classes.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('class');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM cls WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}

$classes = schoolClasses($db);
$usage = [];
foreach ($classes as $class) {
    $usage[(int) $class['id']] = classUsageCounts($db, (int) $class['id']);
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Classes</h3>
            <p class="text-secondary mb-0">
              The academic ladder. Order matters — class promotion moves students to the next class in this list.
              Uncheck “Show on admission form” to hide a class from ভর্তি ইচ্ছুক শ্রেণী on admission.php without deleting it.
            </p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header">
                <h5 class="mb-0"><?= $editRow ? 'Edit class' : 'Add class' ?></h5>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                  <?php if ($editRow): ?>
                    <?= signedRecordField((int) $editRow['id'], 'class') ?>
                  <?php endif; ?>
                  <div class="col-md-4">
                    <label class="form-label">Name (Bangla)</label>
                    <input class="form-control" name="class_name_ban" required
                           value="<?= h((string) ($editRow['class_name_ban'] ?? '')) ?>" placeholder="যেমন: ৬ষ্ঠ">
                    <small class="text-secondary">Shown to students and parents on the website.</small>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Name (English)</label>
                    <input class="form-control" name="class_name" required
                           value="<?= h((string) ($editRow['class_name'] ?? '')) ?>" placeholder="Six">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Sort order</label>
                    <input class="form-control" type="number" name="sort_order"
                           value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                    <small class="text-secondary">Leave 0 to add at the end.</small>
                  </div>
                  <div class="col-md-2 d-flex align-items-start">
                    <div class="form-check mt-4 pt-2">
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                             <?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="is_active">Active</label>
                    </div>
                  </div>
                  <div class="col-md-2 d-flex align-items-start">
                    <div class="form-check mt-4 pt-2">
                      <input class="form-check-input" type="checkbox" name="open_for_admission" id="open_for_admission"
                             <?= !$editRow || (int) ($editRow['open_for_admission'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="open_for_admission">Show on admission form</label>
                    </div>
                  </div>
                  <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><?= $editRow ? 'Update class' : 'Add class' ?></button>
                    <?php if ($editRow): ?>
                      <a class="btn btn-outline-secondary" href="classes.php">Cancel</a>
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
                      <th>Class</th>
                      <th>Sections</th>
                      <th>Students</th>
                        <th>Status</th>
                        <th>Admission</th>
                        <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($classes === []): ?>
                      <tr><td colspan="7" class="text-secondary">No classes yet. Add the first one above.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($classes as $class): ?>
                      <?php
                        $id = (int) $class['id'];
                        $counts = $usage[$id];
                        $locked = usageSummary($counts) !== '';
                      ?>
                      <tr>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="direction" value="up">
                            <?= signedRecordField((int) $id, 'class') ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Move up">&uarr;</button>
                          </form>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="direction" value="down">
                            <?= signedRecordField((int) $id, 'class') ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Move down">&darr;</button>
                          </form>
                          <span class="text-secondary small ms-1"><?= (int) $class['sort_order'] ?></span>
                        </td>
                        <td>
                          <?= h((string) $class['class_name_ban']) ?>
                          <div class="small text-secondary"><?= h((string) $class['class_name']) ?></div>
                        </td>
                        <td>
                          <a href="sections.php?class_id=<?= $id ?>">
                            <?= (int) $counts['sections'] ?> section<?= (int) $counts['sections'] === 1 ? '' : 's' ?>
                          </a>
                        </td>
                        <td><?= (int) $counts['students'] ?></td>
                        <td>
                          <?php if ((int) $class['is_active'] === 1): ?>
                            <span class="badge text-bg-success">Active</span>
                          <?php else: ?>
                            <span class="badge text-bg-secondary">Closed</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ((int) ($class['open_for_admission'] ?? 1) === 1): ?>
                            <span class="badge text-bg-primary">Open</span>
                          <?php else: ?>
                            <span class="badge text-bg-light">Hidden</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('classes.php', (int) $id, 'class')) ?>">Edit</a>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $id, 'class') ?>
                            <button class="btn btn-sm btn-outline-secondary">
                              <?= (int) $class['is_active'] === 1 ? 'Close' : 'Reopen' ?>
                            </button>
                          </form>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_admission">
                            <?= signedRecordField((int) $id, 'class') ?>
                            <button class="btn btn-sm btn-outline-primary">
                              <?= (int) ($class['open_for_admission'] ?? 1) === 1 ? 'Hide from admission' : 'Show on admission' ?>
                            </button>
                          </form>
                          <?php if (!$locked): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this class?')">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="delete">
                              <?= signedRecordField((int) $id, 'class') ?>
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
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
