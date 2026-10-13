<?php
declare(strict_types=1);

$pageAssets = [];
$pageMeta = [
    'title' => 'একাডেমিক ক্যালেন্ডার',
    'description' => 'ছুটি, পরীক্ষা ও অনুষ্ঠানের মাসভিত্তিক শিক্ষাপঞ্জি।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$today = new DateTimeImmutable('today');
$year = (int) ($_GET['year'] ?? $today->format('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) $today->format('Y');
}
$month = (int) ($_GET['month'] ?? $today->format('n'));
if ($month < 1 || $month > 12) {
    $month = (int) $today->format('n');
}
$months = schoolBanglaMonths();
$weekDays = ['শনি', 'রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহস্পতি', 'শুক্র'];
$items = publicCalendarItems($db, $year, $month);
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$byDay = [];
foreach ($items as $item) {
    $rangeStart = max((string) $item['event_date'], $monthStart);
    $rangeEnd = min(schoolCalendarItemEndDate($item), $monthEnd);
    if ($rangeStart === '' || $rangeEnd === '' || $rangeStart > $rangeEnd) {
        continue;
    }
    $cursor = new DateTimeImmutable($rangeStart);
    $last = new DateTimeImmutable($rangeEnd);
    for ($day = $cursor; $day <= $last; $day = $day->modify('+1 day')) {
        $byDay[(int) $day->format('j')][] = $item;
    }
}
$daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$workSummary = schoolCalendarMonthWorkSummary($year, $month, $byDay);
$firstWeekday = (int) date('w', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$saturdayOffset = ($firstWeekday + 1) % 7;
$filterYears = publicCalendarYears($db, (int) $today->format('Y'));
if (!in_array($year, $filterYears, true)) {
    $filterYears[] = $year;
    sort($filterYears);
}

$pdfRow = null;
if (tableExists($db, 'academic_calender')) {
    $pdfRows = fetchRows(
        $db,
        'SELECT * FROM academic_calender WHERE academic_calender_status = 1 ORDER BY academic_calender_creation_time DESC LIMIT 1'
    );
    $pdfRow = $pdfRows[0] ?? null;
}
?>
<div class="school-inner-page">
    <section class="reg_section sweet-mini-section">
        <div class="bg-mini-overlay d-flex align-items-center">
            <div class="container"><h2>একাডেমিক ক্যালেন্ডার</h2></div>
        </div>
    </section>
    <div class="container school-inner-wrap py-4 py-lg-5">
        <p class="school-inner-lead">মাস বেছে ছুটি, পরীক্ষা ও অনুষ্ঠানের তারিখ দেখুন।</p>
        <?php if (count($filterYears) > 1): ?>
            <div class="school-filter-chips school-cal-filters" aria-label="বছর বাছুন">
                <?php foreach ($filterYears as $filterYear): ?>
                    <a class="school-chip<?= $year === $filterYear ? ' is-active' : '' ?>" href="academic_calender?year=<?= (int) $filterYear ?>&month=<?= (int) $month ?>"><?= h(bnDigits((string) $filterYear)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="school-filter-chips school-cal-filters" aria-label="মাস বাছুন">
            <?php foreach ($months as $monthNumber => $monthLabel): ?>
                <a class="school-chip<?= $month === $monthNumber ? ' is-active' : '' ?>" href="academic_calender?year=<?= (int) $year ?>&month=<?= (int) $monthNumber ?>"><?= h($monthLabel) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="school-cal-grid">
            <?php foreach ($weekDays as $index => $label): ?>
                <div class="school-cal-head<?= ($index === 0 || $index === 6) ? ' is-weekend' : '' ?>"><?= h($label) ?></div>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $saturdayOffset; $i++): ?>
                <div class="school-cal-cell is-empty"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $rawDayItems = $byDay[$d] ?? [];
                $isToday = $year === (int) $today->format('Y') && $month === (int) $today->format('n') && $d === (int) $today->format('j');
                $phpWeekday = (int) date('w', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $d)));
                $isWeekend = $phpWeekday === 5 || $phpWeekday === 6;
                $dayItems = schoolCalendarVisibleDayItems($rawDayItems, $isWeekend);
                $types = [];
                foreach ($dayItems as $item) {
                    $type = (string) ($item['event_type'] ?? '');
                    if ($type !== '') {
                        $types[$type] = true;
                    }
                }
                if ($isWeekend) {
                    $types['holiday'] = true;
                }
                $fill = schoolCalendarFillClass($types);
            ?>
                <div class="school-cal-cell<?= $dayItems !== [] || $isWeekend ? ' has-data' : '' ?><?= $isToday ? ' is-today' : '' ?><?= $fill ?>">
                    <span class="school-cal-num"><?= h(bnDigits((string) $d)) ?></span>
                    <?php foreach ($dayItems as $item): ?>
                        <span class="school-cal-tag is-<?= h((string) $item['event_type']) ?>"><?= h((string) $item['title']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
        <div class="school-cal-legend">
            <span class="is-holiday">ছুটি</span>
            <span class="is-exam">পরীক্ষা</span>
            <span class="is-event">অনুষ্ঠান</span>
        </div>
        <hr class="school-cal-divider">
        <div class="school-cal-workdays-wrap">
            <h3 class="school-inner-sub">মোট কার্যদিবস</h3>
            <div class="school-cal-workdays">
                <strong><?= h(bnDigits((string) $workSummary['working_days'])) ?></strong>
                <span>দিন</span>
                <p>এই মাসে মোট <?= h(bnDigits((string) $workSummary['days_in_month'])) ?> দিন। ছুটির <?= h(bnDigits((string) $workSummary['off_days'])) ?> দিন বাদ দিয়ে কার্যদিবস <?= h(bnDigits((string) $workSummary['working_days'])) ?> দিন।</p>
            </div>
        </div>

        <?php
        $pdfFile = trim((string) ($pdfRow['academic_calender_file'] ?? ''));
        if ($pdfFile !== '' && strtolower(pathinfo($pdfFile, PATHINFO_EXTENSION)) === 'pdf'):
        ?>
            <h3 class="school-inner-sub mt-4">পিডিএফ ক্যালেন্ডার</h3>
            <div class="school-pdf-frame">
                <iframe src="academic_calender_file/<?= h($pdfFile) ?>" title="একাডেমিক ক্যালেন্ডার"></iframe>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
