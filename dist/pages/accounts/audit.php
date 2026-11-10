<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Access\Role;
use School\Accounting\Audit\AuditTrail;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

accBootPage(Capability::AUDIT_VIEW);
$db = getDatabaseConnection();
$basePath = '../../';
$pageTitle = 'পরিবর্তনের ইতিহাস | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$perPage = 50;
$entity = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['entity'] ?? '')) ?? '';
$dateFrom = (string) ($_GET['from'] ?? '');
$dateTo = (string) ($_GET['to'] ?? '');
$validDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
    && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
$dateFrom = $validDate($dateFrom) ? $dateFrom : '';
$dateTo = $validDate($dateTo) ? $dateTo : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($entity !== '') {
    $where[] = 'entity_type = :entity';
    $params['entity'] = $entity;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$countStatement = $db->prepare("SELECT COUNT(*) FROM acc_audit_log {$whereSql}");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$rows = fetchRows(
    $db,
    "SELECT * FROM acc_audit_log {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);
$entities = array_column(fetchRows($db, 'SELECT DISTINCT entity_type FROM acc_audit_log ORDER BY entity_type'), 'entity_type');

$entityLabels = [
    'acc_officer' => 'অ্যাকাউন্টস অফিসার',
];

/**
 * @param array<string, mixed>|null $snapshot
 * @param list<string> $changed
 */
$renderSnapshot = static function (?array $snapshot, array $changed): string {
    if ($snapshot === null) {
        return '<span class="text-secondary">—</span>';
    }
    $lines = [];
    foreach ($snapshot as $key => $value) {
        $text = is_scalar($value) || $value === null
            ? var_export($value, true)
            : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = h((string) $key) . ': ' . h($text);
        $lines[] = in_array((string) $key, $changed, true) ? '<span class="acc-changed">' . $line . '</span>' : $line;
    }

    return '<pre class="acc-json">' . implode("\n", $lines) . '</pre>';
};

$queryFor = static function (int $targetPage) use ($entity, $dateFrom, $dateTo): string {
    return '?' . http_build_query(array_filter([
        'entity' => $entity,
        'from' => $dateFrom,
        'to' => $dateTo,
        'page' => $targetPage > 1 ? $targetPage : null,
    ]));
};

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">পরিবর্তনের ইতিহাস</h3>
            <p class="text-secondary mb-0">হিসাব মডিউলে কে, কখন, কী পরিবর্তন করেছেন — আগের ও পরের মানসহ। এই লগ মুছে ফেলা বা সম্পাদনা করা যায় না।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <form method="get" class="card card-body mb-3">
              <div class="row g-2 align-items-end">
                <div class="col-sm-4 col-lg-3">
                  <label class="form-label small mb-1" for="acc-entity">বিষয়</label>
                  <select class="form-select form-select-sm" id="acc-entity" name="entity">
                    <option value="">সব বিষয়</option>
                    <?php foreach ($entities as $name): ?>
                      <option value="<?= h((string) $name) ?>"<?= $entity === $name ? ' selected' : '' ?>><?= h($entityLabels[$name] ?? (string) $name) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-sm-3 col-lg-2">
                  <label class="form-label small mb-1" for="acc-from">শুরুর তারিখ</label>
                  <input type="date" class="form-control form-control-sm" id="acc-from" name="from" value="<?= h($dateFrom) ?>">
                </div>
                <div class="col-6 col-sm-3 col-lg-2">
                  <label class="form-label small mb-1" for="acc-to">শেষ তারিখ</label>
                  <input type="date" class="form-control form-control-sm" id="acc-to" name="to" value="<?= h($dateTo) ?>">
                </div>
                <div class="col-sm-2 col-lg-auto d-flex gap-2">
                  <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>দেখুন</button>
                  <a class="btn btn-sm btn-outline-secondary" href="audit.php">রিসেট</a>
                </div>
              </div>
            </form>

            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">এন্ট্রি</h3>
                <span class="text-secondary small">মোট <?= h(bnDigits((string) $total)) ?>টি</span>
              </div>
              <div class="card-body p-0">
                <?php if ($rows === []): ?>
                  <p class="text-secondary p-3 mb-0">কোনো এন্ট্রি পাওয়া যায়নি।</p>
                <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-top mb-0 acc-table-stack">
                    <thead><tr><th>সময়</th><th>ব্যবহারকারী</th><th>কাজ</th><th>আগের মান</th><th>পরের মান</th></tr></thead>
                    <tbody>
                      <?php foreach ($rows as $row): ?>
                        <?php
                        $before = $row['before_json'] !== null ? json_decode((string) $row['before_json'], true) : null;
                        $after = $row['after_json'] !== null ? json_decode((string) $row['after_json'], true) : null;
                        $before = is_array($before) ? $before : null;
                        $after = is_array($after) ? $after : null;
                        $changed = AuditTrail::changedKeys($before, $after);
                        ?>
                        <tr>
                          <td data-label="সময়" class="text-nowrap"><?= h(accFormatDateTime((string) $row['created_at'])) ?><div class="small text-secondary"><?= h((string) ($row['ip_address'] ?? '')) ?></div></td>
                          <td data-label="ব্যবহারকারী"><?= h((string) $row['actor_name']) ?><div class="small text-secondary"><?= h(Role::label((string) $row['actor_role'])) ?></div></td>
                          <td data-label="কাজ">
                            <code><?= h((string) $row['action']) ?></code>
                            <div class="small"><?= h($entityLabels[(string) $row['entity_type']] ?? (string) $row['entity_type']) ?><?= $row['entity_id'] !== null ? ' #' . h(bnDigits((string) $row['entity_id'])) : '' ?></div>
                            <?php if ($row['note'] !== null): ?><div class="small text-secondary"><?= h((string) $row['note']) ?></div><?php endif; ?>
                          </td>
                          <td data-label="আগের মান"><?= $renderSnapshot($before, $changed) ?></td>
                          <td data-label="পরের মান"><?= $renderSnapshot($after, $changed) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </div>
              <?php if ($pages > 1): ?>
              <div class="card-footer">
                <nav aria-label="পাতা">
                  <ul class="pagination pagination-sm mb-0 flex-wrap">
                    <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= h($queryFor($page - 1)) ?>">আগের</a></li>
                    <li class="page-item disabled"><span class="page-link"><?= h(bnDigits((string) $page)) ?> / <?= h(bnDigits((string) $pages)) ?></span></li>
                    <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= h($queryFor($page + 1)) ?>">পরের</a></li>
                  </ul>
                </nav>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
