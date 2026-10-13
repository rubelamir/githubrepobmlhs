<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Downloads | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $text = trim((string) ($_POST['files_text'] ?? ''));
            $file = storeUploadedFile('files_file', 'files_file', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'], 5242880, true);
            if ($text === '' || $file === null) {
                throw new RuntimeException('File name and attachment are required.');
            }
            $db->prepare('INSERT INTO files (files_text, files_file, files_status) VALUES (:t, :f, 1)')->execute(['t' => $text, 'f' => $file]);
            redirectWithMessage('downloads.php', 'success', 'File is now available on the website.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE files SET files_status = IF(files_status = 1, 0, 1) WHERE id = :id')->execute(['id' => postedRecordId('download')]);
            redirectWithMessage('downloads.php', 'success', 'File visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('download');
            if (!deleteDatabaseRowWithMedia($db, 'files', $id, ['files_file' => 'files_file'])) {
                throw new RuntimeException('File not found.');
            }
            redirectWithMessage('downloads.php', 'success', 'Download record and file deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('downloads.php', 'danger', userFacingError($exception));
    }
}

$rows = fetchRows($db, 'SELECT * FROM files ORDER BY files_creation_time DESC');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">Downloads</h3></div></div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                  <input type="hidden" name="action" value="create">
                  <div class="col-md-5"><label class="form-label">Title</label><input class="form-control" name="files_text" required></div>
                  <div class="col-md-5"><label class="form-label">File</label><input class="form-control" type="file" name="files_file" required></div>
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
                        <td><?= h((string) $row['files_text']) ?></td>
                        <td><?php if (!empty($row['files_file'])): ?><a href="<?= h(publicSiteUrl('files_file/' . $row['files_file'])) ?>" target="_blank">Open</a><?php endif; ?></td>
                        <td><?= (int) $row['files_status'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td>
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'download') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this file?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'download') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
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
