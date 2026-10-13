<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Notice Headlines | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $text = trim((string) ($_POST['notice_headlines'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('Headline is required.');
            }
            $db->prepare('INSERT INTO notice_headlines (notice_headlines, notice_headlines_status) VALUES (:text, 1)')->execute(['text' => $text]);
            redirectWithMessage('headlines.php', 'success', 'Headline added to the scrolling ticker.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE notice_headlines SET notice_headlines_status = IF(notice_headlines_status = 1, 0, 1) WHERE id = :id')->execute(['id' => postedRecordId('headline')]);
            redirectWithMessage('headlines.php', 'success', 'Headline visibility updated.');
        }
        if ($action === 'delete') {
            $db->prepare('DELETE FROM notice_headlines WHERE id = :id')->execute(['id' => postedRecordId('headline')]);
            redirectWithMessage('headlines.php', 'success', 'Headline deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('headlines.php', 'danger', userFacingError($exception));
    }
}

$rows = fetchRows($db, 'SELECT * FROM notice_headlines ORDER BY notice_headlines_creation_time DESC');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">Notice headlines</h3><p class="text-secondary mb-0">These lines scroll on the public website header.</p></div></div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-body">
                <form method="post" class="row g-2">
                  <input type="hidden" name="action" value="create">
                  <div class="col-md-10"><input class="form-control" name="notice_headlines" placeholder="Type a headline" required></div>
                  <div class="col-md-2"><button class="btn btn-primary w-100">Add</button></div>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Headline</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><?= h((string) $row['notice_headlines']) ?></td>
                        <td><?= (int) $row['notice_headlines_status'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'headline') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this headline?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'headline') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
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
