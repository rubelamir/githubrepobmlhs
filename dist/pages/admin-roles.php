<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$actor = getAuthenticatedTeacher();
$basePath = '../';
$pageTitle = 'Admin Roles | School Admin';
$teachers = fetchRows(
    $db,
    'SELECT * FROM teacher_info ORDER BY ' . teacherListOrderSql($db)
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $role = (string) ($_POST['panel_role'] ?? 'teacher');
    $fullAdmin = isset($_POST['full_admin']);
    $modules = $fullAdmin ? ['*'] : (array) ($_POST['modules'] ?? []);
    try {
        if ($teacherId < 1) {
            throw new RuntimeException('Select a teacher.');
        }
        saveTeacherPanelRole($db, $teacherId, $role, $modules, (int) ($actor['id'] ?? 0));
        redirectWithMessage('admin-roles.php', 'success', 'Admin role saved.');
    } catch (Throwable $exception) {
        redirectWithMessage('admin-roles.php', 'danger', userFacingError($exception));
    }
}

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Admin roles</h3>
            <p class="text-secondary mb-0">Grant full Head Teacher access, or give another teacher only the admin modules they need. Regular teachers keep dashboard + assigned mark entry.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3">
              <?php foreach ($teachers as $item): ?>
                <?php
                  $role = currentPanelRole($item);
                  $mods = decodedAdminModules($item);
                  $isFull = in_array('*', $mods, true);
                ?>
                <div class="col-12">
                  <form method="post" class="card stat-card">
                    <?= csrfField() ?>
                    <input type="hidden" name="teacher_id" value="<?= (int) $item['id'] ?>">
                    <div class="card-header d-flex justify-content-between flex-wrap gap-2">
                      <div>
                        <strong><?= h((string) $item['teacher_name']) ?></strong>
                        <div class="small text-secondary">
                          <?= h(getDesignationLabel((string) $item['teacher_designation'])) ?>
                          · <?= h((string) $item['teacher_mobile']) ?>
                          <?php if ((int) ($item['teacher_status'] ?? 1) !== 1): ?>
                            · <span class="text-danger">Inactive</span>
                          <?php endif; ?>
                        </div>
                      </div>
                      <button class="btn btn-sm btn-primary">Save role</button>
                    </div>
                    <div class="card-body">
                      <div class="row g-3">
                        <div class="col-md-4">
                          <label class="form-label">Panel role</label>
                          <select class="form-select role-select" name="panel_role">
                            <?php foreach (panelRoleOptions() as $value => $label): ?>
                              <option value="<?= h($value) ?>" <?= $role === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-8 admin-modules" <?= $role === 'admin' ? '' : 'style="display:none"' ?>>
                          <div class="form-check mb-2">
                            <input class="form-check-input full-admin" type="checkbox" name="full_admin" value="1" id="full<?= (int) $item['id'] ?>" <?= $isFull ? 'checked' : '' ?>>
                            <label class="form-check-label" for="full<?= (int) $item['id'] ?>">Full admin panel (except Head-only role assignment)</label>
                          </div>
                          <div class="row g-2 module-grid" <?= $isFull ? 'style="opacity:.45;pointer-events:none"' : '' ?>>
                            <?php foreach (panelModuleGroups() as $group => $items): ?>
                              <div class="col-md-6 col-xl-3">
                                <div class="small text-secondary fw-semibold mb-1"><?= h($group) ?></div>
                                <?php foreach ($items as $key => $label): ?>
                                  <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="modules[]" value="<?= h($key) ?>" id="m<?= (int) $item['id'] ?>_<?= h($key) ?>" <?= in_array($key, $mods, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="m<?= (int) $item['id'] ?>_<?= h($key) ?>"><?= h($label) ?></label>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
<?php
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form.card').forEach((form) => {
    const role = form.querySelector('.role-select');
    const modules = form.querySelector('.admin-modules');
    const full = form.querySelector('.full-admin');
    const grid = form.querySelector('.module-grid');
    const sync = () => {
      if (modules) modules.style.display = role?.value === 'admin' ? '' : 'none';
      if (grid && full) {
        grid.style.opacity = full.checked ? '.45' : '';
        grid.style.pointerEvents = full.checked ? 'none' : '';
      }
    };
    role?.addEventListener('change', sync);
    full?.addEventListener('change', sync);
  });
});
JS];
include __DIR__ . '/../includes/layout-end.php';
