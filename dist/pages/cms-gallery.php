<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Photo Gallery | School Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create_album') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $eventName = trim((string) ($_POST['event_name'] ?? ''));
            $year = (int) ($_POST['album_year'] ?? date('Y'));
            $fileData = (string) ($_POST['cover_file_data'] ?? '');
            if ($title === '') {
                throw new RuntimeException('Album title is required.');
            }
            if ($year < 1990 || $year > (int) date('Y') + 1) {
                throw new RuntimeException('Enter a valid album year.');
            }
            $cover = $fileData !== '' ? saveCroppedJpeg($fileData, 'image_gallery_file', '', 300 * 1024, 1200, 900) : null;
            $db->prepare(
                'INSERT INTO gallery_albums (title, event_name, album_year, cover_file, album_status)
                 VALUES (:title, :event_name, :album_year, :cover_file, 1)'
            )->execute([
                'title' => $title,
                'event_name' => $eventName !== '' ? $eventName : null,
                'album_year' => $year,
                'cover_file' => $cover,
            ]);
            redirectWithMessage('cms-gallery.php', 'success', 'Album created. Add photos below.');
        }
        if ($action === 'toggle_album') {
            $db->prepare('UPDATE gallery_albums SET album_status = IF(album_status = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('gallery_album')]);
            redirectWithMessage('cms-gallery.php', 'success', 'Album visibility updated.');
        }
        if ($action === 'delete_album') {
            $id = postedRecordId('gallery_album');
            $rows = $id > 0 ? fetchRows($db, 'SELECT * FROM gallery_albums WHERE id = :id LIMIT 1', ['id' => $id]) : [];
            $album = $rows[0] ?? null;
            if (!is_array($album)) {
                throw new RuntimeException('Album not found.');
            }
            if (columnExists($db, 'image_gallery', 'album_id')) {
                $db->prepare('UPDATE image_gallery SET album_id = NULL WHERE album_id = :id')->execute(['id' => $id]);
            }
            $db->prepare('DELETE FROM gallery_albums WHERE id = :id')->execute(['id' => $id]);
            replacePublicMediaFile('image_gallery_file', (string) ($album['cover_file'] ?? ''), '');
            redirectWithMessage('cms-gallery.php', 'success', 'Album deleted. Photos were kept and ungrouped.');
        }
        if ($action === 'create') {
            $caption = trim((string) ($_POST['image_gallery_caption'] ?? ''));
            $fileData = (string) ($_POST['image_gallery_file_data'] ?? '');
            $albumId = (int) ($_POST['album_id'] ?? 0);
            if ($caption === '' || $fileData === '') {
                throw new RuntimeException('Caption and cropped photo are required.');
            }
            $file = saveCroppedJpeg($fileData, 'image_gallery_file', '', 300 * 1024, 1200, 900);
            $db->prepare(
                'INSERT INTO image_gallery (image_gallery_caption, image_gallery_file, image_gallery_status, album_id)
                 VALUES (:c, :f, 1, :a)'
            )->execute([
                'c' => $caption,
                'f' => $file,
                'a' => $albumId > 0 ? $albumId : null,
            ]);
            redirectWithMessage('cms-gallery.php', 'success', 'Photo added to the public gallery.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE image_gallery SET image_gallery_status = IF(image_gallery_status = 1, 0, 1) WHERE id = :id')->execute(['id' => postedRecordId('gallery_image')]);
            redirectWithMessage('cms-gallery.php', 'success', 'Photo visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('gallery_image');
            if (!deleteDatabaseRowWithMedia($db, 'image_gallery', $id, ['image_gallery_file' => 'image_gallery_file'])) {
                throw new RuntimeException('Photo not found.');
            }
            redirectWithMessage('cms-gallery.php', 'success', 'Gallery photo deleted from the website and disk.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('cms-gallery.php', 'danger', userFacingError($exception));
    }
}

$albums = tableExists($db, 'gallery_albums')
    ? fetchRows($db, 'SELECT * FROM gallery_albums ORDER BY album_year DESC, id DESC')
    : [];
$rows = fetchRows($db, 'SELECT * FROM image_gallery ORDER BY image_gallery_creation_time DESC');
$albumTitles = [];
foreach ($albums as $album) {
    $albumTitles[(int) $album['id']] = (string) $album['title'];
}
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#albumForm', [
    { input: '#albumCover', hidden: '#albumCoverData', image: '#albumCoverImage', preview: '#albumCoverPreview', aspectRatio: 4/3, width: 1200, height: 900, maxLength: 408000 }
  ]);
  AdminImageCropper.bindForm('#galleryForm', [
    { input: '#galleryPhoto', hidden: '#galleryPhotoData', image: '#galleryPhotoImage', preview: '#galleryPhotoPreview', aspectRatio: 4/3, width: 1200, height: 900, maxLength: 408000 }
  ]);
});
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">Photo gallery</h3><p class="text-secondary mb-0">Create year/event albums, then add photos to them.</p></div></div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h3 class="card-title mb-0">New album</h3></div>
              <div class="card-body">
                <form method="post" id="albumForm" class="row g-2 align-items-end">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="create_album">
                  <div class="col-md-4"><label class="form-label">Title</label><input class="form-control" name="title" required placeholder="বার্ষিক ক্রীড়া"></div>
                  <div class="col-md-3"><label class="form-label">Event</label><input class="form-control" name="event_name" placeholder="বার্ষিক অনুষ্ঠান"></div>
                  <div class="col-md-2"><label class="form-label">Year</label><input class="form-control" type="number" name="album_year" value="<?= (int) date('Y') ?>" min="1990" max="<?= (int) date('Y') + 1 ?>" required></div>
                  <div class="col-md-3"><?php renderCropperField('albumCover', 'cover_file_data', 'Cover (optional)', '4:3 crop, 1200×900 JPEG, maximum 300KB.'); ?></div>
                  <div class="col-12"><button class="btn btn-primary">Create album</button></div>
                </form>
              </div>
            </div>
            <?php if ($albums !== []): ?>
            <div class="card stat-card mb-3">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Album</th><th>Event</th><th>Year</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($albums as $album): ?>
                      <tr>
                        <td><?= h((string) $album['title']) ?></td>
                        <td><?= h((string) ($album['event_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($album['album_year'] ?? '')) ?></td>
                        <td><?= (int) $album['album_status'] === 1 ? 'Live' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_album"><?= signedRecordField((int) $album['id'], 'gallery_album') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this album? Photos stay on the site.')"><?= csrfField() ?><input type="hidden" name="action" value="delete_album"><?= signedRecordField((int) $album['id'], 'gallery_album') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <?php endif; ?>
            <div class="card stat-card mb-3">
              <div class="card-header"><h3 class="card-title mb-0">Add photo</h3></div>
              <div class="card-body">
                <form method="post" id="galleryForm" class="row g-2 align-items-end">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="create">
                  <div class="col-md-3">
                    <label class="form-label">Album</label>
                    <select class="form-select" name="album_id">
                      <option value="0">Ungrouped</option>
                      <?php foreach ($albums as $album): ?>
                        <option value="<?= (int) $album['id'] ?>"><?= h((string) $album['title']) ?> (<?= h((string) ($album['album_year'] ?? '')) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><label class="form-label">Caption</label><input class="form-control" name="image_gallery_caption" required></div>
                  <div class="col-md-4"><?php renderCropperField('galleryPhoto', 'image_gallery_file_data', 'Photo', '4:3 crop, 1200×900 JPEG, maximum 300KB.', true); ?></div>
                  <div class="col-md-2"><button class="btn btn-primary w-100">Add</button></div>
                </form>
              </div>
            </div>
            <div class="row g-3">
              <?php foreach ($rows as $row): ?>
                <div class="col-md-3">
                  <div class="card stat-card h-100">
                    <img src="<?= h(publicSiteUrl('image_gallery_file/' . $row['image_gallery_file'])) ?>" class="card-img-top" alt="" style="height:160px;object-fit:cover">
                    <div class="card-body">
                      <div class="small mb-1"><?= h((string) $row['image_gallery_caption']) ?></div>
                      <div class="text-secondary small mb-2"><?= h($albumTitles[(int) ($row['album_id'] ?? 0)] ?? 'Ungrouped') ?></div>
                      <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'gallery_image') ?><button class="btn btn-sm btn-outline-secondary"><?= (int) $row['image_gallery_status'] === 1 ? 'Hide' : 'Show' ?></button></form>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this photo?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'gallery_image') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
