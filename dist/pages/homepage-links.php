<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Homepage Links | School Admin';
$groups = [
    'important' => 'Important links (homepage sidebar)',
    'footer' => 'Footer quick links',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $label = trim((string) ($_POST['label'] ?? ''));
            $url = trim((string) ($_POST['url'] ?? ''));
            $group = (string) ($_POST['link_group'] ?? 'important');
            $target = (string) ($_POST['target'] ?? '_self');
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            if ($label === '' || $url === '') {
                throw new RuntimeException('Label and URL are required.');
            }
            if (!isset($groups[$group])) {
                throw new RuntimeException('Invalid link group.');
            }
            if (!in_array($target, ['_self', '_blank'], true)) {
                $target = '_self';
            }

            if ($action === 'create') {
                if ($sortOrder === 0) {
                    $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM homepage_links')->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO homepage_links (link_group, label, url, target, sort_order, is_active)
                     VALUES (:link_group, :label, :url, :target, :sort_order, 1)'
                )->execute([
                    'link_group' => $group,
                    'label' => $label,
                    'url' => $url,
                    'target' => $target,
                    'sort_order' => $sortOrder,
                ]);
                redirectWithMessage('homepage-links.php', 'success', 'Link added.');
            }

            $id = postedRecordId('homepage_link');
            if ($id <= 0) {
                throw new RuntimeException('Invalid link.');
            }
            $db->prepare(
                'UPDATE homepage_links SET link_group = :link_group, label = :label, url = :url, target = :target, sort_order = :sort_order WHERE id = :id'
            )->execute([
                'link_group' => $group,
                'label' => $label,
                'url' => $url,
                'target' => $target,
                'sort_order' => $sortOrder,
                'id' => $id,
            ]);
            redirectWithMessage('homepage-links.php', 'success', 'Link updated.');
        }

        if ($action === 'toggle') {
            $db->prepare('UPDATE homepage_links SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('homepage_link')]);
            redirectWithMessage('homepage-links.php', 'success', 'Link visibility updated.');
        }

        if ($action === 'delete') {
            $db->prepare('DELETE FROM homepage_links WHERE id = :id')->execute(['id' => postedRecordId('homepage_link')]);
            redirectWithMessage('homepage-links.php', 'success', 'Link deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('homepage-links.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('homepage_link');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM homepage_links WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}

$rows = fetchRows($db, "SELECT * FROM homepage_links WHERE link_group <> 'eservices' ORDER BY link_group ASC, sort_order ASC, id ASC");
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Homepage &amp; footer links</h3>
            <p class="text-secondary mb-0">Important links and footer shortcuts shown on the public website.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h5 class="mb-0"><?= $editRow ? 'Edit link' : 'Add link' ?></h5></div>
              <div class="card-body">
                <form method="post" class="row g-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                  <?php if ($editRow): ?>
                    <?= signedRecordField((int) $editRow['id'], 'homepage_link') ?>
                  <?php endif; ?>
                  <div class="col-md-3">
                    <label class="form-label">Group</label>
                    <select class="form-select" name="link_group">
                      <?php foreach ($groups as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= (($editRow['link_group'] ?? 'important') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Label</label>
                    <input class="form-control" name="label" required value="<?= h((string) ($editRow['label'] ?? '')) ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">URL</label>
                    <input class="form-control" name="url" required value="<?= h((string) ($editRow['url'] ?? '')) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Target</label>
                    <select class="form-select" name="target">
                      <option value="_self" <?= (($editRow['target'] ?? '_self') === '_self') ? 'selected' : '' ?>>Same tab</option>
                      <option value="_blank" <?= (($editRow['target'] ?? '') === '_blank') ? 'selected' : '' ?>>New tab</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Sort</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary"><?= $editRow ? 'Update' : 'Add link' ?></button>
                    <?php if ($editRow): ?><a class="btn btn-outline-secondary ms-2" href="homepage-links.php">Cancel</a><?php endif; ?>
                  </div>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Group</th><th>Label</th><th>URL</th><th>Target</th><th>Sort</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><?= h($groups[(string) $row['link_group']] ?? (string) $row['link_group']) ?></td>
                        <td><?= h((string) $row['label']) ?></td>
                        <td><code><?= h((string) $row['url']) ?></code></td>
                        <td><?= h((string) $row['target']) ?></td>
                        <td><?= (int) $row['sort_order'] ?></td>
                        <td><?= (int) $row['is_active'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('homepage-links.php', (int) $row['id'], 'homepage_link')) ?>">Edit</a>
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'homepage_link') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this link?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'homepage_link') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
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
