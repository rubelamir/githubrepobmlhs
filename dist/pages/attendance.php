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
if ($sectionId === '0') {
    $sectionId = '';
}
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
$resolvedSection = studentFilterSectionId($sections, (int) $classId, (int) $sectionId);
$sectionId = $resolvedSection > 0 ? (string) $resolvedSection : '';
$filterNotice = isset($_GET['cls']) ? studentFilterSelectionNotice($sections, (int) $classId, $resolvedSection) : '';

$allowedClass = $filterNotice === '' && $classId !== '' && canTakeAttendanceForClass(
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
            if (!isset($allowedStudents[$studentId]) || !in_array($status, ['present', 'absent'], true)) {
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

$summary = ['present' => 0, 'absent' => 0];
foreach ($students as $student) {
    $current = (string) ($existing[(int) $student['id']] ?? '');
    if (isset($summary[$current])) {
        $summary[$current]++;
    }
}

$pageStyles = <<<'CSS'
.att-toggle { display: inline-flex; gap: .5rem; }
.att-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 2.75rem; height: 2.75rem; border-radius: 50%;
  font-weight: 700; font-size: 1.05rem; cursor: pointer; user-select: none;
  border: 2px solid var(--bs-border-color); color: var(--bs-secondary-color);
  background: transparent; transition: background-color .2s ease, color .2s ease, border-color .2s ease, transform .15s ease, box-shadow .2s ease;
  -webkit-tap-highlight-color: transparent;
}
.att-btn:active { transform: scale(.92); }
.att-p { border-color: #198754; color: #198754; }
.att-a { border-color: #dc3545; color: #dc3545; }
.btn-check:checked + .att-p { background: #198754; color: #fff; box-shadow: 0 0 0 .2rem rgba(25,135,84,.25); }
.btn-check:checked + .att-a { background: #dc3545; color: #fff; box-shadow: 0 0 0 .2rem rgba(220,53,69,.25); }
.btn-check:focus-visible + .att-btn { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
CSS;
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
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
            <form method="get" class="card stat-card mb-3" data-student-filter data-sf-title="হাজিরার শ্রেণী নির্বাচন"<?= $allowedClass ? ' data-sf-result-count="' . count($students) . '"' : '' ?>>
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-2"><label class="form-label" for="filterDate">Date</label><input class="form-control" type="date" name="date" id="filterDate" value="<?= h($date) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="filterSession">Session</label><input class="form-control" name="session_year" id="filterSession" value="<?= h($sessionYear) ?>" inputmode="numeric"></div>
                <div class="col-md-3">
                  <label class="form-label">Class</label>
                  <select class="form-select" name="cls" id="filterClass" required>
                    <option value="">শ্রেণী নির্বাচন করুন</option>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?= (int) $class['id'] ?>" <?= $classId === (string) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Section</label>
                  <select class="form-select" name="sections" id="filterSection" required data-sf-sections="for=attendance">
                    <?= studentFilterSectionOptionsHtml($sections, (int) $classId, $sectionId) ?>
                  </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100">Load</button></div>
              </div>
            </form>
            <?php if ($filterNotice !== ''): ?>
              <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= h($filterNotice) ?></div>
            <?php endif; ?>
            <div class="row g-3 mb-3">
              <div class="col-6"><div class="card stat-card"><div class="card-body">Present <strong><?= (int) $summary['present'] ?></strong></div></div></div>
              <div class="col-6"><div class="card stat-card"><div class="card-body">Absent <strong><?= (int) $summary['absent'] ?></strong></div></div></div>
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
              </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0" data-sf-table="রোল (যেমন ৫ বা ১-১০) বা নাম দিয়ে খুঁজুন…" data-sf-roll-col="0">
                  <thead><tr><th>Roll</th><th>Student</th><th class="text-center">Attendance</th><th>Remarks</th></tr></thead>
                  <tbody>
                    <?php foreach ($students as $student): ?>
                      <?php
                        $studentRef = signedRecordRef((int) $student['id'], 'student');
                        $current = (string) ($existing[(int) $student['id']] ?? '');
                      ?>
                      <tr>
                        <td><?= h((string) $student['cls_roll']) ?></td>
                        <td><?= h((string) $student['name_ban']) ?><div class="small text-secondary"><?= h((string) $student['name_eng']) ?></div></td>
                        <td class="text-center text-nowrap">
                          <div class="att-toggle" role="group" aria-label="Attendance">
                            <input class="btn-check" type="radio" id="p-<?= h($studentRef) ?>" data-status="present" name="status[<?= h($studentRef) ?>]" value="present" <?= $current === 'present' ? 'checked' : '' ?>>
                            <label class="att-btn att-p" for="p-<?= h($studentRef) ?>" title="Present">P</label>
                            <input class="btn-check" type="radio" id="a-<?= h($studentRef) ?>" data-status="absent" name="status[<?= h($studentRef) ?>]" value="absent" <?= $current === 'absent' ? 'checked' : '' ?>>
                            <label class="att-btn att-a" for="a-<?= h($studentRef) ?>" title="Absent">A</label>
                          </div>
                        </td>
                        <td><input class="form-control form-control-sm" name="remarks[<?= h($studentRef) ?>]" value="<?= h($remarks[(int) $student['id']] ?? '') ?>"></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($students === []): ?><tr><td colspan="4" class="text-center text-secondary py-4">শ্রেণী ও শাখা নির্বাচন করে Load চাপুন।</td></tr><?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="card-footer"><button class="btn btn-success" <?= $students === [] ? 'disabled' : '' ?>>Save attendance</button></div>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
