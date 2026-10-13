<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Students | School Admin';
$classes = schoolClasses($db);
$panelTeacher = getAuthenticatedTeacher();
$seeAllClasses = canManageModule('students', $panelTeacher);
$search = trim((string) ($_GET['q'] ?? ''));
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$rollFilter = trim((string) ($_GET['cls_roll'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$loadList = (string) ($_GET['load'] ?? '') === '1';
$scope = ['entire' => [], 'sections' => []];
if (!$seeAllClasses) {
    $scope = classTeacherScope($db, $panelTeacher, $sessionYear);
    $classes = array_values(array_filter(
        $classes,
        static fn (array $class): bool => isset($scope['entire'][(int) $class['id']]) || isset($scope['sections'][(int) $class['id']])
    ));
    if ($classId > 0 && !isset($scope['entire'][$classId]) && !isset($scope['sections'][$classId])) {
        $classId = 0;
        $sectionId = 0;
    }
}
$sections = $classId > 0 ? schoolSections($db, $classId) : [];
if (!$seeAllClasses && $classId > 0 && !isset($scope['entire'][$classId])) {
    $sections = array_values(array_filter(
        $sections,
        static fn (array $section): bool => isset($scope['sections'][$classId][(int) $section['id']])
    ));
    if ($sectionId > 0 && !isset($scope['sections'][$classId][$sectionId])) {
        $sectionId = 0;
    }
}
$sessionYears = [];
for ($year = (int) date('Y') + 1; $year >= 2015; $year--) {
    $sessionYears[] = (string) $year;
}
if ($sessionYear !== '' && !in_array($sessionYear, $sessionYears, true)) {
    array_unshift($sessionYears, $sessionYear);
}
$statusOptions = [
    '' => 'সকল Status',
    '0' => 'Pending',
    '1' => 'Admitted',
    '2' => 'Rejected',
    '3' => 'SSC candidate batch',
    '4' => 'Left / Dropout',
];
if ($statusFilter !== '' && !array_key_exists($statusFilter, $statusOptions)) {
    $statusFilter = '';
}

$students = [];
$listMode = 'idle';
if ($loadList) {
    $listMode = 'filtered';
    $filterGet = [
        'q' => $search,
        'session_year' => $sessionYear,
        'cls' => $classId > 0 ? (string) $classId : '',
        'sections' => $sectionId > 0 ? (string) $sectionId : '',
        'cls_roll' => $rollFilter,
        'status' => $statusFilter,
    ];
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.session_year, s.mobile, s.gender, s.student_pic, s.status, s.cls, s.sections,
                   c.class_name_ban, sec.section_name_ban
            FROM students_info s
            LEFT JOIN cls c ON s.cls = c.id
            LEFT JOIN sections sec ON s.sections = sec.id';
    [$where, $params] = studentsQueryFilters($filterGet);
    if (!$seeAllClasses) {
        if ($classId < 1) {
            $where[] = '1 = 0';
        } elseif ($sectionId < 1 && !isset($scope['entire'][$classId])) {
            $allowedSections = array_map('intval', array_keys($scope['sections'][$classId] ?? []));
            $where[] = 's.sections IN (' . ($allowedSections === [] ? '0' : implode(', ', $allowedSections)) . ')';
        }
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 400';
    $students = fetchRows($db, $sql, $params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete') {
    try {
        $deleteId = postedRecordId('student');
        if (!$seeAllClasses) {
            $target = fetchRows($db, 'SELECT cls, sections, session_year FROM students_info WHERE id = :id LIMIT 1', ['id' => $deleteId])[0] ?? [];
            $targetScope = classTeacherScope($db, $panelTeacher, (string) ($target['session_year'] ?? ''));
            if ($target === [] || !classTeacherScopeAllows($targetScope, (int) $target['cls'], (int) $target['sections'])) {
                throw new RuntimeException('You can manage only students of the class assigned to you.');
            }
        }
        deleteStudentRecord($db, $deleteId);
        redirectWithMessage('students.php', 'success', 'Student record and files deleted.');
    } catch (Throwable $exception) {
        redirectWithMessage('students.php', 'danger', userFacingError($exception));
    }
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  const selected = sections?.dataset.selected || '';
  cls?.addEventListener('change', async () => {
    sections.innerHTML = '<option value="">সকল শাখা</option>';
    if (!cls.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
    rows.forEach((row) => {
      const option = new Option(row.section_name_ban, row.id);
      if (String(row.id) === String(selected)) option.selected = true;
      sections.add(option);
    });
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">ছাত্র-ছাত্রী / Students</h3>
              <p class="text-secondary mb-0">শিক্ষাবর্ষ, শ্রেণী, শাখা, Status, রোল ও সার্চ দিয়ে ফিল্টার করুন।</p>
            </div>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-primary" href="<?= h(publicSiteUrl('online_admission.php')) ?>" target="_blank">Public admission form</a>
              <a class="btn btn-primary" href="student-form.php">New admission</a>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3">
              <div class="card-body">
                <input type="hidden" name="load" value="1">
                <div class="row g-2 align-items-end">
                  <div class="col-md-4">
                    <label class="form-label" for="filterSession">শিক্ষাবর্ষ</label>
                    <select class="form-select" name="session_year" id="filterSession">
                      <?php foreach ($sessionYears as $year): ?>
                        <option value="<?= h($year) ?>" <?= $sessionYear === $year ? 'selected' : '' ?>><?= h(bnDigits($year)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="filterClass">শ্রেণী</label>
                    <select class="form-select" name="cls" id="filterClass">
                      <option value="">সকল শ্রেণী</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h($class['class_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="filterSection">শাখা</label>
                    <select class="form-select" name="sections" id="filterSection" data-selected="<?= $sectionId > 0 ? (string) $sectionId : '' ?>">
                      <option value="">সকল শাখা</option>
                      <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h($section['section_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="mt-3">
                  <div class="d-flex gap-2 align-items-end flex-wrap">
                    <div class="flex-grow-1" style="min-width: 140px;">
                      <label class="form-label" for="filterRoll">রোল <span class="text-secondary fw-normal">(ঐচ্ছিক)</span></label>
                      <input class="form-control" type="text" name="cls_roll" id="filterRoll" value="<?= h($rollFilter) ?>" placeholder="যেমন: ১, ৫, ৮-১২">
                    </div>
                    <div class="flex-grow-1" style="min-width: 160px;">
                      <label class="form-label" for="filterStatus">Status <span class="text-secondary fw-normal">(Optional)</span></label>
                      <select class="form-select" name="status" id="filterStatus">
                        <?php foreach ($statusOptions as $value => $label): ?>
                          <option value="<?= h((string) $value) ?>" <?= $statusFilter === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="flex-grow-1" style="min-width: 160px;">
                      <label class="form-label" for="filterSearch">Search <span class="text-secondary fw-normal">(Optional)</span></label>
                      <input class="form-control" type="text" name="q" id="filterSearch" value="<?= h($search) ?>" placeholder="Name, roll or mobile">
                    </div>
                    <div class="flex-shrink-0">
                      <label class="form-label d-none d-sm-block">&nbsp;</label>
                      <button class="btn btn-outline-primary px-4" type="submit">ফিল্টার করুন</button>
                    </div>
                  </div>
                  <div class="form-text">নির্দিষ্ট রোল কমা দিয়ে, রেঞ্জ হাইফেন দিয়ে। যেমন: ১, ৫, ৮-১২</div>
                </div>
              </div>
            </form>
            <?php if ($listMode === 'idle'): ?>
            <div class="card stat-card">
              <div class="card-body text-secondary">ফিল্টার করুন—মিল থাকা শিক্ষার্থীদের তালিকা এখানে দেখাবে।</div>
            </div>
            <?php else: ?>
            <div class="card stat-card">
              <div class="card-header">
                <strong><?= count($students) ?></strong> records
              </div>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead><tr><th>Photo</th><th>Name</th><th>Class</th><th>Roll</th><th>Session</th><th>Mobile</th><th>Status</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($students as $student): ?>
                      <?php [$label, $badge] = admissionStatusLabel($student['status'] ?? 0); ?>
                      <tr>
                        <td><img src="<?= h(studentPhotoUrl($student['student_pic'] ?? '')) ?>" width="40" height="50" class="rounded" style="object-fit:cover" alt=""></td>
                        <td>
                          <div class="fw-semibold"><?= h((string) $student['name_ban']) ?></div>
                          <div class="small text-secondary"><?= h((string) $student['name_eng']) ?></div>
                        </td>
                        <td><?= h((string) ($student['class_name_ban'] ?? '')) ?><div class="small text-secondary"><?= h((string) ($student['section_name_ban'] ?? '')) ?></div></td>
                        <td><?= h((string) $student['cls_roll']) ?></td>
                        <td><?= h((string) $student['session_year']) ?></td>
                        <td><?= h((string) $student['mobile']) ?></td>
                        <td><span class="badge text-bg-<?= h($badge) ?>"><?= h($label) ?></span></td>
                        <td class="text-nowrap">
                          <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('student-form.php', (int) $student['id'], 'student')) ?>">Edit</a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this student and their photos? This cannot be undone.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <?= signedRecordField((int) $student['id'], 'student') ?>
                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($students === []): ?>
                      <tr><td colspan="8" class="text-center text-secondary py-4">No students found.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
