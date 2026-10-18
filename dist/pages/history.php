<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
ensureHistoryTimelineTable($db);
$basePath = '../';
$pageTitle = 'School History | School Admin';
$editTimelineId = requestRecordId('history_timeline');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $text = trim((string) ($_POST['school_history'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('History text is required.');
            }
            $db->exec('UPDATE school_history SET school_history_status = 0');
            $db->prepare('INSERT INTO school_history (school_history, school_history_status) VALUES (:t, 1)')->execute(['t' => $text]);
            redirectWithMessage('history.php', 'success', 'School history published on the homepage.');
        }
        if ($action === 'activate') {
            $id = postedRecordId('history');
            $db->exec('UPDATE school_history SET school_history_status = 0');
            $db->prepare('UPDATE school_history SET school_history_status = 1 WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('history.php', 'success', 'This history version is now live.');
        }
        if ($action === 'delete') {
            $db->prepare('DELETE FROM school_history WHERE id = :id')->execute(['id' => postedRecordId('history')]);
            redirectWithMessage('history.php', 'success', 'History record deleted.');
        }
        if ($action === 'timeline_save') {
            ensureHistoryTimelineTable($db);
            $id = max(0, (int) ($_POST['timeline_id'] ?? 0));
            if ($id > 0) {
                $id = postedRecordId('history_timeline');
            }
            $yearRaw = asciiDigits(trim((string) ($_POST['event_year'] ?? '')));
            $year = (int) $yearRaw;
            $label = trim((string) ($_POST['label'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            if ($year < 1800 || $year > 2100) {
                throw new RuntimeException('Enter a valid year (e.g. 1965).');
            }
            if ($label === '' || mb_strlen($label, 'UTF-8') > 255) {
                throw new RuntimeException('Label is required (max 255 characters).');
            }
            if ($id > 0) {
                $db->prepare(
                    'UPDATE school_history_timeline
                     SET event_year = :y, label = :l, sort_order = :s
                     WHERE id = :id LIMIT 1'
                )->execute(['y' => $year, 'l' => $label, 's' => $sortOrder, 'id' => $id]);
                redirectWithMessage('history.php', 'success', 'Timeline step updated.');
            }
            $db->prepare(
                'INSERT INTO school_history_timeline (event_year, label, sort_order, is_active)
                 VALUES (:y, :l, :s, 1)'
            )->execute(['y' => $year, 'l' => $label, 's' => $sortOrder]);
            redirectWithMessage('history.php', 'success', 'Timeline step added to the About page.');
        }
        if ($action === 'timeline_toggle') {
            $db->prepare(
                'UPDATE school_history_timeline SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id LIMIT 1'
            )->execute(['id' => postedRecordId('history_timeline')]);
            redirectWithMessage('history.php', 'success', 'Timeline step visibility updated.');
        }
        if ($action === 'timeline_delete') {
            $db->prepare('DELETE FROM school_history_timeline WHERE id = :id LIMIT 1')
                ->execute(['id' => postedRecordId('history_timeline')]);
            redirectWithMessage('history.php', 'success', 'Timeline step deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('history.php', 'danger', userFacingError($exception));
    }
}

$rows = fetchRows($db, 'SELECT * FROM school_history ORDER BY school_history_creation_time DESC');
$timelineRows = tableExists($db, 'school_history_timeline')
    ? fetchRows($db, 'SELECT * FROM school_history_timeline ORDER BY sort_order ASC, event_year ASC, id ASC')
    : [];
$editTimeline = null;
if ($editTimelineId > 0) {
    foreach ($timelineRows as $timelineRow) {
        if ((int) ($timelineRow['id'] ?? 0) === $editTimelineId) {
            $editTimeline = $timelineRow;
            break;
        }
    }
}
$timelineYear = (string) ($editTimeline['event_year'] ?? '');
$timelineLabel = (string) ($editTimeline['label'] ?? '');
$timelineSort = (string) ($editTimeline['sort_order'] ?? '0');
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">School history</h3>
            <p class="text-secondary mb-0">History text for the About page, plus manual timeline steps (year + label).</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <div class="card stat-card mb-3">
              <div class="card-header"><strong>History text</strong></div>
              <div class="card-body">
                <form method="post">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="create">
                  <label class="form-label">New history text</label>
                  <textarea class="form-control mb-3" name="school_history" rows="8" required></textarea>
                  <button class="btn btn-primary">Publish as live version</button>
                </form>
              </div>
            </div>

            <?php foreach ($rows as $row): ?>
              <div class="card stat-card mb-3">
                <div class="card-body">
                  <div class="d-flex justify-content-between mb-2">
                    <span class="badge <?= (int) $row['school_history_status'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                      <?= (int) $row['school_history_status'] === 1 ? 'Live' : 'Archived' ?>
                    </span>
                    <small class="text-secondary"><?= h((string) $row['school_history_creation_time']) ?></small>
                  </div>
                  <p><?= nl2br(h((string) $row['school_history'])) ?></p>
                  <?php if ((int) $row['school_history_status'] !== 1): ?>
                    <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="activate"><?= signedRecordField((int) $row['id'], 'history') ?><button class="btn btn-sm btn-success">Make live</button></form>
                  <?php endif; ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this history?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'history') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="card stat-card mb-3">
              <div class="card-header">
                <strong>About timeline</strong>
                <span class="text-secondary small ms-2">ঐতিহ্যের ধাপ — year + label (shown on public About page)</span>
              </div>
              <div class="card-body">
                <p class="small text-secondary">
                  When at least one <em>Live</em> step exists here, About uses these rows only.
                  If this list is empty, the page falls back to auto years from history text / established year.
                </p>
                <form method="post" class="row g-2 align-items-end mb-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="timeline_save">
                  <?php if ($editTimeline !== null): ?>
                    <input type="hidden" name="timeline_id" value="<?= (int) $editTimeline['id'] ?>">
                    <?= signedRecordField((int) $editTimeline['id'], 'history_timeline') ?>
                  <?php endif; ?>
                  <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <input class="form-control" name="event_year" inputmode="numeric" placeholder="1965" value="<?= h($timelineYear) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Label</label>
                    <input class="form-control" name="label" maxlength="255" placeholder="বিদ্যালয় প্রতিষ্ঠা" value="<?= h($timelineLabel) ?>" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Sort</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= h($timelineSort !== '' ? $timelineSort : '0') ?>">
                  </div>
                  <div class="col-md-2 d-grid gap-1">
                    <button class="btn btn-primary"><?= $editTimeline !== null ? 'Update step' : 'Add step' ?></button>
                    <?php if ($editTimeline !== null): ?>
                      <a class="btn btn-outline-secondary btn-sm" href="history.php">Cancel</a>
                    <?php endif; ?>
                  </div>
                </form>

                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="width:5rem">Year</th>
                        <th>Label</th>
                        <th style="width:5rem">Sort</th>
                        <th style="width:6rem">Status</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($timelineRows === []): ?>
                        <tr><td colspan="5" class="text-secondary text-center py-4">No timeline steps yet.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($timelineRows as $timelineRow): ?>
                        <tr>
                          <td class="fw-semibold"><?= h(bnDigits((string) $timelineRow['event_year'])) ?></td>
                          <td><?= h((string) $timelineRow['label']) ?></td>
                          <td><?= h((string) $timelineRow['sort_order']) ?></td>
                          <td><?= (int) $timelineRow['is_active'] === 1 ? 'Live' : 'Hidden' ?></td>
                          <td class="text-nowrap text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('history.php', (int) $timelineRow['id'], 'history_timeline')) ?>">Edit</a>
                            <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="timeline_toggle"><?= signedRecordField((int) $timelineRow['id'], 'history_timeline') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this timeline step?')"><?= csrfField() ?><input type="hidden" name="action" value="timeline_delete"><?= signedRecordField((int) $timelineRow['id'], 'history_timeline') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
