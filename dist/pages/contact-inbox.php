<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');
if (!contactInboxAllowed()) {
    denyPanelAccess();
}

$db = getDatabaseConnection();
ensureContactMessagesTable($db);
$basePath = '../';
$pageTitle = 'Contact inbox | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read') {
        $id = postedRecordId('contact_message');
        if ($id > 0 && tableExists($db, 'contact_messages')) {
            $db->prepare('UPDATE contact_messages SET is_read = 1 WHERE id = :id')->execute(['id' => $id]);
        }
        redirectWithMessage('contact-inbox.php', 'success', 'Message marked as read.');
    }
    if ($action === 'read_all' && tableExists($db, 'contact_messages')) {
        $db->exec('UPDATE contact_messages SET is_read = 1 WHERE is_read = 0');
        writeAuditLog($db, 'read', 'contact_message', null, 'Marked all contact messages as read');
        redirectWithMessage('contact-inbox.php', 'success', 'All messages marked as read.');
    }
    if ($action === 'delete') {
        $id = postedRecordId('contact_message');
        if ($id < 1 || !tableExists($db, 'contact_messages')) {
            redirectWithMessage('contact-inbox.php', 'danger', 'Message not found.');
        }
        $db->prepare('DELETE FROM contact_messages WHERE id = :id')->execute(['id' => $id]);
        writeAuditLog($db, 'delete', 'contact_message', $id, 'Deleted a contact message');
        redirectWithMessage('contact-inbox.php', 'success', 'Message deleted.');
    }
}

$rows = tableExists($db, 'contact_messages')
    ? fetchRows($db, 'SELECT * FROM contact_messages ORDER BY is_read ASC, created_at DESC LIMIT 200')
    : [];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">Contact inbox</h3>
              <p class="text-secondary mb-0">Messages sent from the public contact form.</p>
            </div>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="read_all">
              <button class="btn btn-outline-secondary">Mark all read</button>
            </form>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>From</th><th>Mobile</th><th>Message</th><th>When</th><th></th></tr></thead>
                  <tbody>
                    <?php if ($rows === []): ?>
                      <tr><td colspan="5" class="text-secondary text-center py-4">No contact messages yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><?= h((string) $row['sender_name']) ?></td>
                        <td><?= h((string) $row['mobile']) ?></td>
                        <td><?= nl2br(h((string) $row['message'])) ?></td>
                        <td class="text-nowrap"><?= h((string) $row['created_at']) ?></td>
                        <td class="text-nowrap">
                          <?php if ((int) $row['is_read'] === 1): ?>
                            <span class="badge text-bg-secondary">Read</span>
                          <?php else: ?>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="read">
                              <?= signedRecordField((int) $row['id'], 'contact_message') ?>
                              <button class="btn btn-sm btn-outline-primary">Mark read</button>
                            </form>
                          <?php endif; ?>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this message?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $row['id'], 'contact_message') ?>
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
