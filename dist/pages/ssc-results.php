<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'SSC Results Chart | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $year = (int) ($_POST['exam_year'] ?? 0);
            if ($year < 1990 || $year > 2100) {
                throw new RuntimeException('Enter a valid exam year.');
            }
            $payload = [
                'exam_year' => $year,
                'gpa5' => max(0, (int) ($_POST['gpa5'] ?? 0)),
                'gpa4' => max(0, (int) ($_POST['gpa4'] ?? 0)),
                'gpa3' => max(0, (int) ($_POST['gpa3'] ?? 0)),
                'gpa2' => max(0, (int) ($_POST['gpa2'] ?? 0)),
                'gpa1' => max(0, (int) ($_POST['gpa1'] ?? 0)),
                'fail_count' => max(0, (int) ($_POST['fail_count'] ?? 0)),
            ];
            $db->prepare(
                'INSERT INTO ssc_exam_results (exam_year, gpa5, gpa4, gpa3, gpa2, gpa1, fail_count)
                 VALUES (:exam_year, :gpa5, :gpa4, :gpa3, :gpa2, :gpa1, :fail_count)
                 ON DUPLICATE KEY UPDATE
                   gpa5 = VALUES(gpa5), gpa4 = VALUES(gpa4), gpa3 = VALUES(gpa3),
                   gpa2 = VALUES(gpa2), gpa1 = VALUES(gpa1), fail_count = VALUES(fail_count)'
            )->execute($payload);
            redirectWithMessage('ssc-results.php', 'success', 'SSC result year saved. The homepage chart will update.');
        }

        if ($action === 'delete') {
            $db->prepare('DELETE FROM ssc_exam_results WHERE id = :id')->execute(['id' => postedRecordId('ssc_result')]);
            redirectWithMessage('ssc-results.php', 'success', 'Year removed from the chart.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('ssc-results.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('ssc_result');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM ssc_exam_results WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}

$rows = fetchRows($db, 'SELECT * FROM ssc_exam_results ORDER BY exam_year DESC');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">SSC results chart</h3>
            <p class="text-secondary mb-0">Year-wise GPA counts shown on the public homepage.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h5 class="mb-0"><?= $editRow ? 'Edit year' : 'Add or update year' ?></h5></div>
              <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="save">
                  <div class="col-md-2"><label class="form-label">Year</label><input class="form-control" type="number" name="exam_year" required value="<?= h((string) ($editRow['exam_year'] ?? date('Y'))) ?>"></div>
                  <div class="col-md-1"><label class="form-label">GPA 5</label><input class="form-control" type="number" name="gpa5" min="0" value="<?= h((string) ($editRow['gpa5'] ?? '0')) ?>"></div>
                  <div class="col-md-1"><label class="form-label">GPA 4</label><input class="form-control" type="number" name="gpa4" min="0" value="<?= h((string) ($editRow['gpa4'] ?? '0')) ?>"></div>
                  <div class="col-md-1"><label class="form-label">GPA 3</label><input class="form-control" type="number" name="gpa3" min="0" value="<?= h((string) ($editRow['gpa3'] ?? '0')) ?>"></div>
                  <div class="col-md-1"><label class="form-label">GPA 2</label><input class="form-control" type="number" name="gpa2" min="0" value="<?= h((string) ($editRow['gpa2'] ?? '0')) ?>"></div>
                  <div class="col-md-1"><label class="form-label">GPA 1</label><input class="form-control" type="number" name="gpa1" min="0" value="<?= h((string) ($editRow['gpa1'] ?? '0')) ?>"></div>
                  <div class="col-md-1"><label class="form-label">Fail</label><input class="form-control" type="number" name="fail_count" min="0" value="<?= h((string) ($editRow['fail_count'] ?? '0')) ?>"></div>
                  <div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div>
                  <?php if ($editRow): ?><div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="ssc-results.php">Cancel</a></div><?php endif; ?>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Year</th><th>GPA 5</th><th>GPA 4</th><th>GPA 3</th><th>GPA 2</th><th>GPA 1</th><th>Fail</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><?= (int) $row['exam_year'] ?></td>
                        <td><?= (int) $row['gpa5'] ?></td>
                        <td><?= (int) $row['gpa4'] ?></td>
                        <td><?= (int) $row['gpa3'] ?></td>
                        <td><?= (int) $row['gpa2'] ?></td>
                        <td><?= (int) $row['gpa1'] ?></td>
                        <td><?= (int) $row['fail_count'] ?></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('ssc-results.php', (int) $row['id'], 'ssc_result')) ?>">Edit</a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Remove this year from the chart?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'ssc_result') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
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
