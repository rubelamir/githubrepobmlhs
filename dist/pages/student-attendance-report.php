<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Student Attendance Report | School Admin';
$teacher = getAuthenticatedTeacher();
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);
$mode = in_array($_GET['mode'] ?? '', ['range', 'month', 'year'], true) ? (string) $_GET['mode'] : 'month';
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
$year = preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? '')) ? (string) $_GET['year'] : date('Y');
$validDate = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = $validDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01');
$to = $validDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d');

$classes = schoolClasses($db);
$sections = $classId > 0 ? schoolSections($db, $classId) : [];

$students = [];
if ($classId > 0) {
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll FROM students_info s'
        . studentsListJoinSql() . ' WHERE s.status = 1 AND s.cls = :cls AND s.session_year = :sy';
    $params = ['cls' => $classId, 'sy' => $sessionYear];
    if ($sectionId > 0) {
        $sql .= ' AND s.sections = :sec';
        $params['sec'] = $sectionId;
    }
    $students = fetchRows($db, $sql . ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 500', $params);
}

$student = null;
foreach ($students as $row) {
    if ((int) $row['id'] === $studentId) {
        $student = $row;
    }
}

if ($mode === 'month') {
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
} elseif ($mode === 'year') {
    $start = $year . '-01-01';
    $end = $year . '-12-31';
} else {
    $start = min($from, $to);
    $end = max($from, $to);
}

$records = [];
$summary = ['present' => 0, 'absent' => 0];
$monthly = [];
$classDays = 0;
if ($student !== null) {
    $records = fetchRows(
        $db,
        'SELECT attendance_date, status, remarks FROM student_attendance
         WHERE student_id = :id AND attendance_date BETWEEN :a AND :b AND status IN (\'present\', \'absent\')
         ORDER BY attendance_date',
        ['id' => $studentId, 'a' => $start, 'b' => $end]
    );
    foreach ($records as $r) {
        $summary[$r['status']]++;
        $m = substr((string) $r['attendance_date'], 0, 7);
        $monthly[$m] ??= ['present' => 0, 'absent' => 0];
        $monthly[$m][$r['status']]++;
    }
}
$total = $summary['present'] + $summary['absent'];
$rate = $total > 0 ? round($summary['present'] * 100 / $total, 1) : 0;
$studentName = $student !== null ? (string) ($student['name_ban'] ?: $student['name_eng']) : '';

$pageStyles = <<<'CSS'
.sar-stat { text-align:center; padding:.75rem; border-radius:.75rem; background:#f1f5f9; }
.sar-stat b { display:block; font-size:1.6rem; }
@media print { .app-sidebar, .app-header, .no-print, .app-footer { display:none !important; } .app-main { margin:0 !important; } }
CSS;
$pageScripts = [<<<'JS'
(function () {
  var f = document.getElementById('sarForm');
  ['session_year','cls','sections'].forEach(function (n) {
    var el = f.elements[n];
    if (el) el.addEventListener('change', function () {
      if (n === 'cls') { f.elements['sections'].value = ''; }
      f.elements['student_id'].value = '';
      f.submit();
    });
  });
  function toggle() {
    var m = f.elements['mode'].value;
    document.querySelectorAll('[data-mode]').forEach(function (e) { e.style.display = e.dataset.mode === m ? '' : 'none'; });
  }
  f.elements['mode'].addEventListener('change', toggle); toggle();
})();
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid">
          <h3 class="mb-0">ছাত্রছাত্রীর হাজিরা রিপোর্ট</h3>
        </div></div>
        <div class="app-content"><div class="container-fluid">
          <div class="card mb-3 no-print"><div class="card-body">
            <form id="sarForm" method="get" class="row g-2">
              <div class="col-6 col-md-2"><label class="form-label">Session</label>
                <input class="form-control" name="session_year" value="<?= h($sessionYear) ?>"></div>
              <div class="col-6 col-md-3"><label class="form-label">Class</label>
                <select class="form-select" name="cls"><option value="">-- Select --</option>
                  <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $classId ? 'selected' : '' ?>><?= h((string) ($c['class_name_ban'] ?: $c['class_name'])) ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-6 col-md-3"><label class="form-label">Section</label>
                <select class="form-select" name="sections"><option value="">All</option>
                  <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $sectionId ? 'selected' : '' ?>><?= h((string) ($s['section_name_ban'] ?: $s['section_name'])) ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-6 col-md-4"><label class="form-label">Student</label>
                <select class="form-select" name="student_id"><option value="">-- Select --</option>
                  <?php foreach ($students as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $studentId ? 'selected' : '' ?>><?= h((string) $s['cls_roll']) ?> - <?= h((string) ($s['name_ban'] ?: $s['name_eng'])) ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-6 col-md-2"><label class="form-label">Report type</label>
                <select class="form-select" name="mode">
                  <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Date range</option>
                  <option value="month" <?= $mode === 'month' ? 'selected' : '' ?>>Monthly</option>
                  <option value="year" <?= $mode === 'year' ? 'selected' : '' ?>>Yearly</option>
                </select></div>
              <div class="col-6 col-md-3" data-mode="range"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
              <div class="col-6 col-md-3" data-mode="range"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
              <div class="col-6 col-md-3" data-mode="month"><label class="form-label">Month</label><input type="month" class="form-control" name="month" value="<?= h($month) ?>"></div>
              <div class="col-6 col-md-3" data-mode="year"><label class="form-label">Year</label><input type="number" min="2000" max="2100" class="form-control" name="year" value="<?= h($year) ?>"></div>
              <div class="col-12 d-flex gap-2 align-items-end">
                <button class="btn btn-primary">রিপোর্ট দেখুন</button>
                <?php if ($student !== null): ?><button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button><?php endif; ?>
              </div>
            </form>
          </div></div>

          <?php if ($student === null): ?>
            <div class="alert alert-info">Session, Class (ও Section) নির্বাচন করে একজন ছাত্র/ছাত্রী বেছে নিন।</div>
          <?php else: ?>
            <div class="card"><div class="card-body">
              <h5><?= h($studentName) ?> <small class="text-secondary">(রোল <?= h((string) $student['cls_roll']) ?>)</small></h5>
              <p class="text-secondary"><?= h(date('d M Y', strtotime($start))) ?> — <?= h(date('d M Y', strtotime($end))) ?></p>
              <div class="row g-2 mb-3">
                <div class="col-6 col-md-3"><div class="sar-stat"><b><?= $total ?></b>মোট রেকর্ড</div></div>
                <div class="col-6 col-md-3"><div class="sar-stat text-success"><b><?= $summary['present'] ?></b>উপস্থিত</div></div>
                <div class="col-6 col-md-3"><div class="sar-stat text-danger"><b><?= $summary['absent'] ?></b>অনুপস্থিত</div></div>
                <div class="col-6 col-md-3"><div class="sar-stat"><b><?= h((string) $rate) ?>%</b>উপস্থিতির হার</div></div>
              </div>
              <?php if (count($monthly) > 1): ?>
              <h6>মাসভিত্তিক সারাংশ</h6>
              <div class="table-responsive mb-3"><table class="table table-sm table-bordered">
                <thead><tr><th>মাস</th><th>উপস্থিত</th><th>অনুপস্থিত</th><th>হার</th></tr></thead><tbody>
                <?php foreach ($monthly as $m => $v): $t = $v['present'] + $v['absent']; ?>
                  <tr><td><?= h(date('F Y', strtotime($m . '-01'))) ?></td><td><?= $v['present'] ?></td><td><?= $v['absent'] ?></td><td><?= $t > 0 ? round($v['present'] * 100 / $t, 1) : 0 ?>%</td></tr>
                <?php endforeach; ?></tbody></table></div>
              <?php endif; ?>
              <h6>দৈনিক হাজিরা</h6>
              <div class="table-responsive"><table class="table table-sm table-striped">
                <thead><tr><th>তারিখ</th><th>বার</th><th>অবস্থা</th><th>মন্তব্য</th></tr></thead><tbody>
                <?php foreach ($records as $r): ?>
                  <tr><td><?= h(date('d M Y', strtotime((string) $r['attendance_date']))) ?></td><td><?= h(date('l', strtotime((string) $r['attendance_date']))) ?></td>
                    <td><?= $r['status'] === 'present' ? '<span class="badge bg-success">উপস্থিত</span>' : '<span class="badge bg-danger">অনুপস্থিত</span>' ?></td>
                    <td><?= h((string) ($r['remarks'] ?? '')) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($records === []): ?><tr><td colspan="4" class="text-center text-secondary">এই সময়ে কোনো হাজিরা রেকর্ড নেই।</td></tr><?php endif; ?>
                </tbody></table></div>
            </div></div>
          <?php endif; ?>
        </div></div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
