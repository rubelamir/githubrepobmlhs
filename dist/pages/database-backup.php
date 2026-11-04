<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/shared/backup.php';
requireAuthentication('../examples/login-v2.php');
if (!databaseBackupAllowed()) {
    denyPanelAccess();
}

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Database Backup | School Admin';
$keepDays = databaseBackupKeepDays();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'run') {
            $result = createDatabaseBackup(true);
            if (!$result['ok']) {
                throw new RuntimeException((string) ($result['detail'] ?? 'Backup failed.'));
            }
            writeAuditLog(
                $db,
                'backup',
                'database',
                null,
                'Manual database backup created: ' . (string) ($result['file'] ?? '')
            );
            $extra = ((int) ($result['pruned'] ?? 0) > 0)
                ? ' Removed ' . (int) $result['pruned'] . ' old file(s).'
                : '';
            redirectWithMessage(
                'database-backup.php',
                'success',
                'Backup created: ' . (string) ($result['file'] ?? '') . ' (' . formatBackupBytes((int) ($result['bytes'] ?? 0)) . ').' . $extra
            );
        }
        if ($action === 'download') {
            $basename = backupBasenameFromSignedRef((string) ($_POST['file'] ?? ''));
            $path = $basename !== '' ? resolveDatabaseBackupPath($basename) : null;
            if ($path === null) {
                throw new RuntimeException('Backup file not found.');
            }
            writeAuditLog($db, 'backup_download', 'database', null, 'Downloaded backup: ' . $basename);
            $safeName = str_replace(['"', "\r", "\n"], '', $basename);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    } catch (Throwable $exception) {
        redirectWithMessage('database-backup.php', 'danger', userFacingError($exception));
    }
}

pruneDatabaseBackups($keepDays);
$backups = listDatabaseBackups();
$backupDir = databaseBackupDirectory();

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Database Backup</h3>
            <p class="text-secondary mb-0">Create backups manually. Files older than <?= (int) $keepDays ?> days are deleted automatically.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <?php $cronUrl = (((($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'bmlhs.site')) . '/dist/cron/database-backup.php?key=' . rawurlencode(databaseBackupCronSecret()); ?>
            <div class="card mb-3">
              <div class="card-body">
                <div class="fw-semibold mb-1">Automatic 2:00 AM backup (cPanel Cron Job)</div>
                <div class="text-secondary small mb-2">Add this command in cPanel &rarr; Cron Jobs, schedule <code>0 2 * * *</code> (server time zone must match; the script only runs during 02:00&ndash;02:59).</div>
                <input type="text" class="form-control form-control-sm" readonly onclick="this.select()" value="<?= h('curl -s "' . $cronUrl . '" >/dev/null 2>&1') ?>">
              </div>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-4">
                <div class="card stat-card h-100">
                  <div class="card-body">
                    <div class="text-secondary small">Schedule</div>
                    <div class="fs-5 fw-semibold">Manual only</div>
                    <div class="text-secondary small mt-1">Backups are created only with the button</div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="card stat-card h-100">
                  <div class="card-body">
                    <div class="text-secondary small">Retention</div>
                    <div class="fs-5 fw-semibold"><?= (int) $keepDays ?> days</div>
                    <div class="text-secondary small mt-1">Older backups are removed automatically</div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="card stat-card h-100">
                  <div class="card-body d-flex flex-column">
                    <div class="text-secondary small">Manual backup</div>
                    <form method="post" class="mt-auto">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="run">
                      <button class="btn btn-primary w-100">Backup now</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>

            <div class="card stat-card">
              <div class="card-header">
                <h2 class="h5 mb-0">Available backups</h2>
                <p class="text-secondary small mb-0">Download from the admin panel. Storage: <code><?= h($backupDir) ?></code></p>
              </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>File</th>
                      <th>Created</th>
                      <th>Size</th>
                      <th>Age</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($backups === []): ?>
                      <tr><td colspan="5" class="text-secondary text-center py-4">No backups yet. Use “Backup now” or wait for 2:00 AM.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($backups as $file): ?>
                      <tr>
                        <td class="text-break"><code><?= h($file['name']) ?></code></td>
                        <td class="text-nowrap"><?= h(formatBackupCreatedAt((int) $file['mtime'])) ?></td>
                        <td class="text-nowrap"><?= h(formatBackupBytes((int) $file['bytes'])) ?></td>
                        <td class="text-nowrap"><?= (int) $file['age_days'] ?> day<?= (int) $file['age_days'] === 1 ? '' : 's' ?></td>
                        <td class="text-nowrap text-end">
                          <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="download">
                            <input type="hidden" name="file" value="<?= h(signedBackupFileRef($file['name'])) ?>">
                            <button class="btn btn-sm btn-outline-primary">Download</button>
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
