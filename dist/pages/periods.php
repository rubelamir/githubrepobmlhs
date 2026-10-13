<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Class periods | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $periodNo = (int) ($_POST['period_no'] ?? 0);
            $periodTime = trim((string) ($_POST['period_time'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $periodLabel = trim((string) ($_POST['period_label'] ?? ''));
            if ($periodNo < 1 || $periodTime === '') {
                throw new RuntimeException('Period number and time are required.');
            }
            if ($periodLabel === '') {
                $periodLabel = periodDisplayLabel($periodNo);
            }
            if ($action === 'create') {
                if ($sortOrder === 0) {
                    $sortOrder = $periodNo * 10;
                }
                $sql = columnExists($db, 'class_periods', 'period_label')
                    ? 'INSERT INTO class_periods (period_no, period_time, period_label, sort_order, is_active)
                       VALUES (:period_no, :period_time, :period_label, :sort_order, :is_active)'
                    : 'INSERT INTO class_periods (period_no, period_time, sort_order, is_active)
                       VALUES (:period_no, :period_time, :sort_order, :is_active)';
                $params = [
                    'period_no' => $periodNo,
                    'period_time' => $periodTime,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ];
                if (columnExists($db, 'class_periods', 'period_label')) {
                    $params['period_label'] = $periodLabel;
                }
                $db->prepare($sql)->execute($params);
                redirectWithMessage('periods.php', 'success', 'Period added.');
            }
            $id = postedRecordId('period');
            $sql = columnExists($db, 'class_periods', 'period_label')
                ? 'UPDATE class_periods
                   SET period_no = :period_no, period_time = :period_time, period_label = :period_label,
                       sort_order = :sort_order, is_active = :is_active
                   WHERE id = :id'
                : 'UPDATE class_periods
                   SET period_no = :period_no, period_time = :period_time, sort_order = :sort_order, is_active = :is_active
                   WHERE id = :id';
            $params = [
                'period_no' => $periodNo,
                'period_time' => $periodTime,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'id' => $id,
            ];
            if (columnExists($db, 'class_periods', 'period_label')) {
                $params['period_label'] = $periodLabel;
            }
            $db->prepare($sql)->execute($params);
            redirectWithMessage('periods.php', 'success', 'Period updated.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE class_periods SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('period')]);
            redirectWithMessage('periods.php', 'success', 'Period availability updated.');
        }
        if ($action === 'delete') {
            $db->prepare('DELETE FROM class_periods WHERE id = :id')->execute(['id' => postedRecordId('period')]);
            redirectWithMessage('periods.php', 'success', 'Period deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('periods.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('period');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM class_periods WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}
$periods = fetchRows($db, 'SELECT * FROM class_periods ORDER BY sort_order ASC, period_no ASC');

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Class periods</h3>
            <p class="text-secondary mb-0">Bell times for the routine. Add or remove periods to match the institution's timetable.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h5 class="mb-0"><?= $editRow ? 'Edit period' : 'Add period' ?></h5></div>
              <div class="card-body">
                <form method="post" class="row g-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                  <?php if ($editRow): ?><?= signedRecordField((int) $editRow['id'], 'period') ?><?php endif; ?>
                  <div class="col-md-2">
                    <label class="form-label">Period no.</label>
                    <input class="form-control" type="number" name="period_no" min="1" required
                           value="<?= h((string) ($editRow['period_no'] ?? '')) ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Time</label>
                    <input class="form-control" name="period_time" required
                           value="<?= h((string) ($editRow['period_time'] ?? '')) ?>" placeholder="১০:০১–১০:৫০">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Label</label>
                    <input class="form-control" name="period_label"
                           value="<?= h((string) ($editRow['period_label'] ?? periodDisplayLabel((int) ($editRow['period_no'] ?? 0)))) ?>"
                           placeholder="১ম ঘন্টা">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Sort order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                             <?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="is_active">Active</label>
                    </div>
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary"><?= $editRow ? 'Update' : 'Add period' ?></button>
                  </div>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>#</th><th>Label</th><th>Time</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($periods as $period): ?>
                      <tr>
                        <td><?= (int) $period['period_no'] ?></td>
                        <td><?= h(periodDisplayLabel((int) $period['period_no'], (string) ($period['period_label'] ?? ''))) ?></td>
                        <td><?= h((string) $period['period_time']) ?></td>
                        <td><?= (int) $period['is_active'] === 1 ? 'Active' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('periods.php', (int) $period['id'], 'period')) ?>">Edit</a>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $period['id'], 'period') ?>
                            <button class="btn btn-sm btn-outline-secondary">Toggle</button>
                          </form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this period?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $period['id'], 'period') ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                          </form>
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
