<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$viewer = getAuthenticatedTeacher();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!canAccessModule('teachers')) {
            denyPanelAccess();
        }

        if ($action === 'delete') {
            $teacherId = postedRecordId('teacher');
            if ($teacherId < 1) {
                throw new RuntimeException('Teacher not found.');
            }
            if ((int) ($viewer['id'] ?? 0) === $teacherId) {
                throw new RuntimeException('You cannot delete your own staff record.');
            }
            $target = fetchRows($db, 'SELECT * FROM teacher_info WHERE id = :id LIMIT 1', ['id' => $teacherId]);
            if ($target === []) {
                throw new RuntimeException('Teacher not found.');
            }
            if (currentPanelRole($target[0]) === 'head' && headAdminCount($db) <= 1) {
                throw new RuntimeException('The last Head Teacher account cannot be deleted.');
            }
            deleteTeacherRecord($db, $teacherId);
            redirectWithMessage('view_all_teacherlist.php#teacher-order', 'success', 'Staff record and files deleted.');
        }

        if ($action === 'move') {
            $moved = moveTeacherInList($db, postedRecordId('teacher'), (string) ($_POST['direction'] ?? ''));
            if (!$moved) {
                redirectWithMessage('view_all_teacherlist.php#teacher-order', 'info', 'Already at the edge of the list.');
            }
            redirectWithMessage('view_all_teacherlist.php#teacher-order', 'success', 'Teacher list order updated.');
        }

        if ($action === 'reorder') {
            $order = $_POST['order'] ?? [];
            if (!is_array($order) || $order === []) {
                throw new RuntimeException('No teacher order was submitted.');
            }
            saveTeacherListOrder($db, idsFromSignedRecordRefs(is_array($order) ? $order : [], 'teacher'));
            redirectWithMessage('view_all_teacherlist.php#teacher-order', 'success', 'Teacher list order updated.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('view_all_teacherlist.php', 'danger', userFacingError($exception));
    }
}

$sql = 'SELECT id, teacher_name, teacher_designation, teacher_mobile, teacher_pic, teacher_status';
if (columnExists($db, 'teacher_info', 'sort_order')) {
    $sql .= ', sort_order';
}
$sql .= ' FROM teacher_info ORDER BY ' . teacherListOrderSql($db);

$teachers = $db->query($sql)->fetchAll();
$canOrderTeachers = canAccessModule('teachers');
$basePath = '../';
$pageTitle = 'Teachers & Staff | School Admin';
$pageStyles = <<<'CSS'
.teacher-order-thumb { width: 40px; height: 50px; object-fit: cover; flex-shrink: 0; }
.teacher-order-handle { cursor: grab; color: var(--bs-secondary-color); }
.teacher-order-handle:active { cursor: grabbing; }
#teacherOrderList tr.is-dragging { opacity: .45; }
CSS;
$pageScripts = [<<<'JS'
(() => {
  const list = document.getElementById('teacherOrderList');
  const form = document.getElementById('teacherReorderForm');
  const box = document.getElementById('teacherReorderInputs');
  if (!list || !form || !box) return;

  const original = Array.from(list.querySelectorAll('tr[data-teacher-id]'))
    .map((row) => row.getAttribute('data-teacher-id'))
    .join(',');
  let dragRow = null;

  list.addEventListener('dragstart', (event) => {
    const row = event.target.closest('tr[data-teacher-id]');
    if (!row || event.target.closest('button, a, input, form')) {
      event.preventDefault();
      return;
    }
    dragRow = row;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', row.getAttribute('data-teacher-id') || '');
    row.classList.add('is-dragging');
  });

  list.addEventListener('dragover', (event) => {
    event.preventDefault();
    const row = event.target.closest('tr[data-teacher-id]');
    if (!dragRow || !row || row === dragRow || !list.contains(row)) return;
    const rect = row.getBoundingClientRect();
    const after = (event.clientY - rect.top) > (rect.height / 2);
    list.insertBefore(dragRow, after ? row.nextSibling : row);
  });

  list.addEventListener('drop', (event) => event.preventDefault());

  list.addEventListener('dragend', () => {
    if (dragRow) dragRow.classList.remove('is-dragging');
    dragRow = null;
    const ids = Array.from(list.querySelectorAll('tr[data-teacher-id]'))
      .map((row) => row.getAttribute('data-teacher-id'));
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
                <h1 class="mb-0">Teacher &amp; Employee List</h1>
                <p class="text-secondary mb-0">Use the arrows or drag rows to change the order shown on the public website.</p>
              </div>
              <div class="d-flex gap-2">
                <a href="add_teacher.php" class="btn btn-success"><i class="bi bi-person-plus me-1"></i>Add New</a>
                <?php if (isHeadAdmin()): ?>
                <a href="admin-roles.php" class="btn btn-outline-warning"><i class="bi bi-shield-lock me-1"></i>Admin roles</a>
                <?php endif; ?>
                <a href="../index.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Back</a>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="card stat-card mb-4" id="teacher-order">
              <div class="card-header">
                <h2 class="h5 mb-0">Website display order</h2>
                <p class="text-secondary small mb-0">This order is used on the Teachers page and the homepage teacher carousel.</p>
              </div>
              <?php if ($canOrderTeachers): ?>
              <form method="post" id="teacherReorderForm" class="d-none">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reorder">
                <div id="teacherReorderInputs"></div>
              </form>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="width:9.5rem">Order</th>
                      <th>Teacher &amp; Employee Name</th>
                      <th>Designation</th>
                      <th>Status</th>
                      <th>Print ID Card</th>
                      <th>Delete</th>
                      <th>View</th>
                    </tr>
                  </thead>
                  <tbody id="teacherOrderList">
                    <?php if ($teachers === []): ?>
                      <tr><td colspan="7" class="text-secondary">No teacher or employee records found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($teachers as $index => $teacher): ?>
                      <?php
                        $orderId = (int) ($teacher['id'] ?? 0);
                        $orderName = (string) ($teacher['teacher_name'] ?? 'Teacher');
                        $orderMobile = (string) ($teacher['teacher_mobile'] ?? '');
                        $orderDesignation = getDesignationLabel((string) ($teacher['teacher_designation'] ?? ''));
                        $orderPicture = schoolFileUrl((string) ($teacher['teacher_pic'] ?? ''), 'teacher_pic');
                        $isFirst = $index === 0;
                        $isLast = $index === count($teachers) - 1;
                        $canDelete = $canOrderTeachers && $orderId > 0 && $orderId !== (int) ($viewer['id'] ?? 0);
                      ?>
                      <tr<?= $canOrderTeachers ? ' draggable="true"' : '' ?> data-teacher-id="<?= h(signedRecordRef((int) $orderId, 'teacher')) ?>">
                        <td class="text-nowrap">
                          <?php if ($canOrderTeachers): ?>
                            <span class="teacher-order-handle me-1" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="up">
                              <?= signedRecordField((int) $orderId, 'teacher') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move up"<?= $isFirst ? ' disabled' : '' ?>>&uarr;</button>
                            </form>
                            <form method="post" class="d-inline">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="move">
                              <input type="hidden" name="direction" value="down">
                              <?= signedRecordField((int) $orderId, 'teacher') ?>
                              <button class="btn btn-sm btn-outline-secondary" title="Move down"<?= $isLast ? ' disabled' : '' ?>>&darr;</button>
                            </form>
                          <?php endif; ?>
                          <span class="text-secondary small ms-1"><?= $index + 1 ?></span>
                        </td>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <?php if ($orderPicture !== ''): ?>
                              <img src="<?= h($orderPicture) ?>" alt="" class="teacher-order-thumb rounded">
                            <?php else: ?>
                              <span class="teacher-order-thumb rounded d-inline-flex align-items-center justify-content-center bg-body-secondary text-secondary"><?= h(strtoupper(substr($orderName, 0, 1))) ?></span>
                            <?php endif; ?>
                            <strong><?= h($orderName) ?></strong>
                          </div>
                        </td>
                        <td><?= h($orderDesignation !== '' ? $orderDesignation : 'Employee') ?></td>
                        <td><?= (int) ($teacher['teacher_status'] ?? 0) === 1 ? 'Active' : 'Hidden' ?></td>
                        <td class="text-nowrap">
                          <?php if ($orderId > 0): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= h(signedRecordUrl('teacher-id-card.php', (int) $orderId, 'teacher')) ?>" target="_blank" rel="noopener">
                              <i class="bi bi-credit-card-2-front me-1"></i>Print ID Card
                            </a>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <?php if ($canDelete): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this staff record and their photos? This cannot be undone.')">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="delete">
                              <?= signedRecordField((int) $orderId, 'teacher') ?>
                              <button class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash me-1"></i>Delete
                              </button>
                            </form>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                          <?php if ($orderMobile !== ''): ?>
                            <a class="btn btn-sm btn-primary" href="profile.php?teacher_mobile=<?= h(rawurlencode($orderMobile)) ?>">
                              <i class="bi bi-person-vcard me-1"></i>View
                            </a>
                          <?php else: ?>
                            —
                          <?php endif; ?>
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
