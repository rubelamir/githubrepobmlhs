<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');
if (!auditLogAllowed()) {
    denyPanelAccess();
}

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Audit log | School Admin';
$rows = tableExists($db, 'audit_log')
    ? fetchRows($db, 'SELECT * FROM audit_log ORDER BY created_at DESC, id DESC LIMIT 200')
    : [];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Audit log</h3>
            <p class="text-secondary mb-0">Who changed tokens, notices, promotions and published results.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Summary</th></tr></thead>
                  <tbody>
                    <?php if ($rows === []): ?>
                      <tr><td colspan="5" class="text-secondary text-center py-4">No audit entries yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td class="text-nowrap"><?= h((string) $row['created_at']) ?></td>
                        <td><?= h((string) ($row['actor_name'] ?? '')) !== '' ? h((string) $row['actor_name']) : '—' ?></td>
                        <td><?= h((string) $row['action_key']) ?></td>
                        <td><?= h((string) $row['entity_name']) ?><?= (int) ($row['entity_id'] ?? 0) > 0 ? ' #' . (int) $row['entity_id'] : '' ?></td>
                        <td><?= h((string) $row['summary']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
