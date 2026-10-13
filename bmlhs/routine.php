<?php
declare(strict_types=1);

$pageAssets = ['jquery'];
$pageMeta = [
    'title' => 'শ্রেণী রুটিন',
    'description' => 'শ্রেণী ও শিক্ষকভিত্তিক সাপ্তাহিক ক্লাস রুটিন।',
];
include 'header.php';
include 'navbar&marqueetag.php';

$db = publicDatabase();
$school = currentSchool();
$classes = schoolClasses($db, true);
$days = weekDays();
$periodMeta = schoolPeriodMeta($db);
$routineSettings = routineSettings($db);
$teachers = schoolTeachers($db);
$teacherMap = teacherNameMap($db);
$today = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('w');
$teacherOptions = [];
foreach ($teachers as $teacher) {
    $mobile = trim((string) ($teacher['teacher_mobile'] ?? ''));
    $name = trim((string) ($teacher['teacher_name'] ?? ''));
    $key = teacherMobileKey($mobile);
    if ($key !== '' && $name !== '' && !isset($teacherOptions[$key])) {
        $teacherOptions[$key] = [
            'mobile' => $mobile,
            'name' => $name,
        ];
    }
}

$view = (string) ($_GET['view'] ?? 'class');
if (!in_array($view, ['class', 'teacher'], true)) {
    $view = 'class';
}

$classFilterSubmitted = isset($_GET['cls']) && trim((string) $_GET['cls']) !== '';
$classId = $classFilterSubmitted ? (int) $_GET['cls'] : 0;
$sectionId = (int) ($_GET['sections'] ?? 0);
$teacherMobile = trim((string) ($_GET['teacher'] ?? ''));
$selectedTeacher = null;
foreach ($teacherOptions as $teacherOption) {
    if ($teacherMobile !== '' && teacherMobilesMatch($teacherMobile, $teacherOption['mobile'])) {
        $selectedTeacher = $teacherOption;
        $teacherMobile = $teacherOption['mobile'];
        break;
    }
}
if ($selectedTeacher === null) {
    $teacherMobile = '';
}
$sections = $classId > 0 ? schoolSections($db, $classId, true) : [];
if ($sectionId === 0 && $sections !== []) {
    $sectionId = (int) $sections[0]['id'];
}

$isPublished = (int) ($routineSettings['is_published'] ?? 1) === 1;
$weekGrid = ($isPublished && $view === 'class' && $classId > 0) ? classRoutineGrid($db, $classId, $sectionId) : [];
$teacherWeek = [];
if ($isPublished && $view === 'teacher' && $selectedTeacher !== null) {
    foreach (fetchRows(
        $db,
        'SELECT * FROM class_routine
         WHERE subject_name <> \'\'
         ORDER BY day_of_week ASC, period_no ASC, class_id ASC'
    ) as $row) {
        if (!teacherMobilesMatch((string) ($row['teacher_mobile'] ?? ''), $teacherMobile)) {
            continue;
        }
        $teacherWeek[(int) $row['day_of_week']][(int) $row['period_no']][] = $row;
    }
}
$classLabelText = $classId > 0 ? classLabel($db, $classId) : '';
$sectionLabelText = $sectionId > 0 ? sectionLabel($db, $sectionId) : '';
$sessionLabel = trim((string) ($routineSettings['session_label'] ?? ''));
$hasClassData = false;
foreach ($weekGrid as $daySlots) {
    foreach ($daySlots as $slotRows) {
        foreach ($slotRows as $slot) {
            if (trim((string) ($slot['subject_name'] ?? '')) !== '') {
                $hasClassData = true;
                break 3;
            }
        }
    }
}

function routinePublicQuery(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    unset($params['day']);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    return 'routine?' . http_build_query($params);
}
?>
<style>
@media print {
  @page { size: A4 landscape; margin: 8mm; }
}
</style>
<section class="reg_section sweet-mini-section">
    <div class="bg-mini-overlay d-flex align-items-center">
        <div class="container">
            <h2>শ্রেণী রুটিন</h2>
        </div>
    </div>
</section>

<div class="school-routine school-inner-page">
    <div class="container py-4 py-lg-5">
        <div class="school-routine-intro mb-4">
            <p class="mb-0">শ্রেণী অথবা শিক্ষক বেছে নিয়ে সাপ্তাহিক ক্লাস রুটিন দেখুন ও প্রিন্ট করুন।</p>
        </div>

        <?php if (!$isPublished): ?>
            <div class="school-routine-empty">রুটিন এখন ওয়েবসাইটে প্রকাশিত নয়। অনুগ্রহ করে পরে আবার দেখুন।</div>
        <?php else: ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 no-print">
                <nav class="school-routine-tabs" aria-label="রুটিন ধরন">
                    <a class="school-routine-tab<?= $view === 'class' ? ' is-active' : '' ?>" href="<?= h(routinePublicQuery(['view' => 'class'])) ?>">শ্রেণী রুটিন</a>
                    <a class="school-routine-tab<?= $view === 'teacher' ? ' is-active' : '' ?>" href="<?= h(routinePublicQuery(['view' => 'teacher'])) ?>">শিক্ষক রুটিন</a>
                </nav>
                <div class="d-flex gap-2">
                    <?php if ($view === 'class' && $classFilterSubmitted && $hasClassData): ?>
                        <a class="btn school-routine-print" href="<?= h('routine-pdf.php?' . http_build_query([
                            'cls' => $classId,
                            'sections' => $sectionId,
                        ])) ?>">
                            <i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i> PDF ডাউনলোড
                        </a>
                    <?php elseif ($view === 'teacher' && $selectedTeacher !== null && $teacherWeek !== []): ?>
                        <a class="btn school-routine-print" href="<?= h('teacher-routine-pdf.php?' . http_build_query([
                            'teacher' => $teacherMobile,
                        ])) ?>">
                            <i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i> PDF ডাউনলোড
                        </a>
                    <?php endif; ?>
                    <button class="btn school-routine-print" type="button" onclick="window.print()">
                        <i class="fa-solid fa-print me-1" aria-hidden="true"></i> প্রিন্ট
                    </button>
                </div>
            </div>

            <?php if ($view === 'class' && $classes === []): ?>
                <div class="school-routine-empty">অ্যাডমিন প্যানেল থেকে শ্রেণী যোগ করার পর এখানে রুটিন দেখা যাবে।</div>
            <?php elseif ($view === 'class'): ?>
                <form method="get" class="school-routine-filters card border-0 shadow-sm mb-4 no-print">
                    <input type="hidden" name="view" value="class">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label" for="cls">শ্রেণী</label>
                                <select class="form-select" id="cls" name="cls" required>
                                    <option value="" <?= $classId === 0 ? 'selected' : '' ?> disabled>শ্রেণী নির্বাচন করুন</option>
                                    <?php echoClassOptions($classes, $classId > 0 ? (string) $classId : ''); ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="sections">শাখা / বিভাগ</label>
                                <select class="form-select" id="sections" name="sections">
                                    <?php if ($sections === []): ?>
                                        <option value="0" selected>সম্পূর্ণ শ্রেণী</option>
                                    <?php endif; ?>
                                    <?php foreach ($sections as $section): ?>
                                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                                            <?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn school-routine-submit w-100">দেখুন</button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <form method="get" class="school-routine-filters card border-0 shadow-sm mb-4 no-print">
                    <input type="hidden" name="view" value="teacher">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-10">
                                <label class="form-label" for="teacher">শিক্ষকের নাম</label>
                                <select class="form-select" id="teacher" name="teacher" required>
                                    <option value="" <?= $selectedTeacher === null ? 'selected' : '' ?> disabled>শিক্ষক নির্বাচিত করুন</option>
                                    <?php foreach ($teacherOptions as $teacherOption): ?>
                                        <option value="<?= h($teacherOption['mobile']) ?>" <?= $selectedTeacher !== null && teacherMobilesMatch($selectedTeacher['mobile'], $teacherOption['mobile']) ? 'selected' : '' ?>>
                                            <?= h($teacherOption['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn school-routine-submit w-100">দেখুন</button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($view === 'teacher' && $selectedTeacher === null): ?>
                <div class="school-routine-empty">অনুগ্রহ করে একজন শিক্ষক নির্বাচন করে "দেখুন" বাটনে ক্লিক করুন।</div>
            <?php elseif ($view === 'class' && !$classFilterSubmitted): ?>
                <div class="school-routine-empty">অনুগ্রহ করে শ্রেণী নির্বাচন করে "দেখুন" বাটনে ক্লিক করুন।</div>
            <?php elseif (!($view === 'class' && $classes === [])): ?>
            <article class="school-routine-sheet school-landscape-routine-sheet<?= $view === 'teacher' ? ' school-teacher-routine-sheet' : '' ?>" id="printDiv">
                <header class="school-routine-head">
                    <?php if (schoolLogoUrl($school) !== ''): ?>
                        <img src="<?= h(schoolLogoUrl($school)) ?>" alt="">
                    <?php endif; ?>
                    <div>
                        <h1><?= h((string) $school['school_name_bn']) ?></h1>
                        <p><?= h((string) $school['address']) ?></p>
                        <?php if ($view === 'class'): ?>
                            <h2>শ্রেণী রুটিন<?= $classLabelText !== '' ? ' — ' . h($classLabelText) : '' ?><?= $sectionLabelText !== '' ? ' ' . h($sectionLabelText) : '' ?></h2>
                        <?php else: ?>
                            <h2>সাপ্তাহিক শিক্ষক রুটিন<?= $selectedTeacher !== null ? ' — ' . h($selectedTeacher['name']) : '' ?></h2>
                        <?php endif; ?>
                        <?php if ($sessionLabel !== ''): ?>
                            <p>সেশন <?= h($sessionLabel) ?></p>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="school-routine-bells">
                    <?php if (trim((string) $routineSettings['assembly_time']) !== ''): ?>
                        <span><?= h((string) $routineSettings['assembly_label']) ?>: <?= h((string) $routineSettings['assembly_time']) ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) $routineSettings['break_time']) !== ''): ?>
                        <span><?= h((string) $routineSettings['break_label']) ?>: <?= h((string) $routineSettings['break_time']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($days[$today])): ?>
                        <span class="is-today">আজ <?= h(weekDayBangla($today)) ?></span>
                    <?php elseif ($view === 'class'): ?>
                        <span>আজ সাপ্তাহিক ছুটি</span>
                    <?php endif; ?>
                </div>

                <?php if ($view === 'class' && !$hasClassData): ?>
                    <div class="school-routine-empty">এই শ্রেণী/শাখার রুটিন এখনো প্রস্তুত হয়নি।</div>
                <?php elseif ($view === 'teacher' && $teacherWeek === []): ?>
                    <div class="school-routine-empty"><?= $selectedTeacher !== null ? 'এই শিক্ষকের কোনো ক্লাস নির্ধারিত নেই।' : 'শিক্ষক রুটিন এখনো প্রস্তুত হয়নি।' ?></div>
                <?php elseif ($view === 'class'): ?>
                    <div class="table-responsive">
                        <table class="table school-routine-table mb-0">
                            <thead>
                                <tr>
                                    <th>বার</th>
                                    <?php foreach ($periodMeta as $meta): ?>
                                        <th>
                                            <?= h($meta['label']) ?>
                                            <small><?= h($meta['time']) ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $key => $label): ?>
                                    <tr class="<?= (int) $key === $today ? 'is-today' : '' ?>">
                                        <th><?= h(weekDayBangla((int) $key)) ?></th>
                                        <?php foreach ($periodMeta as $periodNo => $meta): ?>
                                            <?php $cellRows = $weekGrid[$key][$periodNo] ?? []; ?>
                                            <td>
                                                <?php if ($cellRows === []): ?>
                                                    <div class="school-routine-cell is-empty">—</div>
                                                <?php else: ?>
                                                    <div class="school-routine-cell-group<?= count($cellRows) > 1 ? ' has-multiple' : '' ?>">
                                                    <?php foreach ($cellRows as $cell): ?>
                                                        <?php
                                                            $subject = trim((string) ($cell['subject_name'] ?? ''));
                                                            $tone = routineSubjectTone($subject);
                                                            $teacherName = teacherDisplayName($teacherMap, (string) ($cell['teacher_mobile'] ?? ''));
                                                            $room = trim((string) ($cell['room'] ?? ''));
                                                        ?>
                                                        <div class="school-routine-cell" style="background:<?= h($tone['bg']) ?>;color:<?= h($tone['ink']) ?>">
                                                            <strong><?= h($subject) ?></strong>
                                                            <?php if ($teacherName !== ''): ?><em><?= h($teacherName) ?></em><?php endif; ?>
                                                            <?php if ($room !== ''): ?><span><?= h($room) ?></span><?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table school-routine-table mb-0">
                            <thead>
                                <tr>
                                    <th>বার</th>
                                    <?php foreach ($periodMeta as $meta): ?>
                                        <th>
                                            <?= h($meta['label']) ?>
                                            <small><?= h($meta['time']) ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_keys($days) as $dayNo): ?>
                                    <tr class="<?= (int) $dayNo === $today ? 'is-today' : '' ?>">
                                        <th><?= h(weekDayBangla((int) $dayNo)) ?></th>
                                        <?php foreach ($periodMeta as $periodNo => $meta): ?>
                                            <?php $cells = groupTeacherRoutineCells($db, $teacherWeek[$dayNo][$periodNo] ?? []); ?>
                                            <td>
                                                <?php if ($cells === []): ?>
                                                    <div class="school-routine-cell is-empty">—</div>
                                                <?php else: ?>
                                                    <?php foreach ($cells as $cell): ?>
                                                        <?php $tone = routineSubjectTone((string) $cell['subject_name']); ?>
                                                        <div class="school-routine-cell" style="background:<?= h($tone['bg']) ?>;color:<?= h($tone['ink']) ?>">
                                                            <strong><?= h((string) $cell['subject_name']) ?></strong><br>
                                                            <?php if ($selectedTeacher === null): ?>
                                                                <em><?= h(teacherDisplayName($teacherMap, (string) ($cell['teacher_mobile'] ?? ''))) ?></em>
                                                            <?php endif; ?>
                                                            <em><?= h(implode(', ', $cell['class_labels'])) ?></em>
                                                            <?php if ($cell['room'] !== ''): ?>
                                                                <span><?= h($cell['room']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (trim((string) ($routineSettings['footer_note'] ?? '')) !== ''): ?>
                    <p class="school-routine-note"><?= h((string) $routineSettings['footer_note']) ?></p>
                <?php endif; ?>
            </article>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
<?php if ($isPublished && $view === 'class'): ?>
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
        $('#sections').html(extra || '<option value="0">সম্পূর্ণ শ্রেণী</option>');
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
<?php endif; ?>
