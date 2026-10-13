<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Homepage Cards | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $url = trim((string) ($_POST['url'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $imagePath = trim((string) ($_POST['image_path'] ?? ''));
            $uploaded = storeUploadedFile('image_file', 'homepage_cards', ['jpg', 'jpeg', 'png', 'gif', 'webp'], 2 * 1024 * 1024, true);
            if ($uploaded !== null) {
                $imagePath = 'homepage_cards/' . $uploaded;
            }

            if ($title === '' || $url === '') {
                throw new RuntimeException('Title and URL are required.');
            }

            if ($action === 'create') {
                if ($imagePath === '') {
                    throw new RuntimeException('Upload an image or enter an image path.');
                }
                if ($sortOrder === 0) {
                    $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM homepage_info_cards')->fetchColumn();
                }
                $db->prepare(
                    'INSERT INTO homepage_info_cards (title, url, image_path, sort_order, is_active)
                     VALUES (:title, :url, :image_path, :sort_order, 1)'
                )->execute([
                    'title' => $title,
                    'url' => $url,
                    'image_path' => $imagePath,
                    'sort_order' => $sortOrder,
                ]);
                redirectWithMessage('homepage-cards.php', 'success', 'Homepage card added.');
            }

            $id = postedRecordId('homepage_card');
            if ($id <= 0) {
                throw new RuntimeException('Invalid card.');
            }
            $existing = $db->prepare('SELECT image_path FROM homepage_info_cards WHERE id = :id');
            $existing->execute(['id' => $id]);
            $previousImage = (string) ($existing->fetchColumn() ?: '');
            if ($imagePath === '') {
                $imagePath = $previousImage;
            }
            replacePublicMediaFile('homepage_cards', $previousImage, $imagePath);
            $db->prepare(
                'UPDATE homepage_info_cards SET title = :title, url = :url, image_path = :image_path, sort_order = :sort_order WHERE id = :id'
            )->execute([
                'title' => $title,
                'url' => $url,
                'image_path' => $imagePath,
                'sort_order' => $sortOrder,
                'id' => $id,
            ]);
            redirectWithMessage('homepage-cards.php', 'success', 'Homepage card updated.');
        }

        if ($action === 'toggle') {
            $db->prepare('UPDATE homepage_info_cards SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('homepage_card')]);
            redirectWithMessage('homepage-cards.php', 'success', 'Card visibility updated.');
        }

        if ($action === 'delete') {
            $id = postedRecordId('homepage_card');
            if (!deleteDatabaseRowWithMedia($db, 'homepage_info_cards', $id, ['image_path' => 'homepage_cards'])) {
                throw new RuntimeException('Card not found.');
            }
            redirectWithMessage('homepage-cards.php', 'success', 'Card and image deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('homepage-cards.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('homepage_card');
$editRow = null;
if ($editId > 0) {
    $statement = $db->prepare('SELECT * FROM homepage_info_cards WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editRow = $statement->fetch() ?: null;
}

$rows = fetchRows($db, 'SELECT * FROM homepage_info_cards ORDER BY sort_order ASC, id ASC');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Homepage info cards</h3>
            <p class="text-secondary mb-0">Controls the “প্রাতিষ্ঠানিক তথ্য” grid on the public homepage.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h5 class="mb-0"><?= $editRow ? 'Edit card' : 'Add card' ?></h5></div>
              <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                  <?php if ($editRow): ?>
                    <?= signedRecordField((int) $editRow['id'], 'homepage_card') ?>
                  <?php endif; ?>
                  <div class="col-md-4">
                    <label class="form-label">Title</label>
                    <input class="form-control" name="title" required value="<?= h((string) ($editRow['title'] ?? '')) ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">URL / slug</label>
                    <input class="form-control" name="url" required value="<?= h((string) ($editRow['url'] ?? '')) ?>" placeholder="teacher_staff or https://…">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Sort</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Image path</label>
                    <input class="form-control" name="image_path" value="<?= h((string) ($editRow['image_path'] ?? '')) ?>" placeholder="image/comitee.png">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Upload image</label>
                    <input class="form-control" type="file" name="image_file" accept=".jpg,.jpeg,.png,.gif,.webp">
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary"><?= $editRow ? 'Update' : 'Add card' ?></button>
                    <?php if ($editRow): ?><a class="btn btn-outline-secondary ms-2" href="homepage-cards.php">Cancel</a><?php endif; ?>
                  </div>
                </form>
              </div>
            </div>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Preview</th><th>Title</th><th>URL</th><th>Sort</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td><img src="<?= h(publicSiteUrl(homepageCardImageSrc((string) $row['image_path']))) ?>" alt="" style="height:48px;width:72px;object-fit:cover"></td>
                        <td><?= h((string) $row['title']) ?></td>
                        <td><code><?= h((string) $row['url']) ?></code></td>
                        <td><?= (int) $row['sort_order'] ?></td>
                        <td><?= (int) $row['is_active'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('homepage-cards.php', (int) $row['id'], 'homepage_card')) ?>">Edit</a>
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'homepage_card') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this card?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'homepage_card') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
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
