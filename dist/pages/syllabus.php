<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Syllabus | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $title = trim((string) ($_POST['syllabus_title'] ?? ''));
            $file = storeUploadedFile('syllabus_file', 'syllabus_file', ['pdf', 'doc', 'docx'], 10 * 1024 * 1024, true);
            if ($title === '' || $file === null) {
                throw new RuntimeException('Title and PDF/Word file are required.');
            }
            $db->prepare('INSERT INTO syllabus (syllabus_title, syllabus_file, syllabus_status) VALUES (:t, :f, 1)')
                ->execute(['t' => $title, 'f' => $file]);
            redirectWithMessage('syllabus.php', 'success', 'Syllabus is now available on the website.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE syllabus SET syllabus_status = IF(syllabus_status = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('syllabus')]);
            redirectWithMessage('syllabus.php', 'success', 'Syllabus visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('syllabus');
            if (!deleteDatabaseRowWithMedia($db, 'syllabus', $id, ['syllabus_file' => 'syllabus_file'])) {
                throw new RuntimeException('Syllabus not found.');
            }
            redirectWithMessage('syllabus.php', 'success', 'Syllabus and file deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('syllabus.php', 'danger', userFacingError($exception));
    }
}

$rows = tableExists($db, 'syllabus') ? fetchRows($db, 'SELECT * FROM syllabus ORDER BY syllabus_creation_time DESC') : [];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">Syllabus</h3><p class="text-secondary mb-0">Uploaded files appear on the public syllabus page.</p></div></div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="create">
                  <div class="col-md-5"><label class="form-label">Title</label><input class="form-control" name="syllabus_title" required placeholder="নবম-দশম শ্রেণী সিলেবাস"></div>
                  <div class="col-md-5"><label class="form-label">File</label><input class="form-control" type="file" name="syllabus_file" accept=".pdf,.doc,.docx" required></div>
                  <div class="col-md-2"><button class="btn btn-primary w-100">Upload</button></div>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Title</th><th>File</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><?= h((string) $row['syllabus_title']) ?></td>
                        <td><?php if (!empty($row['syllabus_file'])): ?><a href="<?= h(publicSiteUrl('syllabus_file/' . $row['syllabus_file'])) ?>" target="_blank">Open</a><?php endif; ?></td>
                        <td><?= (int) $row['syllabus_status'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td>
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'syllabus') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this syllabus?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'syllabus') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?><tr><td colspan="4" class="text-center text-secondary py-4">No syllabus files yet.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
