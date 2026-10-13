<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Admissions | School Admin';
$classes = schoolClasses($db);
$teacher = getAuthenticatedTeacher();
$statusFilter = trim((string) ($_GET['status'] ?? '0'));
$sessionYear = trim((string) ($_GET['session_year'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $studentId = postedRecordId('student');
    try {
        if ($action === 'approve') {
            $cls = (int) ($_POST['cls'] ?? 0);
            $section = trim((string) ($_POST['sections'] ?? ''));
            $roll = trim((string) ($_POST['cls_roll'] ?? ''));
            $session = trim((string) ($_POST['session_year'] ?? date('Y')));
            if ($cls < 1 || $section === '' || $roll === '') {
                throw new RuntimeException('Class, section and roll are required to admit a student.');
            }
            assertActiveStudentRollAvailable($db, $cls, $session, $section, $roll, $studentId);
            $db->prepare(
                'UPDATE students_info SET cls = :cls, sections = :sections, cls_roll = :roll, session_year = :session_year, status = 1 WHERE id = :id'
            )->execute([
                'cls' => $cls,
                'sections' => $section,
                'roll' => (int) $roll,
                'session_year' => $session,
                'id' => $studentId,
            ]);
            openStudentEnrollmentPeriod($db, $studentId, $session, $cls, (int) $section, (string) (int) $roll, 'admission');
            redirectWithMessage('admissions.php?status=0', 'success', 'Student admitted and placed in class.');
        }
        if ($action === 'reject') {
            $db->prepare('UPDATE students_info SET status = 2 WHERE id = :id')->execute(['id' => $studentId]);
            redirectWithMessage('admissions.php?status=2', 'success', 'Application rejected.');
        }
        if ($action === 'restore') {
            $db->prepare('UPDATE students_info SET status = 0 WHERE id = :id')->execute(['id' => $studentId]);
            redirectWithMessage('admissions.php?status=0', 'success', 'Application moved back to pending.');
        }
        if ($action === 'delete') {
            deleteStudentRecord($db, $studentId);
            redirectWithMessage('admissions.php?status=' . rawurlencode($statusFilter), 'success', 'Application and files deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('admissions.php', 'danger', userFacingError($exception));
    }
}

$sql = 'SELECT s.*, c.class_name_ban, sec.section_name_ban
        FROM students_info s
        LEFT JOIN cls c ON s.cls = c.id
        LEFT JOIN sections sec ON s.sections = sec.id';
$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 's.status = :status';
    $params['status'] = (int) $statusFilter;
}
if ($sessionYear !== '') {
    $where[] = 's.session_year = :session_year';
    $params['session_year'] = $sessionYear;
}
if ($search !== '') {
    $where[] = '(s.name_ban LIKE :q_name_ban OR s.name_eng LIKE :q_name_eng OR s.mobile LIKE :q_mobile OR s.admission_token LIKE :q_token)';
    $like = '%' . $search . '%';
    $params['q_name_ban'] = $like;
    $params['q_name_eng'] = $like;
    $params['q_mobile'] = $like;
    $params['q_token'] = $like;
}
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 200';
try {
    $rows = fetchRows($db, $sql, $params);
} catch (PDOException $exception) {
    $rows = fetchRows(
        $db,
        'SELECT s.* FROM students_info s' . studentsListJoinSql() . ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 200'
    );
}

$pendingCount = tableCount($db, 'students_info', 'status = 0');
$admittedCount = tableCount($db, 'students_info', 'status = 1');
$rejectedCount = tableCount($db, 'students_info', 'status = 2');

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-class-select').forEach((cls) => {
    cls.addEventListener('change', async () => {
      const form = cls.closest('form');
      const sections = form.querySelector('.js-section-select');
      sections.innerHTML = '<option value="">Select section</option>';
      if (!cls.value) return;
      const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
      rows.forEach((row) => sections.add(new Option(row.section_name_ban, row.id)));
    });
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">ভর্তি প্রক্রিয়া / Admissions</h3>
              <p class="text-secondary mb-0">Review online applications, assign class–section–roll, then admit.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-outline-secondary" href="admission-tokens.php">Admission tokens</a>
              <a class="btn btn-primary" href="student-form.php"><i class="bi bi-person-plus me-1"></i>Office admission</a>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3 mb-3">
              <div class="col-md-4"><a href="admissions.php?status=0" class="card stat-card text-decoration-none"><div class="card-body"><div class="text-secondary">Pending</div><div class="fs-3 fw-semibold"><?= (int) $pendingCount ?></div></div></a></div>
              <div class="col-md-4"><a href="admissions.php?status=1" class="card stat-card text-decoration-none"><div class="card-body"><div class="text-secondary">Admitted</div><div class="fs-3 fw-semibold"><?= (int) $admittedCount ?></div></div></a></div>
              <div class="col-md-4"><a href="admissions.php?status=2" class="card stat-card text-decoration-none"><div class="card-body"><div class="text-secondary">Rejected</div><div class="fs-3 fw-semibold"><?= (int) $rejectedCount ?></div></div></a></div>
            </div>
            <form class="card stat-card mb-3">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label">Search</label><input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Name, token or mobile"></div>
                <div class="col-md-3"><label class="form-label">Session</label><input class="form-control" name="session_year" value="<?= h($sessionYear) ?>"></div>
                <div class="col-md-3">
                  <label class="form-label">Status</label>
                  <select class="form-select" name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Pending</option>
                    <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Admitted</option>
                    <option value="2" <?= $statusFilter === '2' ? 'selected' : '' ?>>Rejected</option>
                  </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filter</button></div>
              </div>
            </form>
            <div class="card stat-card">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Photo</th><th>Applicant</th><th>Session</th><th>Token</th><th>Mobile</th><th>Status</th><th>Placement / action</th></tr></thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <?php [$label, $badge] = admissionStatusLabel($row['status'] ?? 0); ?>
                      <tr>
                        <td><img src="<?= h(studentPhotoUrl($row['student_pic'] ?? '')) ?>" width="40" height="50" class="rounded" style="object-fit:cover" alt=""></td>
                        <td>
                          <div class="fw-semibold"><?= h((string) $row['name_ban']) ?></div>
                          <div class="small text-secondary"><?= h((string) $row['name_eng']) ?> · <?= h((string) ($row['gender'] ?? '')) ?></div>
                        </td>
                        <td><?= h((string) $row['session_year']) ?></td>
                        <td><?= h((string) ($row['admission_token'] ?? '')) ?></td>
                        <td><?= h((string) $row['mobile']) ?></td>
                        <td><span class="badge text-bg-<?= h($badge) ?>"><?= h($label) ?></span></td>
                        <td>
                          <?php if ((int) $row['status'] !== 1): ?>
                            <form method="post" class="row g-1 align-items-end">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="approve">
                              <?= signedRecordField((int) $row['id'], 'student') ?>
                              <div class="col-6 col-xl-3">
                                <select class="form-select form-select-sm js-class-select" name="cls" required>
                                  <option value="">Class</option>
                                  <?php foreach ($classes as $class): ?>
                                    <option value="<?= (int) $class['id'] ?>"><?= h($class['class_name_ban']) ?></option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                              <div class="col-6 col-xl-3">
                                <select class="form-select form-select-sm js-section-select" name="sections" required>
                                  <option value="">Section</option>
                                </select>
                              </div>
                              <div class="col-6 col-xl-2"><input class="form-control form-control-sm" name="cls_roll" placeholder="Roll" required></div>
                              <div class="col-6 col-xl-2"><input class="form-control form-control-sm" name="session_year" value="<?= h((string) $row['session_year']) ?>"></div>
                              <div class="col-12 col-xl-2 d-flex gap-1">
                                <button class="btn btn-sm btn-success">Admit</button>
                              </div>
                            </form>
                            <form method="post" class="d-inline mt-1">
                              <?= csrfField() ?>
                              <?= signedRecordField((int) $row['id'], 'student') ?>
                              <?php if ((int) $row['status'] === 2): ?>
                                <input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-secondary">Restore</button>
                              <?php else: ?>
                                <input type="hidden" name="action" value="reject"><button class="btn btn-sm btn-outline-danger">Reject</button>
                              <?php endif; ?>
                            </form>
                            <form method="post" class="d-inline mt-1" onsubmit="return confirm('Permanently delete this application and its photos?')">
                              <?= csrfField() ?>
                              <input type="hidden" name="action" value="delete">
                              <?= signedRecordField((int) $row['id'], 'student') ?>
                              <button class="btn btn-sm btn-outline-dark">Delete</button>
                            </form>
                          <?php else: ?>
                            <div class="small"><?= h((string) ($row['class_name_ban'] ?? '')) ?> · <?= h((string) ($row['section_name_ban'] ?? '')) ?> · Roll <?= h((string) $row['cls_roll']) ?></div>
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('student-form.php', (int) $row['id'], 'student')) ?>">Edit</a>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-secondary py-4">No applications found.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
