<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Notices | School Admin';
$noticeAttachmentMaxBytes = 10 * 1024 * 1024;
$noticeAttachmentExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
$noticeAttachmentAccept = '.' . implode(',.', $noticeAttachmentExtensions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $text = trim((string) ($_POST['main_notice_text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('Notice text is required.');
            }
            $file = storeUploadedFile(
                'main_notice_file',
                'main_notice_file',
                $noticeAttachmentExtensions,
                $noticeAttachmentMaxBytes,
                true
            );
            $statement = $db->prepare('INSERT INTO main_notice (main_notice_text, main_notice_file, main_notice_status) VALUES (:text, :file, 1)');
            $statement->bindValue(':text', $text, PDO::PARAM_STR);
            $statement->bindValue(':file', $file, $file === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->execute();
            $noticeId = (int) $db->lastInsertId();
            writeAuditLog($db, 'publish', 'notice', $noticeId, schoolSnippet($text, 120));
            $message = 'Notice published on the website.';
            if (!empty($_POST['notify_guardians'])) {
                $summary = notifyGuardiansOfNotice($db, $noticeId, $text);
                $message .= sprintf(
                    ' Guardian email: %d, SMS: %d.',
                    (int) $summary['email'],
                    (int) $summary['sms']
                );
                if ((int) $summary['failed'] > 0) {
                    $message .= sprintf(' Failed: %d.', (int) $summary['failed']);
                }
            }
            redirectWithMessage('notices.php', 'success', $message);
        }
        if ($action === 'toggle') {
            $id = postedRecordId('notice');
            $db->prepare('UPDATE main_notice SET main_notice_status = IF(main_notice_status = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('notices.php', 'success', 'Notice visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('notice');
            if (!deleteDatabaseRowWithMedia($db, 'main_notice', $id, ['main_notice_file' => 'main_notice_file'])) {
                throw new RuntimeException('Notice not found.');
            }
            redirectWithMessage('notices.php', 'success', 'Notice and attached file deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('notices.php', 'danger', userFacingError($exception));
    }
}

$notices = fetchRows($db, 'SELECT * FROM main_notice ORDER BY main_notice_creation_time DESC');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Notices</h3>
            <p class="text-secondary mb-0">Published notices appear on the public website immediately.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3">
              <div class="col-lg-4">
                <div class="card stat-card">
                  <div class="card-header"><h3 class="card-title mb-0">Add notice</h3></div>
                  <div class="card-body">
                    <form method="post" enctype="multipart/form-data" id="noticeCreateForm">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="create">
                      <div class="mb-3">
                        <label class="form-label" for="main_notice_text">Notice</label>
                        <textarea class="form-control" id="main_notice_text" name="main_notice_text" rows="6" required></textarea>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="main_notice_file">Attachment <span class="text-secondary fw-normal">(optional)</span></label>
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $noticeAttachmentMaxBytes ?>">
                        <input
                          class="form-control"
                          type="file"
                          id="main_notice_file"
                          name="main_notice_file"
                          accept="<?= h($noticeAttachmentAccept) ?>"
                        >
                        <div class="form-text">Optional. Image, PDF, Word or Excel only. Maximum 10 MB.</div>
                        <div class="invalid-feedback" id="main_notice_file_error">Please choose a valid attachment.</div>
                      </div>
                      <button class="btn btn-primary w-100" type="submit">Publish</button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="col-lg-8">
                <div class="card stat-card">
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table align-middle mb-0">
                        <thead><tr><th>Notice</th><th>File</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                          <?php foreach ($notices as $row): ?>
                            <tr>
                              <td><?= nl2br(h((string) $row['main_notice_text'])) ?></td>
                              <td>
                                <?php if (!empty($row['main_notice_file'])): ?>
                                  <a href="<?= h(publicSiteUrl('main_notice_file/' . $row['main_notice_file'])) ?>" target="_blank">View</a>
                                <?php else: ?>
                                  —
                                <?php endif; ?>
                              </td>
                              <td><?= (int) $row['main_notice_status'] === 1 ? '<span class="badge text-bg-success">Live</span>' : '<span class="badge text-bg-secondary">Hidden</span>' ?></td>
                              <td class="text-nowrap">
                                <form method="post" class="d-inline">
                                  <?= csrfField() ?>
                                  <input type="hidden" name="action" value="toggle">
                                  <?= signedRecordField((int) $row['id'], 'notice') ?>
                                  <button class="btn btn-sm btn-outline-secondary">Toggle</button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this notice?')">
                                  <?= csrfField() ?>
                                  <input type="hidden" name="action" value="delete">
                                  <?= signedRecordField((int) $row['id'], 'notice') ?>
                                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                          <?php if ($notices === []): ?><tr><td colspan="4" class="text-center text-secondary py-4">No notices yet.</td></tr><?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php
$pageScripts = [
    '(() => {
  const form = document.getElementById("noticeCreateForm");
  const input = document.getElementById("main_notice_file");
  const error = document.getElementById("main_notice_file_error");
  if (!form || !input) return;
  const allowed = ' . json_encode($noticeAttachmentExtensions, JSON_THROW_ON_ERROR) . ';
  const maxBytes = ' . (int) $noticeAttachmentMaxBytes . ';
  const showError = (message) => {
    if (error) error.textContent = message;
    input.classList.add("is-invalid");
  };
  const clearError = () => {
    input.classList.remove("is-invalid");
  };
  const validate = () => {
    const file = input.files && input.files[0];
    if (!file) {
      clearError();
      return true;
    }
    const ext = (file.name.split(".").pop() || "").toLowerCase();
    if (!allowed.includes(ext)) {
      showError("Only image, PDF, Word and Excel files are allowed.");
      input.value = "";
      return false;
    }
    if (file.size > maxBytes) {
      showError("Attachment must be 10 MB or smaller.");
      input.value = "";
      return false;
    }
    clearError();
    return true;
  };
  input.addEventListener("change", validate);
  form.addEventListener("submit", (event) => {
    if (!validate()) event.preventDefault();
  });
})();',
];
include __DIR__ . '/../includes/layout-end.php';
?>
