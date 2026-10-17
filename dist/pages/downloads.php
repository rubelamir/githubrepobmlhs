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
            $file = storeUploadedFile('files_file', 'files_file', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'], 30 * 1024 * 1024, true);
            if ($text === '' || $file === null) {
                throw new RuntimeException('File name and attachment are required.');
            }
            if (columnExists($db, 'files', 'sort_order')) {
                $db->prepare('INSERT INTO files (files_text, files_file, files_status, sort_order) VALUES (:t, :f, 1, :sort)')
                    ->execute(['t' => $text, 'f' => $file, 'sort' => nextDownloadSortOrder($db)]);
            } else {
                $db->prepare('INSERT INTO files (files_text, files_file, files_status) VALUES (:t, :f, 1)')
                    ->execute(['t' => $text, 'f' => $file]);
            }
            redirectWithMessage('downloads.php#download-order', 'success', 'File is now available on the website.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE files SET files_status = IF(files_status = 1, 0, 1) WHERE id = :id')->execute(['id' => postedRecordId('download')]);
            redirectWithMessage('downloads.php#download-order', 'success', 'File visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('download');
            if (!deleteDatabaseRowWithMedia($db, 'files', $id, ['files_file' => 'files_file'])) {
                throw new RuntimeException('File not found.');
            }
            redirectWithMessage('downloads.php#download-order', 'success', 'Download record and file deleted.');
        }
        if ($action === 'move') {
            $moved = moveDownloadInList($db, postedRecordId('download'), (string) ($_POST['direction'] ?? ''));
            if (!$moved) {
                redirectWithMessage('downloads.php#download-order', 'info', 'Already at the edge of the list.');
            }
            redirectWithMessage('downloads.php#download-order', 'success', 'Download order updated.');
        }
        if ($action === 'reorder') {
            $order = $_POST['order'] ?? [];
            if (!is_array($order) || $order === []) {
                throw new RuntimeException('No download order was submitted.');
            }
            saveDownloadListOrder($db, idsFromSignedRecordRefs($order, 'download'));
            redirectWithMessage('downloads.php#download-order', 'success', 'Download order updated.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('downloads.php', 'danger', userFacingError($exception));
    }
}

$rows = fetchRows($db, 'SELECT * FROM files ORDER BY ' . downloadListOrderSql($db));
$canOrderDownloads = columnExists($db, 'files', 'sort_order');
$pageStyles = <<<'CSS'
.download-order-handle { cursor: grab; color: var(--bs-secondary-color); }
.download-order-handle:active { cursor: grabbing; }
#downloadOrderList tr.is-dragging { opacity: .45; }
CSS;
$pageScripts = $canOrderDownloads ? [<<<'JS'
(() => {
  const list = document.getElementById('downloadOrderList');
  const form = document.getElementById('downloadReorderForm');
  const box = document.getElementById('downloadReorderInputs');
  if (!list || !form || !box) return;

  const original = Array.from(list.querySelectorAll('tr[data-download-id]'))
    .map((row) => row.getAttribute('data-download-id'))
    .join(',');
  let dragRow = null;

  list.addEventListener('dragstart', (event) => {
    const row = event.target.closest('tr[data-download-id]');
    if (!row || event.target.closest('button, a, input, form')) {
      event.preventDefault();
      return;
    }
    dragRow = row;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', row.getAttribute('data-download-id') || '');
    row.classList.add('is-dragging');
  });

  list.addEventListener('dragover', (event) => {
    event.preventDefault();
    const row = event.target.closest('tr[data-download-id]');
    if (!dragRow || !row || row === dragRow || !list.contains(row)) return;
    const rect = row.getBoundingClientRect();
    const after = (event.clientY - rect.top) > (rect.height / 2);
    list.insertBefore(dragRow, after ? row.nextSibling : row);
  });

  list.addEventListener('drop', (event) => event.preventDefault());

  list.addEventListener('dragend', () => {
    if (dragRow) dragRow.classList.remove('is-dragging');
    dragRow = null;
    const ids = Array.from(list.querySelectorAll('tr[data-download-id]'))
      .map((row) => row.getAttribute('data-download-id'));
    if (ids.join(',') === original) return;
    box.replaceChildren();
    ids.forEach((id) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'order[]';
      input.value = id;
      box.appendChild(input);
    });
    form.submit();
  });
})();
JS] : [];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Downloads</h3>
            <p class="text-secondary mb-0">Use the arrows or drag rows to change the order shown on the public website.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="create">
                  <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                      <label class="form-label" for="files_text">Title</label>
                      <input class="form-control" id="files_text" name="files_text" required>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label" for="files_file">File</label>
                      <input class="form-control" id="files_file" type="file" name="files_file" required>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label d-none d-md-block">&nbsp;</label>
                      <button class="btn btn-primary w-100">Upload</button>
                    </div>
                  </div>
                  <div class="form-text mt-2">Maximum 30 MB. New uploads appear at the top.</div>
                </form>
              </div>
            </div>
            <div class="card stat-card" id="download-order">
              <?php if ($canOrderDownloads): ?>
              <form method="post" id="downloadReorderForm" class="d-none">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reorder">
                <div id="downloadReorderInputs"></div>
              </form>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <?php if ($canOrderDownloads): ?><th style="width:9.5rem">Order</th><?php endif; ?>
                      <th>Title</th>
                      <th>File</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="downloadOrderList">
                    <?php if ($rows === []): ?>
                      <tr><td colspan="<?= $canOrderDownloads ? 5 : 4 ?>" class="text-secondary">No download files yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $index => $row): ?>
                      <?php
                        $downloadId = (int) ($row['id'] ?? 0);
                        $isFirst = $index === 0;
                        $isLast = $index === count($rows) - 1;
                      ?>
                      <tr<?= $canOrderDownloads ? ' draggable="true"' : '' ?> data-download-id="<?= h(signedRecordRef($downloadId, 'download')) ?>">
                        <?php if ($canOrderDownloads): ?>
                        <td class="text-nowrap">
                          <span class="download-order-handle me-1" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="direction" value="up">
                            <?= signedRecordField($downloadId, 'download') ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Move up"<?= $isFirst ? ' disabled' : '' ?>>&uarr;</button>
                          </form>
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="direction" value="down">
                            <?= signedRecordField($downloadId, 'download') ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Move down"<?= $isLast ? ' disabled' : '' ?>>&darr;</button>
                          </form>
                          <span class="text-secondary small ms-1"><?= $index + 1 ?></span>
                        </td>
                        <?php endif; ?>
                        <td><?= h((string) $row['files_text']) ?></td>
                        <td><?php if (!empty($row['files_file'])): ?><a href="<?= h(publicSiteUrl('files_file/' . $row['files_file'])) ?>" target="_blank">Open</a><?php endif; ?></td>
                        <td><?= (int) $row['files_status'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField($downloadId, 'download') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this file?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField($downloadId, 'download') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
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
