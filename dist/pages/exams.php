<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Exams | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $nameBn = trim((string) ($_POST['exam_name_bangla'] ?? ''));
            $nameEn = trim((string) ($_POST['exam_name_english'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($nameBn === '') {
                throw new RuntimeException('The Bangla exam name is required.');
            }
            if ($action === 'create') {
                if ($sortOrder === 0) {
                    $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM exam_details')->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO exam_details (exam_name_bangla, exam_name_english, sort_order, is_active)
                     VALUES (:bn, :en, :sort_order, :is_active)'
                )->execute([
                    'bn' => $nameBn,
                    'en' => $nameEn !== '' ? $nameEn : $nameBn,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]);
                redirectWithMessage('exams.php', 'success', 'Exam added.');
            }
            $id = postedRecordId('exam');
            if ($id <= 0) {
                throw new RuntimeException('Invalid exam.');
            }
            $db->prepare(
                'UPDATE exam_details
                 SET exam_name_bangla = :bn, exam_name_english = :en, sort_order = :sort_order, is_active = :is_active
                 WHERE id = :id'
            )->execute([
                'bn' => $nameBn,
                'en' => $nameEn !== '' ? $nameEn : $nameBn,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'id' => $id,
            ]);
            redirectWithMessage('exams.php', 'success', 'Exam updated.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE exam_details SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('exam')]);
            redirectWithMessage('exams.php', 'success', 'Exam availability updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('exam');
            $used = tableCount($db, 'student_marks', 'exam_id = ' . $id)
                + tableCount($db, 'marks', 'exam_details_id = ' . $id);
            if ($used > 0) {
                redirectWithMessage('exams.php', 'danger', 'This exam has saved results. Deactivate it instead of deleting.');
            }
            $db->prepare('DELETE FROM exam_details WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('exams.php', 'success', 'Exam deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('exams.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('exam');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM exam_details WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}
$exams = schoolExams($db);

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Exams</h3>
            <p class="text-secondary mb-0">Half-yearly, annual, test, or any paper an institution needs. Result entry offers every active exam.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h5 class="mb-0"><?= $editRow ? 'Edit exam' : 'Add exam' ?></h5></div>
              <div class="card-body">
                <form method="post" class="row g-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                  <?php if ($editRow): ?><?= signedRecordField((int) $editRow['id'], 'exam') ?><?php endif; ?>
                  <div class="col-md-4">
                    <label class="form-label">Name (Bangla)</label>
                    <input class="form-control" name="exam_name_bangla" required value="<?= h((string) ($editRow['exam_name_bangla'] ?? '')) ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Name (English)</label>
                    <input class="form-control" name="exam_name_english" value="<?= h((string) ($editRow['exam_name_english'] ?? '')) ?>">
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
                  <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><?= $editRow ? 'Update exam' : 'Add exam' ?></button>
                    <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="exams.php">Cancel</a><?php endif; ?>
                  </div>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Exam</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php if ($exams === []): ?>
                      <tr><td colspan="3" class="text-secondary">No exams yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($exams as $exam): ?>
                      <tr>
                        <td>
                          <?= h((string) $exam['exam_name_bangla']) ?>
                          <?php if (!empty($exam['exam_name_english'])): ?>
                            <div class="small text-secondary"><?= h((string) $exam['exam_name_english']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= (int) ($exam['is_active'] ?? 1) === 1 ? 'Active' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('exams.php', (int) $exam['id'], 'exam')) ?>">Edit</a>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $exam['id'], 'exam') ?>
                            <button class="btn btn-sm btn-outline-secondary">Toggle</button>
                          </form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this exam?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $exam['id'], 'exam') ?>
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
