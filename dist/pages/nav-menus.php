<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Navigation Menus | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $labelBn = trim((string) ($_POST['label_bn'] ?? ''));
            $labelEn = trim((string) ($_POST['label_en'] ?? ''));
            $parentIdRaw = trim((string) ($_POST['parent_id'] ?? ''));
            $parentId = $parentIdRaw === '' ? null : (int) $parentIdRaw;
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $target = (string) ($_POST['target'] ?? '_self');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($labelBn === '') {
                throw new RuntimeException('Bangla label is required.');
            }
            if (!in_array($target, ['_self', '_blank'], true)) {
                $target = '_self';
            }
            if ($parentId !== null && $parentId <= 0) {
                $parentId = null;
            }

            if ($action === 'create') {
                $url = trim((string) ($_POST['url'] ?? ''));
                if ($url === '') {
                    throw new RuntimeException('URL is required.');
                }
                if ($sortOrder === 0) {
                    $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM nav_menus')->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO nav_menus (label_bn, label_en, url, parent_id, sort_order, is_active, target)
                     VALUES (:label_bn, :label_en, :url, :parent_id, :sort_order, :is_active, :target)'
                )->execute([
                    'label_bn' => $labelBn,
                    'label_en' => $labelEn !== '' ? $labelEn : null,
                    'url' => $url,
                    'parent_id' => $parentId,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'target' => $target,
                ]);
                redirectWithMessage('nav-menus.php', 'success', 'Menu item added.');
            }

            $id = postedRecordId('nav_menu');
            if ($id <= 0) {
                throw new RuntimeException('Invalid menu item.');
            }
            if ($parentId === $id) {
                throw new RuntimeException('A menu item cannot be its own parent.');
            }
            $db->prepare(
                'UPDATE nav_menus
                 SET label_bn = :label_bn, label_en = :label_en, parent_id = :parent_id,
                     sort_order = :sort_order, is_active = :is_active, target = :target
                 WHERE id = :id'
            )->execute([
                'label_bn' => $labelBn,
                'label_en' => $labelEn !== '' ? $labelEn : null,
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'target' => $target,
                'id' => $id,
            ]);
            redirectWithMessage('nav-menus.php', 'success', 'Menu item updated.');
        }

        if ($action === 'toggle') {
            $db->prepare('UPDATE nav_menus SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('nav_menu')]);
            redirectWithMessage('nav-menus.php', 'success', 'Menu visibility updated.');
        }

        if ($action === 'delete') {
            $id = postedRecordId('nav_menu');
            $db->prepare('UPDATE nav_menus SET parent_id = NULL WHERE parent_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM nav_menus WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('nav-menus.php', 'success', 'Menu item deleted.');
        }

        if ($action === 'move') {
            $id = postedRecordId('nav_menu');
            $direction = (string) ($_POST['direction'] ?? '');
            $rows = fetchRows($db, 'SELECT id, sort_order, parent_id FROM nav_menus ORDER BY sort_order ASC, id ASC');
            $index = null;
            foreach ($rows as $i => $row) {
                if ((int) $row['id'] === $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                throw new RuntimeException('Menu item not found.');
            }

            $swapWith = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
            if ($swapWith === null || !isset($rows[$swapWith])) {
                redirectWithMessage('nav-menus.php', 'info', 'Already at the edge of the list.');
            }

            // Prefer swapping within the same parent group.
            $currentParent = $rows[$index]['parent_id'];
            $targetIndex = $swapWith;
            if ($direction === 'up') {
                for ($i = $index - 1; $i >= 0; $i--) {
                    if ($rows[$i]['parent_id'] == $currentParent) {
                        $targetIndex = $i;
                        break;
                    }
                }
            } else {
                for ($i = $index + 1, $n = count($rows); $i < $n; $i++) {
                    if ($rows[$i]['parent_id'] == $currentParent) {
                        $targetIndex = $i;
                        break;
                    }
                }
            }

            if ($targetIndex === $index || ($rows[$targetIndex]['parent_id'] ?? null) != $currentParent) {
                redirectWithMessage('nav-menus.php', 'info', 'Already at the edge of this group.');
            }

            $a = $rows[$index];
            $b = $rows[$targetIndex];
            $db->prepare('UPDATE nav_menus SET sort_order = :sort WHERE id = :id')
                ->execute(['sort' => (int) $b['sort_order'], 'id' => (int) $a['id']]);
            $db->prepare('UPDATE nav_menus SET sort_order = :sort WHERE id = :id')
                ->execute(['sort' => (int) $a['sort_order'], 'id' => (int) $b['id']]);
            redirectWithMessage('nav-menus.php', 'success', 'Menu order updated.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('nav-menus.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('nav_menu');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM nav_menus WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}

$rows = fetchRows($db, 'SELECT * FROM nav_menus ORDER BY sort_order ASC, id ASC');
$parents = fetchRows(
    $db,
    'SELECT id, label_bn FROM nav_menus WHERE parent_id IS NULL ORDER BY sort_order ASC, id ASC'
);
$byId = [];
foreach ($rows as $row) {
    $byId[(int) $row['id']] = $row;
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Navigation menus</h3>
            <p class="text-secondary mb-0">Control the public website navbar — add, edit, reorder, and nest one-level dropdowns.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header">
                <h5 class="mb-0"><?= $editRow ? 'Edit menu item' : 'Add menu item' ?></h5>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3">
                  <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                  <?php if ($editRow): ?>
                    <?= signedRecordField((int) $editRow['id'], 'nav_menu') ?>
                  <?php endif; ?>
                  <div class="col-md-4">
                    <label class="form-label">Label (Bangla)</label>
                    <input class="form-control" name="label_bn" required
                           value="<?= h((string) ($editRow['label_bn'] ?? '')) ?>" placeholder="যেমন: নোটিশ">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Label (English, optional)</label>
                    <input class="form-control" name="label_en"
                           value="<?= h((string) ($editRow['label_en'] ?? '')) ?>" placeholder="Notices">
                  </div>
                  <?php if (!$editRow): ?>
                  <div class="col-md-4">
                    <label class="form-label">URL / slug</label>
                    <input class="form-control" name="url" required
                           placeholder="notice or https://example.com or #">
                  </div>
                  <?php endif; ?>
                  <div class="col-md-3">
                    <label class="form-label">Parent (dropdown)</label>
                    <select class="form-select" name="parent_id">
                      <option value="">— Top level —</option>
                      <?php foreach ($parents as $parent): ?>
                        <?php if ($editRow && (int) $parent['id'] === (int) $editRow['id']) { continue; } ?>
                        <option value="<?= (int) $parent['id'] ?>"
                          <?= $editRow && (int) ($editRow['parent_id'] ?? 0) === (int) $parent['id'] ? 'selected' : '' ?>>
                          <?= h((string) $parent['label_bn']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Sort order</label>
                    <input class="form-control" type="number" name="sort_order"
                           value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Target</label>
                    <select class="form-select" name="target">
                      <option value="_self" <?= (($editRow['target'] ?? '_self') === '_self') ? 'selected' : '' ?>>Same tab</option>
                      <option value="_blank" <?= (($editRow['target'] ?? '') === '_blank') ? 'selected' : '' ?>>New tab</option>
                    </select>
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                             <?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="is_active">Active</label>
                    </div>
                  </div>
                  <div class="col-md-3 d-flex align-items-end gap-2">
                    <button class="btn btn-primary"><?= $editRow ? 'Update' : 'Add menu' ?></button>
                    <?php if ($editRow): ?>
                      <a class="btn btn-outline-secondary" href="nav-menus.php">Cancel</a>
                    <?php endif; ?>
                  </div>
                </form>
              </div>
            </div>

            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Order</th>
                      <th>Label</th>
                      <th>URL</th>
                      <th>Parent</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($rows === []): ?>
                      <tr><td colspan="6" class="text-secondary">No menu items yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                      <?php
                        $parentLabel = '';
                        if ($row['parent_id'] !== null && isset($byId[(int) $row['parent_id']])) {
                            $parentLabel = (string) $byId[(int) $row['parent_id']]['label_bn'];
                        }
                        $indent = $row['parent_id'] !== null ? 'ps-4' : '';
                      ?>
                      <tr>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="direction" value="up">
                            <?= signedRecordField((int) $row['id'], 'nav_menu') ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Move up">↑</button>
                          </form>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="direction" value="down">
                            <?= signedRecordField((int) $row['id'], 'nav_menu') ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Move down">↓</button>
                          </form>
                          <span class="text-secondary small ms-1"><?= (int) $row['sort_order'] ?></span>
                        </td>
                        <td class="<?= $indent ?>">
                          <?= h((string) $row['label_bn']) ?>
                          <?php if (!empty($row['label_en'])): ?>
                            <div class="small text-secondary"><?= h((string) $row['label_en']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><code><?= h((string) $row['url']) ?></code></td>
                        <td><?= $parentLabel !== '' ? h($parentLabel) : '—' ?></td>
                        <td><?= (int) $row['is_active'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('nav-menus.php', (int) $row['id'], 'nav_menu')) ?>">Edit</a>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $row['id'], 'nav_menu') ?>
                            <button class="btn btn-sm btn-outline-secondary">Toggle</button>
                          </form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this menu item?')">
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $row['id'], 'nav_menu') ?>
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
