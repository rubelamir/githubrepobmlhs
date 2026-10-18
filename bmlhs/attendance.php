<?php
declare(strict_types=1);

$pageAssets = ['jquery'];
$pageMeta = [
    'title' => 'হাজিরা',
    'description' => 'শ্রেণী, দিন, মাস ও বছর অনুযায়ী হাজিরা।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$classes = schoolClasses($db, true);
$view = (string) ($_GET['view'] ?? 'day');
if (!in_array($view, ['day', 'month', 'year'], true)) {
    $view = 'day';
}

$today = new DateTimeImmutable('today');
$date = (string) ($_GET['date'] ?? $today->format('Y-m-d'));
if (DateTimeImmutable::createFromFormat('Y-m-d', $date) === false) {
    $date = $today->format('Y-m-d');
}
$year = (int) ($_GET['year'] ?? ($view === 'day' ? (int) substr($date, 0, 4) : (int) $today->format('Y')));
if ($year < 2000 || $year > 2100) {
    $year = (int) $today->format('Y');
}
$month = (int) ($_GET['month'] ?? (int) $today->format('n'));
if ($month < 1 || $month > 12) {
    $month = (int) $today->format('n');
}

$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? (string) $year));
$sections = $classId > 0 ? schoolSections($db, $classId, true) : [];

$banglaMonths = [
    1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
    5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
    9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
];
$weekDays = ['শনি', 'রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহস্পতি', 'শুক্র'];
$statusLabels = [
    'present' => 'উপস্থিত',
    'absent' => 'অনুপস্থিত',
    'late' => 'বিলম্ব',
];

$students = [];
$dayMarks = [];
$monthMarks = [];
$monthDays = [];
$yearMonths = [];
$summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'unmarked' => 0];

if ($classId > 0 && tableExists($db, 'students_info')) {
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll FROM students_info s'
        . studentsListJoinSql() . '
            WHERE s.status = 1 AND s.cls = :cls';
    $params = ['cls' => $classId];
    if ($sessionYear !== '') {
        $sql .= ' AND s.session_year = :session_year';
        $params['session_year'] = $sessionYear;
    }
    if ($sectionId > 0) {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = (string) $sectionId;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);
}

$studentIds = array_map(static fn (array $row): int => (int) $row['id'], $students);

if ($studentIds !== [] && tableExists($db, 'student_attendance')) {
    $idList = implode(',', $studentIds);
    if ($view === 'day') {
        $rows = fetchRows(
            $db,
            "SELECT student_id, status, remarks FROM student_attendance
             WHERE attendance_date = :d AND student_id IN ({$idList})",
            ['d' => $date]
        );
        foreach ($rows as $row) {
            $dayMarks[(int) $row['student_id']] = $row;
            $status = (string) $row['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        $summary['unmarked'] = max(0, count($students) - count($dayMarks));
    }

    if ($view === 'month') {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        $rows = fetchRows(
            $db,
            "SELECT student_id, attendance_date, status FROM student_attendance
             WHERE attendance_date BETWEEN :from AND :to AND student_id IN ({$idList})",
            ['from' => $from, 'to' => $to]
        );
        foreach ($rows as $row) {
            $sid = (int) $row['student_id'];
            $status = (string) $row['status'];
            $dayNo = (int) substr((string) $row['attendance_date'], 8, 2);
            $monthMarks[$sid][$status] = ($monthMarks[$sid][$status] ?? 0) + 1;
            $monthDays[$dayNo][$status] = ($monthDays[$dayNo][$status] ?? 0) + 1;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
    }

    if ($view === 'year') {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $rows = fetchRows(
            $db,
            "SELECT MONTH(attendance_date) AS month_no, status, COUNT(*) AS total
             FROM student_attendance
             WHERE attendance_date BETWEEN :from AND :to AND student_id IN ({$idList})
             GROUP BY MONTH(attendance_date), status",
            ['from' => $from, 'to' => $to]
        );
        foreach ($rows as $row) {
            $m = (int) $row['month_no'];
            $status = (string) $row['status'];
            $yearMonths[$m][$status] = (int) $row['total'];
            if (isset($summary[$status])) {
                $summary[$status] += (int) $row['total'];
            }
        }
    }
}

$daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$firstWeekday = (int) date('w', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$saturdayOffset = ($firstWeekday + 1) % 7;

function attendanceQuery(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);

    return 'attendance?' . http_build_query($params);
}
?>
<section class="reg_section sweet-mini-section">
    <div class="bg-mini-overlay d-flex align-items-center">
        <div class="container">
            <h2>হাজিরা / Attendance</h2>
        </div>
    </div>
</section>

<div class="school-attendance school-inner-page">
    <div class="container py-4 py-lg-5">
        <div class="school-attendance-intro mb-4">
            <p class="mb-0">শ্রেণী বেছে নিয়ে দিন, মাস অথবা বছর অনুযায়ী শিক্ষার্থীদের উপস্থিতি দেখুন।</p>
        </div>

        <div class="school-attendance-tabs mb-4" role="tablist">
            <a class="school-attendance-tab<?= $view === 'day' ? ' is-active' : '' ?>" href="<?= h(attendanceQuery(['view' => 'day'])) ?>">দিন</a>
            <a class="school-attendance-tab<?= $view === 'month' ? ' is-active' : '' ?>" href="<?= h(attendanceQuery(['view' => 'month'])) ?>">মাস</a>
            <a class="school-attendance-tab<?= $view === 'year' ? ' is-active' : '' ?>" href="<?= h(attendanceQuery(['view' => 'year'])) ?>">বছর</a>
        </div>

        <form method="get" class="school-attendance-filters card border-0 shadow-sm mb-4">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <?php if ($view === 'day'): ?>
                        <div class="col-md-3">
                            <label class="form-label" for="attDate">দিন</label>
                            <input class="form-control" type="date" id="attDate" name="date" value="<?= h($date) ?>">
                        </div>
                    <?php endif; ?>
                    <?php if ($view === 'month'): ?>
                        <div class="col-md-3">
                            <label class="form-label" for="attMonth">মাস</label>
                            <select class="form-select" id="attMonth" name="month">
                                <?php foreach ($banglaMonths as $num => $label): ?>
                                    <option value="<?= $num ?>" <?= $month === $num ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($view !== 'day'): ?>
                        <div class="col-md-2">
                            <label class="form-label" for="attYear">বছর</label>
                            <select class="form-select" id="attYear" name="year">
                                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 6; $y--): ?>
                                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= h(bnDigits((string) $y)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label class="form-label" for="attSession">সেশন</label>
                        <input class="form-control" id="attSession" name="session_year" value="<?= h($sessionYear) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="cls">শ্রেণী</label>
                        <select class="form-select" id="cls" name="cls" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php echoClassOptions($classes, $classId > 0 ? (string) $classId : ''); ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="sections">শাখা</label>
                        <select class="form-select" id="sections" name="sections">
                            <option value="0">সম্পূর্ণ শ্রেণী</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                                    <?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn school-attendance-submit w-100">দেখুন</button>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($classId < 1): ?>
            <div class="school-attendance-empty">শ্রেণী নির্বাচন করে হাজিরা দেখুন।</div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="school-att-stat is-present">
                        <span>উপস্থিত</span>
                        <strong><?= h(bnDigits((string) $summary['present'])) ?></strong>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="school-att-stat is-absent">
                        <span>অনুপস্থিত</span>
                        <strong><?= h(bnDigits((string) $summary['absent'])) ?></strong>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="school-att-stat is-late">
                        <span>বিলম্ব</span>
                        <strong><?= h(bnDigits((string) $summary['late'])) ?></strong>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="school-att-stat is-muted">
                        <span><?= $view === 'day' ? 'অচিহ্নিত' : 'মোট রেকর্ড' ?></span>
                        <strong><?= h(bnDigits((string) ($view === 'day' ? $summary['unmarked'] : ($summary['present'] + $summary['absent'] + $summary['late'])))) ?></strong>
                    </div>
                </div>
            </div>

            <?php if ($view === 'month'): ?>
                <div class="school-att-calendar mb-4">
                    <h3 class="school-att-heading"><?= h($banglaMonths[$month]) ?> <?= h(bnDigits((string) $year)) ?></h3>
                    <div class="school-att-cal-grid">
                        <?php foreach ($weekDays as $label): ?>
                            <div class="school-att-cal-head"><?= h($label) ?></div>
                        <?php endforeach; ?>
                        <?php for ($i = 0; $i < $saturdayOffset; $i++): ?>
                            <div class="school-att-cal-cell is-empty"></div>
                        <?php endfor; ?>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                            <?php
                                $cellDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $p = (int) ($monthDays[$d]['present'] ?? 0);
                                $a = (int) ($monthDays[$d]['absent'] ?? 0);
                                $l = (int) ($monthDays[$d]['late'] ?? 0);
                                $has = $p + $a + $l > 0;
                            ?>
                            <a class="school-att-cal-cell<?= $has ? ' has-data' : '' ?>" href="<?= h(attendanceQuery(['view' => 'day', 'date' => $cellDate])) ?>">
                                <span class="school-att-cal-num"><?= h(bnDigits((string) $d)) ?></span>
                                <?php if ($has): ?>
                                    <span class="school-att-cal-dots">
                                        <i class="is-present"><?= h(bnDigits((string) $p)) ?></i>
                                        <i class="is-absent"><?= h(bnDigits((string) $a)) ?></i>
                                        <?php if ($l > 0): ?><i class="is-late"><?= h(bnDigits((string) $l)) ?></i><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">সবুজ = উপস্থিত, লাল = অনুপস্থিত। দিনে ক্লিক করলে সেই দিনের তালিকা খুলবে।</p>
                </div>
            <?php endif; ?>

            <?php if ($view === 'year'): ?>
                <div class="row g-3 mb-4">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <?php
                            $p = (int) ($yearMonths[$m]['present'] ?? 0);
                            $a = (int) ($yearMonths[$m]['absent'] ?? 0);
                            $l = (int) ($yearMonths[$m]['late'] ?? 0);
                            $total = $p + $a + $l;
                        ?>
                        <div class="col-6 col-md-4 col-xl-3">
                            <a class="school-att-month-card" href="<?= h(attendanceQuery(['view' => 'month', 'month' => $m, 'year' => $year])) ?>">
                                <strong><?= h($banglaMonths[$m]) ?></strong>
                                <?php if ($total === 0): ?>
                                    <span class="text-secondary">কোনো হাজিরা নেই</span>
                                <?php else: ?>
                                    <span>উপস্থিত <?= h(bnDigits((string) $p)) ?></span>
                                    <span>অনুপস্থিত <?= h(bnDigits((string) $a)) ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <?php if ($view !== 'year'): ?>
            <div class="card border-0 shadow-sm school-att-table-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table school-att-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>রোল</th>
                                    <th>নাম</th>
                                    <?php if ($view === 'day'): ?>
                                        <th>অবস্থা</th>
                                        <th>মন্তব্য</th>
                                    <?php else: ?>
                                        <th>উপস্থিত</th>
                                        <th>অনুপস্থিত</th>
                                        <th>বিলম্ব</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($students === []): ?>
                                    <tr><td colspan="4" class="text-center text-secondary py-4">এই শ্রেণীতে কোনো শিক্ষার্থী পাওয়া যায়নি।</td></tr>
                                <?php endif; ?>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                        $id = (int) $student['id'];
                                        $mark = $dayMarks[$id] ?? null;
                                        $status = is_array($mark) ? (string) $mark['status'] : '';
                                    ?>
                                    <tr>
                                        <td><?= h(bnDigits((string) $student['cls_roll'])) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= h((string) $student['name_ban']) ?></div>
                                            <?php if ((string) ($student['name_eng'] ?? '') !== ''): ?>
                                                <div class="small text-secondary"><?= h((string) $student['name_eng']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($view === 'day'): ?>
                                            <td>
                                                <?php if ($status !== '' && isset($statusLabels[$status])): ?>
                                                    <span class="school-att-badge is-<?= h($status) ?>"><?= h($statusLabels[$status]) ?></span>
                                                <?php else: ?>
                                                    <span class="school-att-badge is-muted">চিহ্নিত নয়</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary"><?= h((string) ($mark['remarks'] ?? '')) ?></td>
                                        <?php else: ?>
                                            <td><?= h(bnDigits((string) (int) ($monthMarks[$id]['present'] ?? 0))) ?></td>
                                            <td><?= h(bnDigits((string) (int) ($monthMarks[$id]['absent'] ?? 0))) ?></td>
                                            <td><?= h(bnDigits((string) (int) ($monthMarks[$id]['late'] ?? 0))) ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php $keepDocumentOpen = true; include 'footer.php'; ?>
<script>
$(document).ready(function () {
  function loadSections(classId) {
    $.ajax({
      url: 'selectmenu.php',
      type: 'POST',
      data: { type: 'sections', id: classId },
      success: function (data) {
        var extra = '';
        $(data).filter('option').each(function () {
          if ($(this).attr('value')) {
            extra += this.outerHTML;
          }
        });
        $('#sections').html('<option value="0">সম্পূর্ণ শ্রেণী</option>' + extra);
      }
    });
  }
  $('#cls').on('change', function () {
    var cls = $(this).val();
    if (!cls) {
      $('#sections').html('<option value="0">সম্পূর্ণ শ্রেণী</option>');
      return;
    }
    loadSections(cls);
  });
});
</script>
</body>
</html>
