<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Admission tokens | School Admin';
$statusFilter = trim((string) ($_GET['status'] ?? '0'));
if (!in_array($statusFilter, ['0', '1', 'all'], true)) {
    $statusFilter = '0';
}
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'generate') {
            $count = (int) ($_POST['count'] ?? 10);
            $created = generateUnusedAdmissionTokens($db, $count);
            writeAuditLog($db, 'generate', 'admission_token', null, count($created) . ' admission tokens generated');
            $message = count($created) . ' unused token' . (count($created) === 1 ? '' : 's') . ' created';
            if (count($created) <= 12) {
                $message .= ': ' . implode(', ', $created);
            } else {
                $message .= '. Copy them from the unused list below.';
            }
            redirectWithMessage('admission-tokens.php?status=0', 'success', $message);
        }
        if ($action === 'create') {
            $token = addCustomAdmissionToken($db, (string) ($_POST['token'] ?? ''));
            writeAuditLog($db, 'create', 'admission_token', null, 'Added admission token ' . $token);
            redirectWithMessage('admission-tokens.php?status=0', 'success', 'Token added: ' . $token);
        }
        if ($action === 'sms') {
            if (!smsGatewayReady($db)) {
                throw new RuntimeException('Set the SMS provider in School Settings before sending a token.');
            }
            $mobile = normalizeBdMobile((string) ($_POST['mobile'] ?? ''));
            if ($mobile === '') {
                throw new RuntimeException('Enter a valid Bangladeshi mobile number.');
            }
            $token = preg_replace('/\s+/', '', (string) ($_POST['token'] ?? '')) ?? '';
            if ($token === '') {
                $token = uniqueAdmissionToken($db);
                insertUnusedAdmissionToken($db, $token);
            } else {
                $statusCol = admissionTokenStatusColumn($db);
                $quotedStatus = '`' . str_replace('`', '``', $statusCol) . '`';
                $existing = fetchRows($db, "SELECT id, {$quotedStatus} AS token_status FROM token WHERE token = :token LIMIT 1", ['token' => $token]);
                if ($existing === []) {
                    if (!preg_match('/^[0-9A-Za-z-]{4,32}$/', $token) || admissionTokenIsTaken($db, $token)) {
                        throw new RuntimeException('Use an unused token, or leave the field blank to create one.');
                    }
                    insertUnusedAdmissionToken($db, $token);
                } elseif ((int) ($existing[0]['token_status'] ?? 0) === 1) {
                    throw new RuntimeException('This token is already used.');
                }
            }
            $sent = sendSchoolSms($db, $mobile, admissionTokenSmsText($db, $token), 'admission_token');
            writeAuditLog($db, 'sms', 'admission_token', null, 'Sent token ' . $token . ' to ' . $mobile);
            if (empty($sent['ok'])) {
                throw new RuntimeException('Token ' . $token . ' is saved, but the SMS failed.');
            }
            redirectWithMessage('admission-tokens.php?status=0', 'success', 'Token ' . $token . ' sent to ' . $mobile . '.');
        }
        if ($action === 'delete') {
            $deletedId = postedRecordId('admission_token');
            deleteUnusedAdmissionToken($db, $deletedId);
            writeAuditLog($db, 'delete', 'admission_token', $deletedId, 'Deleted unused admission token');
            redirectWithMessage(
                'admission-tokens.php?status=' . rawurlencode($statusFilter) . ($search !== '' ? '&q=' . rawurlencode($search) : ''),
                'success',
                'Unused token deleted.'
            );
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $exception) {
        redirectWithMessage('admission-tokens.php?status=' . rawurlencode($statusFilter), 'danger', userFacingError($exception));
    }
}

$rows = [];
$unusedCount = 0;
$usedCount = 0;
if (tableExists($db, 'token') && columnExists($db, 'token', 'token')) {
    $statusCol = admissionTokenStatusColumn($db);
    $quotedStatus = '`' . str_replace('`', '``', $statusCol) . '`';
    $hasCreated = columnExists($db, 'token', 'token_creation_time');
    $sql = 'SELECT t.id, t.token, t.' . $quotedStatus . ' AS token_status';
    if ($hasCreated) {
        $sql .= ', t.token_creation_time';
    }
    $sql .= ', s.id AS student_id, s.name_ban, s.name_eng
             FROM token t
             LEFT JOIN students_info s ON s.admission_token = t.token';
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = 't.token LIKE :q';
        $params['q'] = '%' . $search . '%';
    }
    if ($statusFilter === '0') {
        $where[] = 't.' . $quotedStatus . ' = 0 AND s.id IS NULL';
    } elseif ($statusFilter === '1') {
        $where[] = '(t.' . $quotedStatus . ' = 1 OR s.id IS NOT NULL)';
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . ($hasCreated ? 't.token_creation_time DESC, t.id DESC' : 't.id DESC') . ' LIMIT 300';
    $rows = fetchRows($db, $sql, $params);

    $unusedCount = (int) $db->query(
        'SELECT COUNT(*) FROM token t
         LEFT JOIN students_info s ON s.admission_token = t.token
         WHERE t.' . $quotedStatus . ' = 0 AND s.id IS NULL'
    )->fetchColumn();
    $usedCount = (int) $db->query(
        'SELECT COUNT(*) FROM token t
         LEFT JOIN students_info s ON s.admission_token = t.token
         WHERE t.' . $quotedStatus . ' = 1 OR s.id IS NOT NULL'
    )->fetchColumn();
}

$pageStyles = <<<'CSS'
@media print {
  .no-print { display: none !important; }
  .app-content-header h3, .app-content-header p { color: #000 !important; }
}
CSS;
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-copy-token').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-token') || '';
      if (!value) return;
      try {
        await navigator.clipboard.writeText(value);
        const original = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => { button.textContent = original; }, 1200);
      } catch (error) {
        window.prompt('Copy token', value);
      }
    });
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">ভর্তি টোকেন / Admission tokens</h3>
              <p class="text-secondary mb-0">Issue unused tokens for the public admission form. Used tokens cannot be reused.</p>
            </div>
            <div class="no-print d-flex flex-wrap gap-2">
              <a class="btn btn-outline-secondary" href="admissions.php">Applications</a>
              <button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print list</button>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3 mb-3 no-print">
              <div class="col-md-6"><a href="admission-tokens.php?status=0" class="card stat-card text-decoration-none"><div class="card-body"><div class="text-secondary">Unused</div><div class="fs-3 fw-semibold"><?= $unusedCount ?></div></div></a></div>
              <div class="col-md-6"><a href="admission-tokens.php?status=1" class="card stat-card text-decoration-none"><div class="card-body"><div class="text-secondary">Used</div><div class="fs-3 fw-semibold"><?= $usedCount ?></div></div></a></div>
            </div>
            <div class="card stat-card mb-3 no-print">
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-lg-6">
                    <h2 class="h6">Generate unused tokens</h2>
                    <form method="post" class="row g-2 align-items-end">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="generate">
                      <div class="col-8">
                        <label class="form-label" for="token-count">How many (1–50)</label>
                        <input class="form-control" id="token-count" type="number" name="count" min="1" max="50" value="10" required>
                      </div>
                      <div class="col-4"><button class="btn btn-primary w-100">Generate</button></div>
                    </form>
                  </div>
                  <div class="col-lg-6">
                    <h2 class="h6">Send a token by SMS</h2>
                    <form method="post" class="row g-2 align-items-end">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="sms">
                      <div class="col-md-5">
                        <label class="form-label" for="sms-mobile">Guardian mobile</label>
                        <input class="form-control" id="sms-mobile" name="mobile" inputmode="numeric" placeholder="01XXXXXXXXX" required>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label" for="sms-token">Token</label>
                        <input class="form-control" id="sms-token" name="token" maxlength="32" placeholder="Blank = new token">
                      </div>
                      <div class="col-md-3"><button class="btn btn-success w-100">Send SMS</button></div>
                    </form>
                  </div>
                  <div class="col-lg-6">
                    <h2 class="h6">Add a specific token</h2>
                    <form method="post" class="row g-2 align-items-end">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="create">
                      <div class="col-8">
                        <label class="form-label" for="custom-token">Token</label>
                        <input class="form-control" id="custom-token" name="token" maxlength="32" placeholder="e.g. 1847362910" required>
                      </div>
                      <div class="col-4"><button class="btn btn-outline-primary w-100">Add</button></div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
            <form class="card stat-card mb-3 no-print">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Token number"></div>
                <div class="col-md-4">
                  <label class="form-label">Status</label>
                  <select class="form-select" name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Unused</option>
                    <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Used</option>
                  </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filter</button></div>
              </div>
            </form>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Token</th><th>Status</th><th>Created</th><th>Used by</th><th class="no-print"></th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <?php
                        $isUsed = (int) ($row['token_status'] ?? 0) === 1 || (int) ($row['student_id'] ?? 0) > 0;
                        $created = trim((string) ($row['token_creation_time'] ?? ''));
                        $applicant = trim((string) (($row['name_ban'] ?? '') !== '' ? $row['name_ban'] : ($row['name_eng'] ?? '')));
                      ?>
                      <tr>
                        <td class="fw-semibold font-monospace"><?= h((string) $row['token']) ?></td>
                        <td>
                          <?php if ($isUsed): ?>
                            <span class="badge text-bg-secondary">Used</span>
                          <?php else: ?>
                            <span class="badge text-bg-success">Unused</span>
                          <?php endif; ?>
                        </td>
                        <td><?= $created !== '' ? h($created) : '—' ?></td>
                        <td>
                          <?php if ($applicant !== ''): ?>
                            <?= h($applicant) ?>
                            <?php if ((int) ($row['student_id'] ?? 0) > 0): ?>
                              <a class="small no-print" href="<?= h(signedRecordUrl('student-form.php', (int) $row['student_id'], 'student')) ?>">Open</a>
                            <?php endif; ?>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td class="no-print">
                          <button type="button" class="btn btn-sm btn-outline-secondary js-copy-token" data-token="<?= h((string) $row['token']) ?>">Copy</button>
                          <?php if (!$isUsed): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this unused token?')">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="delete">
                              <?= signedRecordField((int) $row['id'], 'admission_token') ?>
                              <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                      <tr><td colspan="5" class="text-center text-secondary py-4">No tokens found.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
