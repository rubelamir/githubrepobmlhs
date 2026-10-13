<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Academic calendar | School Admin';
$types = ['holiday' => 'Holiday', 'exam' => 'Exam', 'event' => 'Event'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $eventDate = trim((string) ($_POST['event_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $type = trim((string) ($_POST['event_type'] ?? 'event'));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($title === '' || $eventDate === '') {
                throw new RuntimeException('Title and date are required.');
            }
            if (!isset($types[$type])) {
                throw new RuntimeException('Choose a valid type.');
            }
            $eventDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
            $eventDateErrors = DateTimeImmutable::getLastErrors();
            if ($eventDateObject === false
              || ($eventDateErrors !== false && ($eventDateErrors['warning_count'] > 0 || $eventDateErrors['error_count'] > 0))
              || $eventDateObject->format('Y-m-d') !== $eventDate
            ) {
                throw new RuntimeException('Enter a valid start date.');
            }
            if ($endDate !== '') {
              $endDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
              $endDateErrors = DateTimeImmutable::getLastErrors();
              if ($endDateObject === false
                || ($endDateErrors !== false && ($endDateErrors['warning_count'] > 0 || $endDateErrors['error_count'] > 0))
                || $endDateObject->format('Y-m-d') !== $endDate
              ) {
                throw new RuntimeException('Enter a valid end date.');
              }
              if ($endDateObject < $eventDateObject) {
                throw new RuntimeException('End date cannot be before the start date.');
              }
            }
            $payload = [
                'title' => $title,
                'event_date' => $eventDate,
                'end_date' => $endDate !== '' ? $endDate : null,
                'event_type' => $type,
                'description' => $description !== '' ? $description : null,
            ];
            if ($action === 'update') {
                $payload['id'] = postedRecordId('calendar');
                $db->prepare(
                    'UPDATE academic_calendar_items
                     SET title = :title, event_date = :event_date, end_date = :end_date,
                         event_type = :event_type, description = :description
                     WHERE id = :id'
                )->execute($payload);
                redirectWithMessage('academic-calendar.php', 'success', 'Calendar item updated.');
            }
            $db->prepare(
                'INSERT INTO academic_calendar_items (title, event_date, end_date, event_type, description, item_status)
                 VALUES (:title, :event_date, :end_date, :event_type, :description, 1)'
            )->execute($payload);
            redirectWithMessage('academic-calendar.php', 'success', 'Calendar item published.');
        }
        if ($action === 'toggle') {
            $db->prepare('UPDATE academic_calendar_items SET item_status = IF(item_status = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('calendar')]);
            redirectWithMessage('academic-calendar.php', 'success', 'Item visibility updated.');
        }
        if ($action === 'delete') {
            $db->prepare('DELETE FROM academic_calendar_items WHERE id = :id')->execute(['id' => postedRecordId('calendar')]);
            redirectWithMessage('academic-calendar.php', 'success', 'Calendar item deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('academic-calendar.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('calendar');
$editRows = $editId > 0 ? fetchRows($db, 'SELECT * FROM academic_calendar_items WHERE id = :id LIMIT 1', ['id' => $editId]) : [];
$editRow = $editRows[0] ?? null;
$isEdit = is_array($editRow);
$rows = tableExists($db, 'academic_calendar_items')
    ? fetchRows($db, 'SELECT * FROM academic_calendar_items ORDER BY event_date DESC, id DESC')
    : [];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Academic calendar</h3>
            <p class="text-secondary mb-0">Monthly holidays, exams and events shown on the public calendar.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3">
              <div class="col-lg-4">
                <div class="card stat-card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><?= $isEdit ? 'Edit item' : 'Add item' ?></h3>
                    <?php if ($isEdit): ?><a class="btn btn-sm btn-outline-secondary" href="academic-calendar.php">New</a><?php endif; ?>
                  </div>
                  <div class="card-body">
                    <form method="post">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                      <?php if ($isEdit): ?><?= signedRecordField((int) $editRow['id'], 'calendar') ?><?php endif; ?>
                      <div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" required value="<?= h($isEdit ? (string) $editRow['title'] : '') ?>"></div>
                      <div class="mb-3"><label class="form-label">Type</label>
                        <select class="form-select" name="event_type">
                          <?php foreach ($types as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $isEdit && (string) $editRow['event_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-3"><label class="form-label">Start date</label><input class="form-control" type="date" name="event_date" required value="<?= h($isEdit ? (string) $editRow['event_date'] : '') ?>"></div>
                      <div class="mb-3"><label class="form-label">End date <span class="text-secondary">(optional)</span></label><input class="form-control" type="date" name="end_date" value="<?= h($isEdit ? (string) ($editRow['end_date'] ?? '') : '') ?>"></div>
                      <div class="mb-3"><label class="form-label">Note</label><textarea class="form-control" name="description" rows="3"><?= h($isEdit ? (string) ($editRow['description'] ?? '') : '') ?></textarea></div>
                      <button class="btn btn-primary w-100"><?= $isEdit ? 'Update' : 'Publish' ?></button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="col-lg-8">
                <div class="card stat-card">
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Status</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($rows as $row): ?>
                          <tr>
                            <td><?= h((string) $row['event_date']) ?><?= !empty($row['end_date']) ? ' – ' . h((string) $row['end_date']) : '' ?></td>
                            <td><?= h((string) $row['title']) ?></td>
                            <td><?= h($types[(string) $row['event_type']] ?? (string) $row['event_type']) ?></td>
                            <td><?= (int) $row['item_status'] === 1 ? 'Live' : 'Hidden' ?></td>
                            <td class="text-nowrap">
                              <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('academic-calendar.php', (int) $row['id'], 'calendar')) ?>">Edit</a>
                              <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'calendar') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this item?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'calendar') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?><tr><td colspan="5" class="text-center text-secondary py-4">No calendar items yet.</td></tr><?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
