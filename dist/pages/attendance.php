<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Attendance | School Admin';
$date = (string) ($_GET['date'] ?? date('Y-m-d'));
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$classId = trim((string) ($_GET['cls'] ?? ''));
$sectionId = trim((string) ($_GET['sections'] ?? ''));
$teacher = getAuthenticatedTeacher();
$seeAll = canViewAllAttendance($teacher);
$classes = classesForAttendance($db, $teacher, $sessionYear);

if (!$seeAll && $classId === '' && count($classes) === 1) {
    $classId = (string) (int) $classes[0]['id'];
}

$hasEntireClass = $seeAll;
if (!$seeAll && $classId !== '') {
    foreach (classTeacherAssignments($db, (string) ($teacher['teacher_mobile'] ?? ''), $sessionYear) as $row) {
        if ((int) $row['class_id'] === (int) $classId && (int) $row['section_id'] === 0) {
            $hasEntireClass = true;
            break;
        }
    }
}

$sections = $classId !== '' ? sectionsForAttendance($db, (int) $classId, $teacher, $sessionYear) : [];
if (!$seeAll && !$hasEntireClass && $sectionId === '' && count($sections) === 1) {
    $sectionId = (string) (int) $sections[0]['id'];
}

$allowedClass = $classId !== '' && canTakeAttendanceForClass(
    $db,
    (int) $classId,
    $sectionId === '' ? 0 : (int) $sectionId,
    $sessionYear,
    $teacher
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = (string) ($_POST['attendance_date'] ?? date('Y-m-d'));
    $sessionYear = trim((string) ($_POST['session_year'] ?? ''));
    $classId = trim((string) ($_POST['cls'] ?? ''));
    $sectionId = trim((string) ($_POST['sections'] ?? ''));
    $query = http_build_query(['date' => $date, 'session_year' => $sessionYear, 'cls' => $classId, 'sections' => $sectionId]);
    try {
      $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
      $dateErrors = DateTimeImmutable::getLastErrors();
      if ($dateObject === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $dateObject->format('Y-m-d') !== $date
      ) {
        throw new RuntimeException('Enter a valid attendance date.');
      }
        if ($classId === '' || !canTakeAttendanceForClass($db, (int) $classId, $sectionId === '' ? 0 : (int) $sectionId, $sessionYear, $teacher)) {
            throw new RuntimeException('You can take attendance only for the class assigned to you as class teacher.');
        }
        $sql = 'SELECT id FROM students_info WHERE status = 1 AND cls = :cls';
        $params = ['cls' => (int) $classId];
        if ($sessionYear !== '') {
            $sql .= ' AND session_year = :session_year';
            $params['session_year'] = $sessionYear;
        }
        if ($sectionId !== '') {
            $sql .= ' AND sections = :sections';
            $params['sections'] = $sectionId;
        }
        $allowedStudents = [];
        foreach (fetchRows($db, $sql, $params) as $row) {
            $allowedStudents[(int) $row['id']] = true;
        }
        $marks = $_POST['status'] ?? [];
        if (!is_array($marks)) {
            $marks = [];
        }
        $statement = $db->prepare(
            'INSERT INTO student_attendance (student_id, attendance_date, status, remarks, marked_by)
             VALUES (:student_id, :attendance_date, :status, :remarks, :marked_by)
             ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), marked_by = VALUES(marked_by)'
        );
        $saved = 0;
        foreach ($marks as $studentRef => $status) {
            $studentId = idFromSignedRecordRef((string) $studentRef, 'student');
            if (!isset($allowedStudents[$studentId]) || !in_array($status, ['present', 'absent', 'late'], true)) {
                continue;
            }
            $statement->execute([
                'student_id' => $studentId,
                'attendance_date' => $date,
                'status' => $status,
                'remarks' => trim((string) ($_POST['remarks'][$studentRef] ?? '')),
                'marked_by' => (string) ($teacher['teacher_mobile'] ?? ''),
            ]);
            $saved++;
        }
        redirectWithMessage('attendance.php?' . $query, 'success', 'Attendance saved for ' . $saved . ' student(s) on ' . $date . '.');
    } catch (Throwable $exception) {
        redirectWithMessage('attendance.php?' . $query, 'danger', userFacingError($exception));
    }
}

$students = [];
if ($allowedClass) {
    $params = [];
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.session_year, s.student_pic FROM students_info s'
        . studentsListJoinSql() . ' WHERE s.status = 1';
    if ($sessionYear !== '') {
        $sql .= ' AND s.session_year = :session_year';
        $params['session_year'] = $sessionYear;
    }
    $sql .= ' AND s.cls = :cls';
    $params['cls'] = (int) $classId;
    if ($sectionId !== '') {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = $sectionId;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 400';
    $students = fetchRows($db, $sql, $params);
}

$existing = [];
$remarks = [];
if ($students !== []) {
    $ids = implode(',', array_map(static fn ($row) => (int) $row['id'], $students));
    $rows = fetchRows($db, "SELECT student_id, status, remarks FROM student_attendance WHERE attendance_date = :d AND student_id IN ($ids)", ['d' => $date]);
    foreach ($rows as $row) {
        $existing[(int) $row['student_id']] = (string) $row['status'];
        $remarks[(int) $row['student_id']] = (string) ($row['remarks'] ?? '');
    }
}

$summary = ['present' => 0, 'absent' => 0, 'late' => 0];
foreach ($students as $student) {
    $current = (string) ($existing[(int) $student['id']] ?? '');
    if (isset($summary[$current])) {
        $summary[$current]++;
    }
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  cls?.addEventListener('change', async () => {
    sections.innerHTML = sections.dataset.allowAll === '1' ? '<option value="">All sections</option>' : '<option value="">Select</option>';
    if (!cls.value) return;
    const session = document.querySelector('input[name="session_year"]')?.value || '';
    const rows = await (await fetch('academic-api.php?type=sections&for=attendance&class_id=' + encodeURIComponent(cls.value) + '&session_year=' + encodeURIComponent(session))).json();
    rows.forEach((row) => sections.add(new Option(row.section_name_ban || row.section_name, row.id)));
  });
  document.querySelectorAll('[data-mark-all]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('input[data-status="' + button.dataset.markAll + '"]').forEach((input) => { input.checked = true; });
    });
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">হাজিরা / Attendance</h3>
            <p class="text-secondary mb-0">
              <?php if ($seeAll): ?>
                Mark a class for a date. Use bulk buttons, then save.
              <?php else: ?>
                You can mark attendance only for the class assigned to you as class teacher.
              <?php endif; ?>
            </p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <?php if (!$seeAll && $classes === []): ?>
              <div class="alert alert-warning">You are not assigned as a class teacher for this session. Ask the Head Teacher to appoint you under Class teachers.</div>
            <?php endif; ?>
            <form method="get" class="card stat-card mb-3">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label">Date</label><input class="form-control" type="date" name="date" value="<?= h($date) ?>"></div>
                <div class="col-md-2"><label class="form-label">Session</label><input class="form-control" name="session_year" value="<?= h($sessionYear) ?>"></div>
                <div class="col-md-3">
                  <label class="form-label">Class</label>
                  <select class="form-select" name="cls" id="filterClass" <?= $seeAll ? '' : 'required' ?>>
                    <?php if ($seeAll): ?><option value="">All</option><?php endif; ?>
                    <?php if (!$seeAll): ?><option value="">Select</option><?php endif; ?>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?= (int) $class['id'] ?>" <?= $classId === (string) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Section</label>
                  <select class="form-select" name="sections" id="filterSection" data-allow-all="<?= $hasEntireClass ? '1' : '0' ?>">
                    <?php if ($hasEntireClass): ?>
                      <option value="" <?= $sectionId === '' ? 'selected' : '' ?>>All sections</option>
                    <?php else: ?>
                      <option value="">Select</option>
                    <?php endif; ?>
                    <?php foreach ($sections as $section): ?>
                      <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (string) $section['id'] ? 'selected' : '' ?>><?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100">Load</button></div>
              </div>
            </form>
            <div class="row g-3 mb-3">
              <div class="col-md-4"><div class="card stat-card"><div class="card-body">Present <strong><?= (int) $summary['present'] ?></strong></div></div></div>
              <div class="col-md-4"><div class="card stat-card"><div class="card-body">Absent <strong><?= (int) $summary['absent'] ?></strong></div></div></div>
              <div class="col-md-4"><div class="card stat-card"><div class="card-body">Late <strong><?= (int) $summary['late'] ?></strong></div></div></div>
            </div>
            <form method="post" class="card stat-card">
              <?= csrfField() ?>
              <input type="hidden" name="attendance_date" value="<?= h($date) ?>">
              <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
              <input type="hidden" name="cls" value="<?= h($classId) ?>">
              <input type="hidden" name="sections" value="<?= h($sectionId) ?>">
              <div class="card-header d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-success" data-mark-all="present">All present</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-mark-all="absent">All absent</button>
                <button type="button" class="btn btn-sm btn-outline-warning" data-mark-all="late">All late</button>
              </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th>Roll</th><th>Student</th><th>Present</th><th>Absent</th><th>Late</th><th>Remarks</th></tr></thead>
                  <tbody>
                    <?php foreach ($students as $student): ?>
                      <?php
                        $studentRef = signedRecordRef((int) $student['id'], 'student');
                        $current = (string) ($existing[(int) $student['id']] ?? '');
                      ?>
                      <tr>
                        <td><?= h((string) $student['cls_roll']) ?></td>
                        <td><?= h((string) $student['name_ban']) ?><div class="small text-secondary"><?= h((string) $student['name_eng']) ?></div></td>
                        <?php foreach (['present', 'absent', 'late'] as $status): ?>
                          <td>
                            <input class="form-check-input" type="radio" data-status="<?= h($status) ?>" name="status[<?= h($studentRef) ?>]" value="<?= h($status) ?>" <?= $current === $status ? 'checked' : '' ?>>
                          </td>
                        <?php endforeach; ?>
                        <td><input class="form-control form-control-sm" name="remarks[<?= h($studentRef) ?>]" value="<?= h($remarks[(int) $student['id']] ?? '') ?>"></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($students === []): ?><tr><td colspan="6" class="text-center text-secondary py-4">Select your assigned class<?= $hasEntireClass ? '' : ' and section' ?>, then load students.</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="card-footer"><button class="btn btn-success" <?= $students === [] ? 'disabled' : '' ?>>Save attendance</button></div>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
