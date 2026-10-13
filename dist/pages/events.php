<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Events | School Admin';
$galleryMax = 8;
$galleryMaxBytes = 2 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $headline = trim((string) ($_POST['events_headlines'] ?? ''));
            $details = trim((string) ($_POST['events_details'] ?? ''));
            if ($headline === '' || $details === '') {
                throw new RuntimeException('Headline and details are required.');
            }
            $fileData = (string) ($_POST['events_file_data'] ?? '');

            if ($action === 'create') {
                $file = $fileData !== '' ? saveCroppedJpeg($fileData, 'events_file', '', 300 * 1024, 1280, 720) : null;
                if (columnExists($db, 'events', 'sort_order')) {
                    $db->prepare(
                        'INSERT INTO events (events_headlines, events_details, events_file, events_status, sort_order)
                         VALUES (:h, :d, :f, 1, :sort)'
                    )->execute([
                        'h' => $headline,
                        'd' => $details,
                        'f' => $file,
                        'sort' => nextEventSortOrder($db),
                    ]);
                } else {
                    $db->prepare('INSERT INTO events (events_headlines, events_details, events_file, events_status) VALUES (:h, :d, :f, 1)')
                        ->execute(['h' => $headline, 'd' => $details, 'f' => $file]);
                }
                $id = (int) $db->lastInsertId();
                saveEventGalleryUploads($db, $id, $galleryMax, $galleryMaxBytes);
                redirectWithMessage(
                    signedRecordUrl('events.php', $id, 'event'),
                    'success',
                    'Event published. Use [img:1], [img:2] in the details to place extra photos in the article.'
                );
            }

            $id = postedRecordId('event');
            $existingRows = $id > 0 ? fetchRows($db, 'SELECT * FROM events WHERE id = :id LIMIT 1', ['id' => $id]) : [];
            $existing = $existingRows[0] ?? null;
            if (!is_array($existing)) {
                throw new RuntimeException('Event not found.');
            }
            $file = (string) ($existing['events_file'] ?? '');
            if ($fileData !== '') {
                $newFile = saveCroppedJpeg($fileData, 'events_file', '', 300 * 1024, 1280, 720);
                replacePublicMediaFile('events_file', $file, $newFile);
                $file = $newFile;
            }
            $db->prepare('UPDATE events SET events_headlines = :h, events_details = :d, events_file = :f WHERE id = :id')
                ->execute(['h' => $headline, 'd' => $details, 'f' => $file !== '' ? $file : null, 'id' => $id]);
            saveEventGalleryUploads($db, $id, $galleryMax, $galleryMaxBytes);
            redirectWithMessage(signedRecordUrl('events.php', $id, 'event'), 'success', 'Event updated.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE events SET events_status = IF(events_status = 1, 0, 1) WHERE id = :id')->execute(['id' => postedRecordId('event')]);
            redirectWithMessage('events.php#event-order', 'success', 'Event visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('event');
            if (!deleteEventRecord($db, $id)) {
                throw new RuntimeException('Event not found.');
            }
            redirectWithMessage('events.php#event-order', 'success', 'Event and all photos deleted.');
        }
        if ($action === 'move') {
            $moved = moveEventInList($db, postedRecordId('event'), (string) ($_POST['direction'] ?? ''));
            if (!$moved) {
                redirectWithMessage('events.php#event-order', 'info', 'Already at the edge of the list.');
            }
            redirectWithMessage('events.php#event-order', 'success', 'Event order updated.');
        }
        if ($action === 'reorder') {
            $order = $_POST['order'] ?? [];
            if (!is_array($order) || $order === []) {
                throw new RuntimeException('No event order was submitted.');
            }
            saveEventListOrder($db, idsFromSignedRecordRefs(is_array($order) ? $order : [], 'event'));
            redirectWithMessage('events.php#event-order', 'success', 'Event order updated.');
        }
        if ($action === 'delete_image') {
            $eventId = postedRecordId('event', 'event_id');
            if ($eventId < 1) {
                $eventId = postedRecordId('event');
            }
            $imageId = postedRecordId('event_image', 'image_id');
            if (!deleteEventImage($db, $imageId, $eventId)) {
                throw new RuntimeException('Extra photo not found.');
            }
            redirectWithMessage(signedRecordUrl('events.php', $eventId, 'event'), 'success', 'Extra photo deleted. Renumber any [img:n] tags if needed.');
        }
    } catch (Throwable $exception) {
        $editId = postedRecordId('event');
        if ($editId < 1) {
            $editId = postedRecordId('event', 'event_id');
        }
        $target = $editId > 0 ? signedRecordUrl('events.php', $editId, 'event') : 'events.php';
        redirectWithMessage($target, 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('event');
$editRow = null;
if ($editId > 0) {
    $found = fetchRows($db, 'SELECT * FROM events WHERE id = :id LIMIT 1', ['id' => $editId]);
    $editRow = $found[0] ?? null;
}
$isEdit = is_array($editRow);
$editImages = $isEdit ? eventImages($db, (int) $editRow['id']) : [];
$rows = fetchRows($db, 'SELECT * FROM events ORDER BY ' . eventListOrderSql($db));
$canOrderEvents = canAccessModule('events');
$extraCounts = [];
if (tableExists($db, 'event_images')) {
    foreach (fetchRows($db, 'SELECT event_id, COUNT(*) AS total FROM event_images GROUP BY event_id') as $countRow) {
        $extraCounts[(int) $countRow['event_id']] = (int) $countRow['total'];
    }
}
$pageStyles = <<<'CSS'
.event-order-thumb { width: 64px; height: 40px; object-fit: cover; flex-shrink: 0; }
.event-order-handle { cursor: grab; color: var(--bs-secondary-color); }
.event-order-handle:active { cursor: grabbing; }
#eventOrderList tr.is-dragging { opacity: .45; }
CSS;
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#eventForm', [
    { input: '#eventPhoto', hidden: '#eventPhotoData', image: '#eventPhotoImage', preview: '#eventPhotoPreview', aspectRatio: 16/9, width: 1280, height: 720, maxLength: 408000 }
  ]);
});
(() => {
  const list = document.getElementById('eventOrderList');
  const form = document.getElementById('eventReorderForm');
  const box = document.getElementById('eventReorderInputs');
  if (!list || !form || !box) return;

  const original = Array.from(list.querySelectorAll('tr[data-event-id]'))
    .map((row) => row.getAttribute('data-event-id'))
    .join(',');
  let dragRow = null;

  list.addEventListener('dragstart', (event) => {
    const row = event.target.closest('tr[data-event-id]');
    if (!row || event.target.closest('button, a, input, form')) {
      event.preventDefault();
      return;
    }
    dragRow = row;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', row.getAttribute('data-event-id') || '');
    row.classList.add('is-dragging');
  });

  list.addEventListener('dragover', (event) => {
    event.preventDefault();
    const row = event.target.closest('tr[data-event-id]');
    if (!dragRow || !row || row === dragRow || !list.contains(row)) return;
    const rect = row.getBoundingClientRect();
    const after = (event.clientY - rect.top) > (rect.height / 2);
    list.insertBefore(dragRow, after ? row.nextSibling : row);
  });

  list.addEventListener('drop', (event) => event.preventDefault());

  list.addEventListener('dragend', () => {
    if (dragRow) dragRow.classList.remove('is-dragging');
    dragRow = null;
    const ids = Array.from(list.querySelectorAll('tr[data-event-id]'))
      .map((row) => row.getAttribute('data-event-id'));
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
                <h1 class="mb-0">Events</h1>
                <p class="text-secondary mb-0">Use the arrows or drag rows to change the order shown on the public website.</p>
              </div>
              <div class="d-flex gap-2">
                <a href="events.php" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add New</a>
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
                <h3 class="card-title mb-0"><?= $isEdit ? 'Edit event' : 'Add event' ?></h3>
                <?php if ($isEdit): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="events.php">New event</a>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="eventForm">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                  <?php if ($isEdit): ?>
                    <?= signedRecordField((int) $editRow['id'], 'event') ?>
                  <?php endif; ?>
                  <div class="row g-3">
                    <div class="col-lg-6">
                      <label class="form-label" for="events_headlines">Headline</label>
                      <input class="form-control" id="events_headlines" name="events_headlines" required value="<?= h($isEdit ? (string) $editRow['events_headlines'] : '') ?>">
                    </div>
                    <div class="col-lg-6">
                      <?php if ($isEdit && !empty($editRow['events_file'])): ?>
                        <div class="form-text mb-1">Current cover</div>
                        <img src="<?= h(publicSiteUrl('events_file/' . $editRow['events_file'])) ?>" alt="" class="rounded border" style="max-height:80px;object-fit:cover">
                      <?php endif; ?>
                      <?php renderCropperField('eventPhoto', 'events_file_data', $isEdit ? 'Replace cover photo' : 'Cover photo', '16:9 crop, 1280×720 JPEG, maximum 300KB. Used as the main image and Facebook share preview.'); ?>
                    </div>
                    <div class="col-12">
                      <label class="form-label" for="events_details">Details</label>
                      <textarea class="form-control" id="events_details" name="events_details" rows="6" required><?= h($isEdit ? (string) $editRow['events_details'] : '') ?></textarea>
                      <div class="form-text">Write <code>[img:1]</code> on its own line (or inside the paragraph) where extra photo 1 should appear. Unused extras show at the bottom of the public article.</div>
                    </div>
                    <div class="col-lg-6">
                      <label class="form-label" for="event_gallery">Extra photos</label>
                      <input class="form-control" type="file" id="event_gallery" name="event_gallery[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                      <div class="form-text">Optional. JPEG, PNG, WebP or GIF. Maximum <?= (int) $galleryMax ?> extras, 2 MB each.</div>
                    </div>
                    <div class="col-lg-6 d-flex align-items-end">
                      <button class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Publish' ?></button>
                    </div>
                  </div>
                </form>
                <?php if ($editImages !== []): ?>
                  <div class="mt-3">
                    <div class="form-label">Uploaded extras</div>
                    <div class="row g-2">
                      <?php foreach ($editImages as $index => $image):
                          $number = $index + 1;
                          $shortcode = '[img:' . $number . ']';
                          ?>
                        <div class="col-6 col-md-3 col-xl-2">
                          <div class="border rounded p-2 h-100">
                            <img src="<?= h(publicSiteUrl('events_file/' . $image['image_file'])) ?>" alt="" class="w-100 rounded mb-2" style="height:88px;object-fit:cover">
                            <div class="d-flex justify-content-between align-items-center gap-1">
                              <code class="small"><?= h($shortcode) ?></code>
                              <button type="button" class="btn btn-sm btn-outline-primary py-0" onclick="navigator.clipboard.writeText('<?= h($shortcode) ?>')">Copy</button>
                            </div>
                            <form method="post" class="mt-2" onsubmit="return confirm('Delete this extra photo?')">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="delete_image">
                              <?= signedRecordField((int) $editRow['id'], 'event', 'event_id') ?>
                              <?= signedRecordField((int) $image['id'], 'event_image', 'image_id') ?>
                              <button class="btn btn-sm btn-outline-danger w-100">Delete</button>
                            </form>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card stat-card mb-4" id="event-order">
              <div class="card-header">
                <h2 class="h5 mb-0">Website display order</h2>
                <p class="text-secondary small mb-0">This order is used on the Events page and the homepage event section.</p>
              </div>
              <?php if ($canOrderEvents): ?>
              <form method="post" id="eventReorderForm" class="d-none">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reorder">
                <div id="eventReorderInputs"></div>
              </form>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="width:9.5rem">Order</th>
                      <th>Event Name</th>
                      <th>Date</th>
                      <th>Status</th>
                      <th>View</th>
                      <th>Edit</th>
                      <th>Delete</th>
                    </tr>
                  </thead>
                  <tbody id="eventOrderList">
                    <?php if ($rows === []): ?>
                      <tr><td colspan="7" class="text-secondary">No events found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $index => $row): ?>
                      <?php
                        $eventId = (int) ($row['id'] ?? 0);
                        $headline = (string) ($row['events_headlines'] ?? 'Event');
                        $cover = trim((string) ($row['events_file'] ?? ''));
                        $coverUrl = $cover !== '' ? publicSiteUrl('events_file/' . $cover) : '';
                        $dateLabel = adminDateTimeLabel((string) ($row['events_creation_time'] ?? ''));
                        $isLive = (int) ($row['events_status'] ?? 0) === 1;
                        $isFirst = $index === 0;
                        $isLast = $index === count($rows) - 1;
                        $extras = $extraCounts[$eventId] ?? 0;
                        $publicHref = $eventId > 0 ? publicSiteUrl(eventPublicHref($eventId)) : '';
                      ?>
                      <tr<?= $canOrderEvents ? ' draggable="true"' : '' ?> data-event-id="<?= h(signedRecordRef((int) $eventId, 'event')) ?>">
                        <td class="text-nowrap">
                          <?php if ($canOrderEvents): ?>
                            <span class="event-order-handle me-1" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="up">
                              <?= signedRecordField((int) $eventId, 'event') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move up"<?= $isFirst ? ' disabled' : '' ?>>&uarr;</button>
                            </form>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="down">
                              <?= signedRecordField((int) $eventId, 'event') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move down"<?= $isLast ? ' disabled' : '' ?>>&darr;</button>
                            </form>
                          <?php endif; ?>
                          <span class="text-secondary small ms-1"><?= $index + 1 ?></span>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <?php if ($coverUrl !== ''): ?>
                              <img src="<?= h($coverUrl) ?>" alt="" class="event-order-thumb rounded">
                            <?php else: ?>
                              <span class="event-order-thumb rounded d-inline-flex align-items-center justify-content-center bg-body-secondary text-secondary"><?= h(strtoupper(substr($headline, 0, 1))) ?></span>
                            <?php endif; ?>
                            <div>
                              <strong><?= h($headline) ?></strong>
                              <?php if ($extras > 0): ?>
                                <div class="small text-secondary"><?= (int) $extras ?> extra photo<?= $extras === 1 ? '' : 's' ?></div>
                              <?php endif; ?>
                            </div>
                          </div>
                        </td>
                        <td class="text-nowrap"><?= $dateLabel !== '' ? h($dateLabel) : '—' ?></td>
                        <td class="text-nowrap">
                          <?= $isLive ? 'Live' : 'Hidden' ?>
                          <form method="post" class="d-inline ms-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <?= signedRecordField((int) $eventId, 'event') ?>
                            <button class="btn btn-sm btn-outline-secondary"><?= $isLive ? 'Hide' : 'Show' ?></button>
                          </form>
                        </td>
                        <td class="text-nowrap">
                          <?php if ($publicHref !== ''): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= h($publicHref) ?>" target="_blank" rel="noopener">
                              <i class="bi bi-box-arrow-up-right me-1"></i>View
                            </a>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-primary" href="<?= h(signedRecordUrl('events.php', (int) $eventId, 'event')) ?>">
                            <i class="bi bi-pencil-square me-1"></i>Edit
                          </a>
                        </td>
                        <td class="text-nowrap">
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this event and all photos?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $eventId, 'event') ?>
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
