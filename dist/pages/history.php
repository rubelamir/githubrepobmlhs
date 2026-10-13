<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'School History | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $text = trim((string) ($_POST['school_history'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('History text is required.');
            }
            $db->exec('UPDATE school_history SET school_history_status = 0');
            $db->prepare('INSERT INTO school_history (school_history, school_history_status) VALUES (:t, 1)')->execute(['t' => $text]);
            redirectWithMessage('history.php', 'success', 'School history published on the homepage.');
        }
        if ($action === 'activate') {
            $id = postedRecordId('history');
            $db->exec('UPDATE school_history SET school_history_status = 0');
            $db->prepare('UPDATE school_history SET school_history_status = 1 WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('history.php', 'success', 'This history version is now live.');
        }
        if ($action === 'delete') {
            $db->prepare('DELETE FROM school_history WHERE id = :id')->execute(['id' => postedRecordId('history')]);
            redirectWithMessage('history.php', 'success', 'History record deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('history.php', 'danger', userFacingError($exception));
    }
}

$rows = fetchRows($db, 'SELECT * FROM school_history ORDER BY school_history_creation_time DESC');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">School history</h3></div></div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-body">
                <form method="post">
                  <input type="hidden" name="action" value="create">
                  <label class="form-label">New history text</label>
                  <textarea class="form-control mb-3" name="school_history" rows="8" required></textarea>
                  <button class="btn btn-primary">Publish as live version</button>
                </form>
              </div>
            </div>
            <?php foreach ($rows as $row): ?>
              <div class="card stat-card mb-3">
                <div class="card-body">
                  <div class="d-flex justify-content-between mb-2">
                    <span class="badge <?= (int) $row['school_history_status'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                      <?= (int) $row['school_history_status'] === 1 ? 'Live' : 'Archived' ?>
                    </span>
                    <small class="text-secondary"><?= h((string) $row['school_history_creation_time']) ?></small>
                  </div>
                  <p><?= nl2br(h((string) $row['school_history'])) ?></p>
                  <?php if ((int) $row['school_history_status'] !== 1): ?>
                    <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="activate"><?= signedRecordField((int) $row['id'], 'history') ?><button class="btn btn-sm btn-success">Make live</button></form>
                  <?php endif; ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this history?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'history') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
