<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Homepage Slider | School Admin';
$carouselCropWidth = 1280;
$carouselCropHeight = 676;
$carouselCropMaxBytes = 300 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $text = trim((string) ($_POST['carousel_text'] ?? ''));
            $fileData = (string) ($_POST['carousel_file_data'] ?? '');
            if ($text === '') {
                throw new RuntimeException('Caption is required.');
            }

            if ($action === 'create') {
                if ($fileData === '') {
                    throw new RuntimeException('Caption and cropped image are required.');
                }
                $file = saveCroppedJpeg($fileData, 'carousel_file', '', $carouselCropMaxBytes, $carouselCropWidth, $carouselCropHeight);
                if (columnExists($db, 'carousel', 'sort_order')) {
                    $db->prepare(
                        'INSERT INTO carousel (carousel_text, carousel_file, carousel_status, sort_order)
                         VALUES (:t, :f, 1, :sort)'
                    )->execute([
                        't' => $text,
                        'f' => $file,
                        'sort' => nextCarouselSortOrder($db),
                    ]);
                } else {
                    $db->prepare('INSERT INTO carousel (carousel_text, carousel_file, carousel_status) VALUES (:t, :f, 1)')
                        ->execute(['t' => $text, 'f' => $file]);
                }
                $id = (int) $db->lastInsertId();
                redirectWithMessage(signedRecordUrl('carousel.php', $id, 'carousel'), 'success', 'Slider image published.');
            }

            $id = postedRecordId('carousel');
            $existingRows = $id > 0 ? fetchRows($db, 'SELECT * FROM carousel WHERE id = :id LIMIT 1', ['id' => $id]) : [];
            $existing = $existingRows[0] ?? null;
            if (!is_array($existing)) {
                throw new RuntimeException('Slider image not found.');
            }
            $file = (string) ($existing['carousel_file'] ?? '');
            if ($fileData !== '') {
                $newFile = saveCroppedJpeg($fileData, 'carousel_file', '', $carouselCropMaxBytes, $carouselCropWidth, $carouselCropHeight);
                replacePublicMediaFile('carousel_file', $file, $newFile);
                $file = $newFile;
            }
            if ($file === '') {
                throw new RuntimeException('A slider image is required.');
            }
            $db->prepare('UPDATE carousel SET carousel_text = :t, carousel_file = :f WHERE id = :id')
                ->execute(['t' => $text, 'f' => $file, 'id' => $id]);
            redirectWithMessage(signedRecordUrl('carousel.php', $id, 'carousel'), 'success', 'Slider image updated.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE carousel SET carousel_status = IF(carousel_status = 1, 0, 1) WHERE id = :id')->execute(['id' => postedRecordId('carousel')]);
            redirectWithMessage('carousel.php#slider-order', 'success', 'Slider visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('carousel');
            if (!deleteDatabaseRowWithMedia($db, 'carousel', $id, ['carousel_file' => 'carousel_file'])) {
                throw new RuntimeException('Slider image not found.');
            }
            redirectWithMessage('carousel.php#slider-order', 'success', 'Slider image deleted from the website and disk.');
        }
        if ($action === 'move') {
            $moved = moveCarouselInList($db, postedRecordId('carousel'), (string) ($_POST['direction'] ?? ''));
            if (!$moved) {
                redirectWithMessage('carousel.php#slider-order', 'info', 'Already at the edge of the list.');
            }
            redirectWithMessage('carousel.php#slider-order', 'success', 'Slider order updated.');
        }
        if ($action === 'reorder') {
            $order = $_POST['order'] ?? [];
            if (!is_array($order) || $order === []) {
                throw new RuntimeException('No slider order was submitted.');
            }
            saveCarouselListOrder($db, idsFromSignedRecordRefs(is_array($order) ? $order : [], 'carousel'));
            redirectWithMessage('carousel.php#slider-order', 'success', 'Slider order updated.');
        }
    } catch (Throwable $exception) {
        $editId = postedRecordId('carousel');
        $target = $editId > 0 ? signedRecordUrl('carousel.php', $editId, 'carousel') : 'carousel.php';
        redirectWithMessage($target, 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('carousel');
$editRow = null;
if ($editId > 0) {
    $found = fetchRows($db, 'SELECT * FROM carousel WHERE id = :id LIMIT 1', ['id' => $editId]);
    $editRow = $found[0] ?? null;
}
$isEdit = is_array($editRow);
$rows = fetchRows($db, 'SELECT * FROM carousel ORDER BY ' . carouselListOrderSql($db));
$canOrderSlides = canAccessModule('carousel');
$pageStyles = <<<'CSS'
.carousel-order-thumb { width: 64px; height: 34px; object-fit: cover; flex-shrink: 0; }
.carousel-order-handle { cursor: grab; color: var(--bs-secondary-color); }
.carousel-order-handle:active { cursor: grabbing; }
#carouselOrderList tr.is-dragging { opacity: .45; }
CSS;
$pageScripts = [<<<JS
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#carouselForm', [
    { input: '#carouselImage', hidden: '#carouselImageData', image: '#carouselImageImage', preview: '#carouselImagePreview', aspectRatio: {$carouselCropWidth}/{$carouselCropHeight}, width: {$carouselCropWidth}, height: {$carouselCropHeight}, maxLength: 408000 }
  ]);
});
(() => {
  const list = document.getElementById('carouselOrderList');
  const form = document.getElementById('carouselReorderForm');
  const box = document.getElementById('carouselReorderInputs');
  if (!list || !form || !box) return;

  const original = Array.from(list.querySelectorAll('tr[data-slide-id]'))
    .map((row) => row.getAttribute('data-slide-id'))
    .join(',');
  let dragRow = null;

  list.addEventListener('dragstart', (event) => {
    const row = event.target.closest('tr[data-slide-id]');
    if (!row || event.target.closest('button, a, input, form')) {
      event.preventDefault();
      return;
    }
    dragRow = row;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', row.getAttribute('data-slide-id') || '');
    row.classList.add('is-dragging');
  });

  list.addEventListener('dragover', (event) => {
    event.preventDefault();
    const row = event.target.closest('tr[data-slide-id]');
    if (!dragRow || !row || row === dragRow || !list.contains(row)) return;
    const rect = row.getBoundingClientRect();
    const after = (event.clientY - rect.top) > (rect.height / 2);
    list.insertBefore(dragRow, after ? row.nextSibling : row);
  });

  list.addEventListener('drop', (event) => event.preventDefault());

  list.addEventListener('dragend', () => {
    if (dragRow) dragRow.classList.remove('is-dragging');
    dragRow = null;
    const ids = Array.from(list.querySelectorAll('tr[data-slide-id]'))
      .map((row) => row.getAttribute('data-slide-id'));
    if (ids.join(',') === original) return;
    box.replaceChildren();
    ids.forEach((id) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'order[]';
      input.value = id;
      box.appendChild(input);
    });
    form.submit();
  });
})();
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div>
                <h1 class="mb-0">Homepage slider</h1>
                <p class="text-secondary mb-0">Use the arrows or drag rows to change the order shown on the public website. The homepage shows at most <?= (int) homepageCarouselSlideLimit() ?> live slides.</p>
              </div>
              <div class="d-flex gap-2">
                <a href="carousel.php" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add New</a>
                <a href="../index.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Back</a>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-4">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><?= $isEdit ? 'Edit slide' : 'Add slide' ?></h3>
                <?php if ($isEdit): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="carousel.php">New slide</a>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <form method="post" id="carouselForm">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                  <?php if ($isEdit): ?>
                    <?= signedRecordField((int) $editRow['id'], 'carousel') ?>
                  <?php endif; ?>
                  <div class="row g-3">
                    <div class="col-lg-6">
                      <label class="form-label" for="carousel_text">Caption</label>
                      <input class="form-control" id="carousel_text" name="carousel_text" required value="<?= h($isEdit ? (string) $editRow['carousel_text'] : '') ?>">
                    </div>
                    <div class="col-lg-6">
                      <?php if ($isEdit && !empty($editRow['carousel_file'])): ?>
                        <div class="form-text mb-1">Current image</div>
                        <img src="<?= h(publicSiteUrl('carousel_file/' . $editRow['carousel_file'])) ?>" alt="" class="rounded border" style="max-height:80px;object-fit:cover">
                      <?php endif; ?>
                      <?php renderCropperField(
                          'carouselImage',
                          'carousel_file_data',
                          $isEdit ? 'Replace slider image' : 'Slider image',
                          'Crop to match the homepage slider (' . $carouselCropWidth . '×' . $carouselCropHeight . ' JPEG, maximum 300KB).',
                          !$isEdit
                      ); ?>
                    </div>
                    <div class="col-12 d-flex align-items-end">
                      <button class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Publish' ?></button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <div class="card stat-card mb-4" id="slider-order">
              <div class="card-header">
                <h2 class="h5 mb-0">Website display order</h2>
                <p class="text-secondary small mb-0">This order is used on the homepage slider. Only the first <?= (int) homepageCarouselSlideLimit() ?> live slides are shown there.</p>
              </div>
              <?php if ($canOrderSlides): ?>
              <form method="post" id="carouselReorderForm" class="d-none">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reorder">
                <div id="carouselReorderInputs"></div>
              </form>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="width:9.5rem">Order</th>
                      <th>Caption</th>
                      <th>Date</th>
                      <th>Status</th>
                      <th>View</th>
                      <th>Edit</th>
                      <th>Delete</th>
                    </tr>
                  </thead>
                  <tbody id="carouselOrderList">
                    <?php if ($rows === []): ?>
                      <tr><td colspan="7" class="text-secondary">No slider images found.</td></tr>
                    <?php endif; ?>
                    <?php $homepageLiveShown = 0; ?>
                    <?php foreach ($rows as $index => $row): ?>
                      <?php
                        $slideId = (int) ($row['id'] ?? 0);
                        $caption = (string) ($row['carousel_text'] ?? 'Slide');
                        $cover = trim((string) ($row['carousel_file'] ?? ''));
                        $coverUrl = $cover !== '' ? publicSiteUrl('carousel_file/' . $cover) : '';
                        $dateLabel = adminDateTimeLabel((string) ($row['carousel_creation_time'] ?? ''));
                        $isLive = (int) ($row['carousel_status'] ?? 0) === 1;
                        $onHomepage = $isLive && $homepageLiveShown < homepageCarouselSlideLimit();
                        if ($onHomepage) {
                            $homepageLiveShown++;
                        }
                        $isFirst = $index === 0;
                        $isLast = $index === count($rows) - 1;
                        $publicHref = publicSiteUrl();
                      ?>
                      <tr<?= $canOrderSlides ? ' draggable="true"' : '' ?> data-slide-id="<?= h(signedRecordRef((int) $slideId, 'carousel')) ?>">
                        <td class="text-nowrap">
                          <?php if ($canOrderSlides): ?>
                            <span class="carousel-order-handle me-1" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="up">
                              <?= signedRecordField((int) $slideId, 'carousel') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move up"<?= $isFirst ? ' disabled' : '' ?>>&uarr;</button>
                            </form>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="down">
                              <?= signedRecordField((int) $slideId, 'carousel') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move down"<?= $isLast ? ' disabled' : '' ?>>&darr;</button>
                            </form>
                          <?php endif; ?>
                          <span class="text-secondary small ms-1"><?= $index + 1 ?></span>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <?php if ($coverUrl !== ''): ?>
                              <img src="<?= h($coverUrl) ?>" alt="" class="carousel-order-thumb rounded">
                            <?php else: ?>
                              <span class="carousel-order-thumb rounded d-inline-flex align-items-center justify-content-center bg-body-secondary text-secondary"><?= h(strtoupper(substr($caption, 0, 1))) ?></span>
                            <?php endif; ?>
                            <strong><?= h($caption) ?></strong>
                          </div>
                        </td>
                        <td class="text-nowrap"><?= $dateLabel !== '' ? h($dateLabel) : '—' ?></td>
                        <td class="text-nowrap">
                          <?= $isLive ? 'Live' : 'Hidden' ?>
                          <?php if ($onHomepage): ?>
                            <span class="badge text-bg-success">Homepage</span>
                          <?php endif; ?>
                          <form method="post" class="d-inline ms-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $slideId, 'carousel') ?>
                            <button class="btn btn-sm btn-outline-secondary"><?= $isLive ? 'Hide' : 'Show' ?></button>
                          </form>
                        </td>
                        <td class="text-nowrap">
                          <?php if ($coverUrl !== ''): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= h($publicHref) ?>" target="_blank" rel="noopener">
                              <i class="bi bi-box-arrow-up-right me-1"></i>View
                            </a>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-primary" href="<?= h(signedRecordUrl('carousel.php', (int) $slideId, 'carousel')) ?>">
                            <i class="bi bi-pencil-square me-1"></i>Edit
                          </a>
                        </td>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this slide?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $slideId, 'carousel') ?>
                            <button class="btn btn-sm btn-outline-danger">
                              <i class="bi bi-trash me-1"></i>Delete
                            </button>
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
