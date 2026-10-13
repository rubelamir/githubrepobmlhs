<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'শ্রেণী রুটিন | School Admin';

$classes = schoolClasses($db);
$teachers = schoolTeachers($db);
$teacherMap = teacherNameMap($db);
$days = weekDays();
$periodMeta = schoolPeriodMeta($db);
$periods = [];
foreach ($periodMeta as $no => $meta) {
    $periods[$no] = $meta['time'];
}
$routineSettings = routineSettings($db);
$view = (string) ($_GET['view'] ?? 'class');
if (!in_array($view, ['class', 'teacher'], true)) {
    $view = 'class';
}

$classId = (int) ($_GET['cls'] ?? ($classes[0]['id'] ?? 0));
$sectionId = (int) ($_GET['sections'] ?? 0);
$day = (int) ($_GET['day'] ?? (int) date('w'));
if (!isset($days[$day])) {
    $day = 0;
}
$sections = $classId > 0 ? schoolSections($db, $classId) : [];
if ($sectionId === 0 && $sections !== []) {
    $sectionId = (int) $sections[0]['id'];
}
$subjectChoices = $classId > 0 ? classSubjects($db, $classId, $sectionId) : [];
$assignedTeachers = subjectTeacherMap($subjectChoices);

function routineAdminQuery(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    return 'routine.php?' . http_build_query($params);
}

function routineRedirect(array $query, string $type, string $message): void
{
    redirectWithMessage(routineAdminQuery($query), $type, $message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save_day');
    $classId = (int) ($_POST['cls'] ?? $classId);
    $sectionId = (int) ($_POST['sections'] ?? $sectionId);
    $day = (int) ($_POST['day'] ?? $day);
    $view = (string) ($_POST['view'] ?? $view);
    $baseQuery = ['view' => $view, 'cls' => $classId, 'sections' => $sectionId, 'day' => $day];
    try {
        if ($action === 'save_settings') {
            saveRoutineSettings($db, $_POST);
            routineRedirect($baseQuery, 'success', 'রুটিন সেটিংস সংরক্ষিত হয়েছে। ওয়েবসাইটে এখনই প্রতিফলিত হবে।');
        }
        if ($action === 'save_day') {
            $conflicts = saveClassRoutineDay(
                $db,
                $classId,
                $sectionId,
                $day,
                $periods,
                $_POST['subject'] ?? [],
                $_POST['teacher'] ?? [],
                $_POST['room'] ?? []
            );
            if ($conflicts !== []) {
                $parts = [];
                foreach ($conflicts as $clash) {
                    $parts[] = periodDisplayLabel((int) $clash['period']) . ' — '
                        . teacherDisplayName($teacherMap, (string) $clash['teacher'])
                        . ' ইতোমধ্যে ' . classLabel($db, (int) $clash['class_id'])
                        . ' ' . sectionLabel($db, (int) $clash['section_id'])
                        . ' (' . $clash['subject'] . ') এ আছেন';
                }
                routineRedirect($baseQuery, 'warning', 'রুটিন সংরক্ষিত, তবে দ্বন্দ্ব আছে: ' . implode('; ', $parts));
            }
            routineRedirect($baseQuery, 'success', weekDayBangla($day) . ' এর রুটিন সংরক্ষিত হয়েছে।');
        }
        if ($action === 'clear_day') {
            clearClassRoutineDay($db, $classId, $sectionId, $day);
            routineRedirect($baseQuery, 'success', weekDayBangla($day) . ' এর রুটিন মুছে ফেলা হয়েছে।');
        }
        if ($action === 'copy_day') {
            $toDay = (int) ($_POST['to_day'] ?? -1);
            $copied = copyClassRoutineDay($db, $classId, $sectionId, $day, $toDay);
            routineRedirect(
                ['view' => 'class', 'cls' => $classId, 'sections' => $sectionId, 'day' => $toDay],
                'success',
                weekDayBangla($day) . ' থেকে ' . weekDayBangla($toDay) . ' এ ' . bnDigits((string) $copied) . 'টি রুটিন এন্ট্রি কপি হয়েছে।'
            );
        }
        if ($action === 'copy_week') {
            $toClass = (int) ($_POST['to_class'] ?? 0);
            $toSection = (int) ($_POST['to_section'] ?? 0);
            $copied = copyClassRoutineWeek($db, $classId, $sectionId, $toClass, $toSection);
            routineRedirect(
                ['view' => 'class', 'cls' => $toClass, 'sections' => $toSection, 'day' => $day],
                'success',
                'পুরো সপ্তাহের ' . bnDigits((string) $copied) . 'টি রুটিন এন্ট্রি নতুন শাখায় কপি হয়েছে।'
            );
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $exception) {
        routineRedirect($baseQuery, 'danger', userFacingError($exception));
    }
}

$weekGrid = $classId > 0 ? classRoutineGrid($db, $classId, $sectionId) : [];
$slots = $weekGrid[$day] ?? [];
$stats = $classId > 0 ? classRoutineStats($db, $classId, $sectionId, $days, $periods) : [
    'total' => 0, 'filled' => 0, 'empty' => 0, 'teachers' => 0, 'percent' => 0,
];
$teacherDay = $view === 'teacher' ? teacherDayRoutine($db, $day) : [];
$conflictCount = 0;
foreach ($teacherDay as $row) {
    if (!empty($row['has_conflict'])) {
        $conflictCount++;
    }
}

$subjectTeacherJson = json_encode($assignedTeachers, JSON_UNESCAPED_UNICODE);
$classLabelText = $classId > 0 ? classLabel($db, $classId) : '';
$sectionLabelText = $sectionId > 0 ? sectionLabel($db, $sectionId) : 'সম্পূর্ণ শ্রেণী';
$settings = schoolSettings($db);
$publicRoutineUrl = publicSiteUrl('routine');

$pageStyles = <<<'CSS'
.routine-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; }
.routine-tabs { display:flex; gap:.4rem; background:#fff; padding:.35rem; border-radius:999px; box-shadow:0 .35rem 1rem rgba(15,23,42,.06); }
.routine-tab { padding:.4rem 1rem; border-radius:999px; text-decoration:none; color:inherit; font-weight:600; }
.routine-tab.is-active { background:#1a4d3e; color:#fff; }
.routine-stat { background:#fff; border:0; border-radius:.9rem; box-shadow:0 .35rem 1rem rgba(15,23,42,.07); padding:1rem 1.1rem; }
.routine-stat span { display:block; color:#64748b; font-size:.85rem; }
.routine-stat strong { font-size:1.55rem; line-height:1.2; color:#0f2f26; }
.routine-day-tabs { display:flex; flex-wrap:wrap; gap:.4rem; }
.routine-day-tab { min-width:5.2rem; text-align:center; border-radius:.75rem; padding:.45rem .7rem; text-decoration:none; color:inherit; background:#fff; border:1px solid rgba(15,23,42,.08); }
.routine-day-tab strong { display:block; font-size:.95rem; }
.routine-day-tab small { color:#64748b; }
.routine-day-tab.is-active { background:#1a4d3e; color:#fff; border-color:#1a4d3e; }
.routine-day-tab.is-active small { color:#d7e8e0; }
.routine-cell { border-radius:.7rem; padding:.45rem .5rem; min-height:4.2rem; font-size:.82rem; line-height:1.35; }
.routine-cell.is-empty { background:#f8fafc; color:#94a3b8; }
.routine-cell .sub { font-weight:700; }
.routine-cell .meta { opacity:.8; }
.routine-grid th { white-space:nowrap; }
.routine-editor tbody tr.conflict { background:#fff4f2; }
.routine-publish { font-size:.85rem; }
@media print {
  .routine-tabs, .routine-hero .btn, .routine-actions, .routine-editor, .routine-settings { display:none !important; }
}
CSS;

$pageScripts = [<<<JS
document.addEventListener('DOMContentLoaded', () => {
  const assigned = {$subjectTeacherJson} || {};
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  cls?.addEventListener('change', async () => {
    if (!sections) return;
    sections.innerHTML = '<option value="0">সম্পূর্ণ শ্রেণী</option>';
    if (!cls.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
    rows.forEach((row) => sections.add(new Option(row.section_name_ban || row.section_name, row.id)));
  });
  const destClass = document.getElementById('copyToClass');
  const destSection = document.getElementById('copyToSection');
  destClass?.addEventListener('change', async () => {
    if (!destSection) return;
    destSection.innerHTML = '<option value="0">সম্পূর্ণ শ্রেণী</option>';
    if (!destClass.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(destClass.value))).json();
    rows.forEach((row) => destSection.add(new Option(row.section_name_ban || row.section_name, row.id)));
  });
  document.querySelectorAll('[data-routine-editor]').forEach((editor) => {
    editor.addEventListener('change', (event) => {
      const select = event.target.closest('[data-subject-select]');
      if (!select) return;
      const entry = select.closest('[data-routine-entry]');
      const teacher = entry?.querySelector('[data-teacher-select]');
      if (teacher && assigned[select.value] && !teacher.value) {
        teacher.value = assigned[select.value];
      }
    });
    editor.addEventListener('click', (event) => {
      const addButton = event.target.closest('[data-add-routine-entry]');
      if (addButton) {
        const container = addButton.closest('[data-period-entries]');
        const entries = container?.querySelector('[data-routine-entries]');
        const template = entries?.querySelector('[data-routine-entry]');
        if (!entries || !template) return;
        const clone = template.cloneNode(true);
        const entryIndex = Number(container.dataset.nextIndex || entries.querySelectorAll('[data-routine-entry]').length);
        container.dataset.nextIndex = String(entryIndex + 1);
        clone.querySelectorAll('[name]').forEach((field) => {
          field.name = field.name.replace(/((?:subject|teacher|room)\[\d+\]\[)\d+(\])/, '$1' + entryIndex + '$2');
        });
        clone.querySelector('[data-subject-select]').value = '';
        clone.querySelector('[data-teacher-select]').value = '';
        clone.querySelector('[data-room-input]').value = '';
        const conflict = clone.querySelector('[data-routine-conflict]');
        if (conflict) conflict.remove();
        entries.append(clone);
        return;
      }
      const removeButton = event.target.closest('[data-remove-routine-entry]');
      if (!removeButton) return;
      const entries = removeButton.closest('[data-routine-entries]');
      const entry = removeButton.closest('[data-routine-entry]');
      if (!entries || !entry) return;
      if (entries.querySelectorAll('[data-routine-entry]').length > 1) {
        entry.remove();
      } else {
        entry.querySelector('[data-subject-select]').value = '';
        entry.querySelector('[data-teacher-select]').value = '';
        entry.querySelector('[data-room-input]').value = '';
      }
    });
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid routine-hero">
            <div>
              <h3 class="mb-1">শ্রেণী রুটিন</h3>
              <p class="text-secondary mb-0">শ্রেণী, শাখা, পিরিয়ড, বিষয় ও শিক্ষক — সবকিছু এখান থেকে নিয়ন্ত্রণ করুন।</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <nav class="routine-tabs" aria-label="Routine views">
                <a class="routine-tab<?= $view === 'class' ? ' is-active' : '' ?>" href="<?= h(routineAdminQuery(['view' => 'class'])) ?>">শ্রেণী রুটিন</a>
                <a class="routine-tab<?= $view === 'teacher' ? ' is-active' : '' ?>" href="<?= h(routineAdminQuery(['view' => 'teacher'])) ?>">শিক্ষক রুটিন</a>
              </nav>
              <a class="btn btn-outline-success btn-sm" href="<?= h($publicRoutineUrl) ?>" target="_blank" rel="noopener">ওয়েবসাইটে দেখুন</a>
              <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()">প্রিন্ট</button>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <div class="card stat-card routine-settings mb-3 no-print">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">পাবলিক রুটিন সেটিংস</h5>
                <span class="badge <?= (int) $routineSettings['is_published'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?> routine-publish">
                  <?= (int) $routineSettings['is_published'] === 1 ? 'ওয়েবসাইটে প্রকাশিত' : 'লুকানো' ?>
                </span>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="save_settings">
                  <input type="hidden" name="view" value="<?= h($view) ?>">
                  <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                  <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                  <input type="hidden" name="day" value="<?= (int) $day ?>">
                  <div class="col-md-2">
                    <label class="form-label">সমাবেশ</label>
                    <input class="form-control" name="assembly_label" value="<?= h((string) $routineSettings['assembly_label']) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">সমাবেশ সময়</label>
                    <input class="form-control" name="assembly_time" value="<?= h((string) $routineSettings['assembly_time']) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">বিরতি</label>
                    <input class="form-control" name="break_label" value="<?= h((string) $routineSettings['break_label']) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">বিরতি সময়</label>
                    <input class="form-control" name="break_time" value="<?= h((string) $routineSettings['break_time']) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">সেশন</label>
                    <input class="form-control" name="session_label" value="<?= h((string) $routineSettings['session_label']) ?>" placeholder="<?= h(bnDigits((string) date('Y'))) ?>">
                  </div>
                  <div class="col-md-2 d-flex align-items-center">
                    <div class="form-check mt-4">
                      <input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1" <?= (int) $routineSettings['is_published'] === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="is_published">ওয়েবসাইটে দেখাবে</label>
                    </div>
                  </div>
                  <div class="col-md-10">
                    <label class="form-label">পাদটীকা</label>
                    <input class="form-control" name="footer_note" value="<?= h((string) $routineSettings['footer_note']) ?>" placeholder="যেমন: রুটিন পরিবর্তন সাপেক্ষে প্রযোজ্য">
                  </div>
                  <div class="col-md-2">
                    <button class="btn btn-primary w-100">সেটিংস সংরক্ষণ</button>
                  </div>
                </form>
              </div>
            </div>

            <?php if ($view === 'class' && $classes === []): ?>
            <div class="alert alert-warning">শ্রেণী রুটিন তৈরির আগে Academic structure → Classes থেকে শ্রেণী যোগ করুন।</div>
            <?php elseif ($view === 'class'): ?>
            <form method="get" class="card stat-card mb-3 no-print">
              <input type="hidden" name="view" value="class">
              <input type="hidden" name="day" value="<?= (int) $day ?>">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-4">
                  <label class="form-label">শ্রেণী</label>
                  <select class="form-select" name="cls" id="filterClass">
                    <?php foreach ($classes as $class): ?>
                      <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">শাখা / বিভাগ</label>
                  <select class="form-select" name="sections" id="filterSection">
                    <?php if ($sections === []): ?>
                      <option value="0" selected>সম্পূর্ণ শ্রেণী</option>
                    <?php endif; ?>
                    <?php foreach ($sections as $section): ?>
                      <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4"><button class="btn btn-outline-primary w-100">রুটিন খুলুন</button></div>
              </div>
            </form>

            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3"><div class="routine-stat"><span>পূরণকৃত স্লট</span><strong><?= h(bnDigits((string) $stats['filled'])) ?></strong></div></div>
              <div class="col-6 col-lg-3"><div class="routine-stat"><span>খালি পিরিয়ড</span><strong><?= h(bnDigits((string) $stats['empty'])) ?></strong></div></div>
              <div class="col-6 col-lg-3"><div class="routine-stat"><span>শিক্ষক</span><strong><?= h(bnDigits((string) $stats['teachers'])) ?></strong></div></div>
              <div class="col-6 col-lg-3"><div class="routine-stat"><span>সম্পূর্ণতা</span><strong><?= h(bnDigits((string) $stats['percent'])) ?>%</strong></div></div>
            </div>

            <div class="routine-day-tabs mb-3 no-print">
              <?php foreach ($days as $key => $label): ?>
                <?php $filledDay = 0; foreach ($periods as $periodNo => $time) { if (($weekGrid[$key][$periodNo] ?? []) !== []) { $filledDay++; } } ?>
                <a class="routine-day-tab<?= $day === (int) $key ? ' is-active' : '' ?>" href="<?= h(routineAdminQuery(['day' => $key])) ?>">
                  <strong><?= h(weekDayShortName((int) $key)) ?></strong>
                  <small><?= h(bnDigits((string) $filledDay)) ?>/<?= h(bnDigits((string) count($periods))) ?></small>
                </a>
              <?php endforeach; ?>
            </div>

            <form method="post" class="card stat-card mb-3 routine-editor no-print">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="save_day">
              <input type="hidden" name="view" value="class">
              <input type="hidden" name="cls" value="<?= (int) $classId ?>">
              <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
              <input type="hidden" name="day" value="<?= (int) $day ?>">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                  <h5 class="mb-1"><?= h(weekDayBangla($day)) ?> সম্পাদনা — <?= h($classLabelText) ?> <?= h($sectionLabelText) ?></h5>
                  <p class="small text-secondary mb-0">একই পিরিয়ডে একাধিক বিষয় থাকলে “বিষয় যোগ করুন” চাপুন এবং প্রতিটির শিক্ষক আলাদা করে বাছুন।</p>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="periods.php">পিরিয়ড সময়</a>
              </div>
              <div class="table-responsive" data-routine-editor>
                <table class="table align-middle mb-0">
                  <thead><tr><th>পিরিয়ড</th><th>সময়</th><th>বিষয়, শিক্ষক ও কক্ষ</th></tr></thead>
                  <tbody>
                    <?php foreach ($periodMeta as $periodNo => $meta): ?>
                      <?php
                        $slotRows = $slots[$periodNo] ?? [];
                        if ($slotRows === []) {
                            $slotRows = [[]];
                        }
                      ?>
                      <tr>
                        <td class="text-nowrap">
                          <strong><?= h($meta['label']) ?></strong>
                          <div class="small text-secondary"><?= h(bnDigits((string) $periodNo)) ?></div>
                        </td>
                        <td class="text-nowrap"><?= h($meta['time']) ?></td>
                        <td data-period-entries data-next-index="<?= count($slotRows) ?>">
                          <div data-routine-entries>
                            <?php foreach ($slotRows as $entryNo => $slot): ?>
                              <?php
                                $subject = (string) ($slot['subject_name'] ?? '');
                                $teacherMobile = (string) ($slot['teacher_mobile'] ?? '');
                                $conflicts = routineTeacherConflicts($db, $day, (int) $periodNo, $teacherMobile, $classId, $sectionId);
                                $subjectInChoices = false;
                                foreach ($subjectChoices as $subjectRow) {
                                    if ($subject === trim((string) ($subjectRow['subject_name_ban'] ?: $subjectRow['subject_name']))) {
                                        $subjectInChoices = true;
                                        break;
                                    }
                                }
                              ?>
                              <div class="row g-2 align-items-start mb-2" data-routine-entry>
                                <div class="col-lg-4">
                                  <select class="form-select form-select-sm" name="subject[<?= (int) $periodNo ?>][<?= (int) $entryNo ?>]" data-subject-select>
                                    <?php if ($subject !== '' && !$subjectInChoices): ?>
                                      <option value="<?= h($subject) ?>" selected><?= h($subject) ?></option>
                                    <?php endif; ?>
                                    <option value="">বিষয় বাছুন</option>
                                    <?php foreach ($subjectChoices as $subjectRow): ?>
                                      <?php $label = trim((string) ($subjectRow['subject_name_ban'] ?: $subjectRow['subject_name'])); ?>
                                      <option value="<?= h($label) ?>" <?= $subject === $label ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>
                                <div class="col-lg-4">
                                  <select class="form-select form-select-sm" name="teacher[<?= (int) $periodNo ?>][<?= (int) $entryNo ?>]" data-teacher-select>
                                    <option value="">শিক্ষক বাছুন</option>
                                    <?php foreach ($teachers as $item): ?>
                                      <option value="<?= h((string) $item['teacher_mobile']) ?>" <?= $teacherMobile !== '' && teacherMobilesMatch($teacherMobile, (string) $item['teacher_mobile']) ? 'selected' : '' ?>><?= h((string) $item['teacher_name']) ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </div>
                                <div class="col-lg-3">
                                  <input class="form-control form-control-sm" name="room[<?= (int) $periodNo ?>][<?= (int) $entryNo ?>]" value="<?= h((string) ($slot['room'] ?? '')) ?>" placeholder="কক্ষ" data-room-input>
                                </div>
                                <div class="col-lg-1 d-flex justify-content-end">
                                  <button class="btn btn-outline-danger btn-sm" type="button" aria-label="বিষয় সরান" data-remove-routine-entry>&times;</button>
                                </div>
                                <?php if ($conflicts !== []): ?>
                                  <div class="col-12 small text-danger" data-routine-conflict>
                                    দ্বন্দ্ব: <?= h(classLabel($db, (int) $conflicts[0]['class_id'])) ?> <?= h(sectionLabel($db, (int) $conflicts[0]['section_id'])) ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                          <button class="btn btn-outline-primary btn-sm" type="button" data-add-routine-entry>+ বিষয় যোগ করুন</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2 routine-actions">
                <button class="btn btn-success">এই দিন সংরক্ষণ করুন</button>
                <div class="d-flex flex-wrap gap-2">
                  <button class="btn btn-outline-danger" name="action" value="clear_day" onclick="return confirm('এই দিনের সব পিরিয়ড মুছবেন?')">দিন মুছুন</button>
                </div>
              </div>
            </form>
            <div class="row g-3 mb-3 no-print">
              <div class="col-lg-6">
                <form method="post" class="card stat-card h-100">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="copy_day">
                  <input type="hidden" name="view" value="class">
                  <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                  <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                  <input type="hidden" name="day" value="<?= (int) $day ?>">
                  <div class="card-body">
                    <h6 class="mb-3">দিন কপি করুন</h6>
                    <p class="small text-secondary"><?= h(weekDayBangla($day)) ?> এর রুটিন অন্য বারে বসিয়ে দিন। গন্তব্য দিনটি ওভাররাইট হবে।</p>
                    <div class="row g-2 align-items-end">
                      <div class="col-8">
                        <label class="form-label">যে বারে যাবে</label>
                        <select class="form-select" name="to_day" required>
                          <?php foreach ($days as $key => $label): ?>
                            <?php if ((int) $key === $day) { continue; } ?>
                            <option value="<?= (int) $key ?>"><?= h(weekDayBangla((int) $key)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-4"><button class="btn btn-outline-primary w-100">কপি</button></div>
                    </div>
                  </div>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" class="card stat-card h-100" onsubmit="return confirm('গন্তব্য শাখার পুরো সপ্তাহ ওভাররাইট হবে। নিশ্চিত?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="copy_week">
                  <input type="hidden" name="view" value="class">
                  <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                  <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                  <input type="hidden" name="day" value="<?= (int) $day ?>">
                  <div class="card-body">
                    <h6 class="mb-3">সপ্তাহ অন্য শাখায় কপি</h6>
                    <div class="row g-2">
                      <div class="col-md-5">
                        <label class="form-label">শ্রেণী</label>
                        <select class="form-select" name="to_class" id="copyToClass" required>
                          <?php foreach ($classes as $class): ?>
                            <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label">শাখা</label>
                        <select class="form-select" name="to_section" id="copyToSection">
                          <option value="0">সম্পূর্ণ শ্রেণী</option>
                          <?php foreach ($sections as $section): ?>
                            <option value="<?= (int) $section['id'] ?>"><?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary w-100">কপি</button></div>
                    </div>
                  </div>
                </form>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($view === 'teacher'): ?>
            <form method="get" class="card stat-card mb-3 no-print">
              <input type="hidden" name="view" value="teacher">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-4">
                  <label class="form-label">বার</label>
                  <select class="form-select" name="day">
                    <?php foreach ($days as $key => $label): ?>
                      <option value="<?= (int) $key ?>" <?= $day === (int) $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3"><button class="btn btn-outline-primary w-100">দেখুন</button></div>
              </div>
            </form>
            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-4"><div class="routine-stat"><span>শিক্ষক</span><strong><?= h(bnDigits((string) count($teacherDay))) ?></strong></div></div>
              <div class="col-6 col-lg-4"><div class="routine-stat"><span>দ্বন্দ্ব</span><strong><?= h(bnDigits((string) $conflictCount)) ?></strong></div></div>
            </div>
            <?php endif; ?>

            <div class="card stat-card" id="printDiv">
              <div class="card-body">
                <div class="text-center mb-3">
                  <h4 class="mb-1"><?= h((string) $settings['school_name_bn']) ?></h4>
                  <?php if ($view === 'class'): ?>
                    <h5 class="mb-1">শ্রেণী রুটিন — <?= h($classLabelText) ?> <?= h($sectionLabelText) ?></h5>
                  <?php else: ?>
                    <h5 class="mb-1">শিক্ষক রুটিন — <?= h(weekDayBangla($day)) ?></h5>
                  <?php endif; ?>
                  <?php if (trim((string) $routineSettings['session_label']) !== ''): ?>
                    <div class="text-secondary">সেশন <?= h((string) $routineSettings['session_label']) ?></div>
                  <?php endif; ?>
                  <div class="d-flex justify-content-center flex-wrap gap-3 mt-2 small">
                    <?php if (trim((string) $routineSettings['assembly_time']) !== ''): ?>
                      <span class="text-danger"><?= h((string) $routineSettings['assembly_label']) ?>: <?= h((string) $routineSettings['assembly_time']) ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) $routineSettings['break_time']) !== ''): ?>
                      <span class="text-danger"><?= h((string) $routineSettings['break_label']) ?>: <?= h((string) $routineSettings['break_time']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($view === 'class'): ?>
                <div class="table-responsive">
                  <table class="table table-bordered text-center align-middle routine-grid mb-0">
                    <thead>
                      <tr>
                        <th>বার</th>
                        <?php foreach ($periodMeta as $meta): ?>
                          <th><?= h($meta['label']) ?><div class="small fw-normal"><?= h($meta['time']) ?></div></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($days as $key => $label): ?>
                        <tr>
                          <th>
                            <a class="text-decoration-none" href="<?= h(routineAdminQuery(['day' => $key])) ?>"><?= h(weekDayBangla((int) $key)) ?></a>
                          </th>
                          <?php foreach ($periodMeta as $periodNo => $meta): ?>
                            <?php
                            ?>
                            <td>
                              <?php $cellRows = $weekGrid[$key][$periodNo] ?? []; ?>
                              <?php if ($cellRows === []): ?>
                                <div class="routine-cell is-empty">—</div>
                              <?php else: ?>
                                <?php foreach ($cellRows as $cell): ?>
                                  <?php $tone = routineSubjectTone((string) $cell['subject_name']); ?>
                                  <div class="routine-cell mb-1" style="background:<?= h($tone['bg']) ?>;color:<?= h($tone['ink']) ?>">
                                    <div class="sub"><?= h((string) $cell['subject_name']) ?></div>
                                    <div class="meta"><?= h(teacherDisplayName($teacherMap, (string) ($cell['teacher_mobile'] ?? ''))) ?></div>
                                    <?php if (trim((string) ($cell['room'] ?? '')) !== ''): ?>
                                      <div class="meta"><?= h((string) $cell['room']) ?></div>
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
                <?php else: ?>
                  <?php if ($teacherDay === []): ?>
                    <div class="text-center text-secondary py-4">এই দিনে কোনো শিক্ষকের ক্লাস নির্ধারিত নেই।</div>
                  <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-bordered text-center align-middle routine-grid mb-0">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>শিক্ষকের নাম</th>
                          <?php foreach ($periodMeta as $meta): ?>
                            <th><?= h($meta['label']) ?><div class="small fw-normal"><?= h($meta['time']) ?></div></th>
                          <?php endforeach; ?>
                        </tr>
                      </thead>
                      <tbody>
                        <?php $sl = 0; foreach ($teacherDay as $row): $sl++; ?>
                          <tr>
                            <th><?= h(bnDigits((string) $sl)) ?></th>
                            <th class="text-start">
                              <?= h($row['name']) ?>
                              <?php if (!empty($row['has_conflict'])): ?><div class="small text-danger">একই পিরিয়ডে একাধিক ক্লাস</div><?php endif; ?>
                            </th>
                            <?php foreach ($periodMeta as $periodNo => $meta): ?>
                              <?php $cells = $row['slots'][$periodNo] ?? []; ?>
                              <td>
                                <?php if ($cells === []): ?>
                                  <div class="routine-cell is-empty">—</div>
                                <?php else: ?>
                                  <?php foreach ($cells as $cell): ?>
                                    <?php $tone = routineSubjectTone((string) $cell['subject_name']); ?>
                                    <div class="routine-cell mb-1 <?= count($cells) > 1 ? 'border border-danger' : '' ?>" style="background:<?= h($tone['bg']) ?>;color:<?= h($tone['ink']) ?>">
                                      <div class="sub"><?= h((string) $cell['subject_name']) ?></div>
                                      <div class="meta"><?= h(classLabel($db, (int) $cell['class_id'])) ?> <?= h(sectionLabel($db, (int) $cell['section_id'])) ?></div>
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
                <?php endif; ?>

                <?php if (trim((string) $routineSettings['footer_note']) !== ''): ?>
                  <p class="text-center text-secondary small mb-0 mt-3"><?= h((string) $routineSettings['footer_note']) ?></p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
