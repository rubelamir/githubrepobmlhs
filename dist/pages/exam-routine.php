<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$canEditRoutine = canManageModule('exam-routine');
$basePath = '../';
$pageTitle = 'পরীক্ষার রুটিন | School Admin';
$settings = schoolSettings($db);

$exams = schoolExams($db, true);
$classes = schoolClasses($db);
$sessionYears = [];
for ($year = (int) date('Y') + 1; $year >= 2015; $year--) {
    $sessionYears[] = (string) $year;
}

$examId = (int) ($_GET['exam'] ?? ($exams[0]['id'] ?? 0));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
if ($sessionYear !== '' && !in_array($sessionYear, $sessionYears, true)) {
    array_unshift($sessionYears, $sessionYear);
}
$sections = $classId > 0 ? schoolSections($db, $classId) : [];
$subjects = $classId > 0 ? classSubjects($db, $classId, $sectionId) : [];
if ($sectionId < 1 && $sections !== []) {
    $sectionId = (int) ($sections[0]['id'] ?? 0);
    $subjects = classSubjects($db, $classId, $sectionId);
}

function examRoutineAdminQuery(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $key === 'ref') {
            unset($params[$key]);
        }
    }

    return 'exam-routine.php?' . http_build_query($params);
}

function examRoutineRedirect(array $query, string $type, string $message): void
{
    redirectWithMessage(examRoutineAdminQuery($query), $type, $message);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEditRoutine) {
        http_response_code(403);
        exit('Access denied.');
    }
    $action = (string) ($_POST['action'] ?? 'save');
    $examId = (int) ($_POST['exam'] ?? $examId);
    $classId = (int) ($_POST['cls'] ?? $classId);
    $sectionId = (int) ($_POST['sections'] ?? $sectionId);
    $sessionYear = trim((string) ($_POST['session_year'] ?? $sessionYear));
    $baseQuery = [
        'exam' => $examId,
        'cls' => $classId,
        'sections' => $sectionId,
        'session_year' => $sessionYear,
    ];

    try {
        if ($action === 'save') {
            $subjectId = (int) ($_POST['class_subject_id'] ?? 0);
            $subjectName = '';
            foreach (classSubjects($db, $classId, $sectionId) as $subject) {
                if ((int) $subject['id'] === $subjectId) {
                    $subjectName = examRoutineSubjectLabel($subject);
                    if (trim((string) ($_POST['full_marks'] ?? '')) === '' && isset($subject['full_marks'])) {
                        $_POST['full_marks'] = (string) $subject['full_marks'];
                    }
                    break;
                }
            }
            if ($subjectId < 1 || $subjectName === '') {
                throw new RuntimeException('তালিকা থেকে একটি বিষয় নির্বাচন করুন।');
            }
            saveExamRoutineRow($db, [
                'exam_id' => $examId,
                'session_year' => $sessionYear,
                'class_id' => $classId,
                'section_id' => $sectionId,
                'exam_date' => (string) ($_POST['exam_date'] ?? ''),
                'subject_name' => $subjectName,
                'class_subject_id' => $subjectId,
                'start_time' => (string) ($_POST['start_time'] ?? ''),
                'end_time' => (string) ($_POST['end_time'] ?? ''),
                'room' => (string) ($_POST['room'] ?? ''),
                'full_marks' => (string) ($_POST['full_marks'] ?? ''),
                'note' => '',
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]);
            examRoutineRedirect($baseQuery, 'success', 'রুটিনে বিষয় যোগ হয়েছে।');
        }

        if ($action === 'delete') {
            deleteExamRoutineRow($db, postedRecordId('exam_routine'));
            examRoutineRedirect($baseQuery, 'success', 'রুটিন এন্ট্রি মুছে ফেলা হয়েছে।');
        }

        if ($action === 'clear') {
            $cleared = clearExamRoutine($db, $examId, $sessionYear, $classId, $sectionId);
            examRoutineRedirect(
                $baseQuery,
                'success',
                bnDigits((string) $cleared) . 'টি এন্ট্রি মুছে ফেলা হয়েছে।'
            );
        }

        if ($action === 'copy') {
            $toClass = (int) ($_POST['to_class'] ?? 0);
            $toSection = (int) ($_POST['to_section'] ?? 0);
            $copied = copyExamRoutineToSection(
                $db,
                $examId,
                $sessionYear,
                $classId,
                $sectionId,
                $toClass,
                $toSection
            );
            examRoutineRedirect(
                [
                    'exam' => $examId,
                    'cls' => $toClass,
                    'sections' => $toSection,
                    'session_year' => $sessionYear,
                ],
                'success',
                bnDigits((string) $copied) . 'টি স্লট নতুন শাখায় কপি হয়েছে।'
            );
        }

        throw new RuntimeException('অজানা অ্যাকশন।');
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        if (str_contains($message, 'uniq_exam_routine_slot') || str_contains($message, 'Duplicate')) {
            $message = 'এই তারিখে একই বিষয় ইতোমধ্যে রুটিনে আছে।';
        } else {
            $message = userFacingError($exception);
        }
        examRoutineRedirect($baseQuery, 'danger', $message);
    }
}

$loadList = $examId > 0 && $sessionYear !== '' && $classId > 0;
$rows = $loadList ? examRoutineRows($db, $examId, $sessionYear, $classId, $sectionId) : [];
if ($sectionId > 0) {
    $rows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => (int) ($row['section_id'] ?? 0) === $sectionId
            || (int) ($row['section_id'] ?? 0) === 0
    ));
}

$examName = '';
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $examId) {
        $examName = trim((string) ($exam['exam_name_bangla'] ?? $exam['exam_name_english'] ?? ''));
        break;
    }
}
$classLabelText = $classId > 0 ? classLabel($db, $classId) : '';
$sectionLabelText = $sectionId > 0 ? sectionLabel($db, $sectionId) : 'সকল শাখা';

$printLogo = !empty($settings['logo']) ? publicSiteUrl((string) $settings['logo']) : '';
$printSchoolName = trim((string) ($settings['school_name_bn'] ?? ''));
$printAddress = schoolPlainAddress((string) ($settings['address'] ?? ''));
$printEiin = trim((string) ($settings['eiin'] ?? ''));
$printMobile = publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''));

usort(
    $rows,
    static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['exam_date'] ?? ''), (string) ($b['exam_date'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));

        return $cmp !== 0 ? $cmp : ((int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
    }
);

$pdfQuery = [
    'download' => 'pdf',
    'exam' => $examId,
    'cls' => $classId,
    'sections' => $sectionId,
    'session_year' => $sessionYear,
];
if (($_GET['download'] ?? '') === 'pdf') {
    $backQuery = array_diff_key($pdfQuery, ['download' => true]);
    if (!$loadList || $rows === []) {
        examRoutineRedirect($backQuery, 'warning', 'PDF তৈরির জন্য এই ফিল্টারে কোনো রুটিন নেই।');
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        require_once dirname(__DIR__, 2) . '/shared/exam-routine-pdf.php';
        $classLine = $classLabelText . ($sectionId > 0 ? ' · ' . $sectionLabelText : '');
        $pdfFile = 'exam-routine-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear)
            . '-e' . $examId . '-c' . $classId . ($sectionId > 0 ? '-s' . $sectionId : '') . '.pdf';
        outputExamRoutinePdf($rows, $settings, $examName, $classLine, $sessionYear, $pdfFile);
    } catch (Throwable $exception) {
        error_log('Exam routine PDF failed: ' . $exception->getMessage());
        examRoutineRedirect($backQuery, 'danger', 'PDF তৈরি করা যায়নি।');
    }
    exit;
}

$subjectOptionsJson = [];
foreach ($subjects as $subject) {
    $subjectOptionsJson[] = [
        'id' => (int) $subject['id'],
        'name' => examRoutineSubjectLabel($subject),
        'full_marks' => (string) ($subject['full_marks'] ?? ''),
    ];
}

$pageStyles = <<<'CSS'
@font-face {
  font-family: "Hind Siliguri";
  src: url("../../bmlhs/fonts/ttf/HindSiliguri-Regular.ttf") format("truetype");
  font-weight: 400;
  font-display: swap;
}
@font-face {
  font-family: "Hind Siliguri";
  src: url("../../bmlhs/fonts/ttf/HindSiliguri-Bold.ttf") format("truetype");
  font-weight: 700;
  font-display: swap;
}
.exam-routine-print-head {
  display: none;
  color: #0f2f26;
  font-family: "Hind Siliguri", "Noto Sans Bengali", "Segoe UI", sans-serif;
  margin-bottom: 4mm;
}
.erp-head {
  display: flex;
  align-items: center;
  gap: 3mm;
  border-bottom: 0.35mm solid #0f2f26;
  padding-bottom: 2.5mm;
  margin-bottom: 2.5mm;
}
.erp-logo {
  width: 28mm;
  height: 28mm;
  object-fit: contain;
  flex: 0 0 auto;
}
.erp-brand { flex: 1; text-align: center; }
.erp-school {
  font-size: 24pt;
  font-weight: 700;
  line-height: 1.25;
}
.erp-meta {
  font-size: 13pt;
  color: #334155;
  line-height: 1.4;
}
.erp-title {
  margin: 3mm 0 3mm;
  text-align: center;
  font-size: 15pt;
  font-weight: 700;
  letter-spacing: .4px;
  background: #efe4c8;
  border: 0.3mm solid #c4892a;
  padding: 1.2mm 2mm;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.erp-exam {
  text-align: center;
  font-size: 13pt;
  font-weight: 700;
}
.routine-table-wrap {
  background: #fff;
  border: 1px solid rgba(15, 47, 38, .15);
  border-radius: .75rem;
  overflow: hidden;
  margin-bottom: 1rem;
}
.routine-table {
  width: 100%;
  margin: 0;
  border-collapse: collapse;
  font-size: .95rem;
  color: #0f2f26;
}
.routine-table thead th {
  background: #0f2f26;
  color: #fff;
  font-weight: 700;
  text-align: center;
  vertical-align: middle;
  padding: .6rem .5rem;
  border: 1px solid #0f2f26;
  white-space: nowrap;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.routine-table tbody td {
  padding: .5rem .5rem;
  border: 1px solid rgba(15, 47, 38, .18);
  vertical-align: middle;
}
.routine-table tbody tr:nth-child(even) td {
  background: #f7f3e8;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.routine-table tbody tr:hover td { background: #efe4c8; }
.routine-table .c { text-align: center; white-space: nowrap; }
.routine-table .subject { font-weight: 700; }
.routine-table .act { width: 1%; text-align: center; white-space: nowrap; }
@media print {
  @page {
    size: A4 portrait;
    margin: 20mm 8mm 8mm 8mm;
  }
  .no-print, .app-sidebar, .app-header, .app-footer, .btn { display: none !important; }
  .app-content, .app-main, .app-wrapper { margin: 0 !important; padding: 0 !important; }
  .app-content > .container-fluid { padding: 0 !important; max-width: none !important; }
  .exam-routine-print-head { display: block !important; }
  .routine-table-wrap { border: 0 !important; border-radius: 0 !important; overflow: visible !important; }
  .routine-table {
    font-family: "Hind Siliguri", "Noto Sans Bengali", "Segoe UI", sans-serif;
    font-size: 10.5pt;
  }
  .routine-table thead { display: table-header-group; }
  .routine-table tr { page-break-inside: avoid; break-inside: avoid; }
  .routine-table thead th { padding: 2mm 1.5mm; border: 0.3mm solid #0f2f26; }
  .routine-table tbody td { padding: 1.6mm 1.5mm; border: 0.3mm solid #0f2f26; }
  .routine-table tbody tr:hover td { background: inherit; }
  .routine-table tbody tr:nth-child(odd) td { background: #fff; }
  .routine-sign {
    display: flex !important;
    justify-content: flex-end;
    margin-top: 22mm;
    font-family: "Hind Siliguri", "Noto Sans Bengali", "Segoe UI", sans-serif;
    font-size: 11pt;
    font-weight: 700;
    color: #0f2f26;
  }
  .routine-sign div {
    min-width: 45mm;
    text-align: center;
    border-top: 0.3mm solid #0f2f26;
    padding-top: 1mm;
  }
}
.routine-sign { display: none; }
CSS;

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  cls?.addEventListener('change', async () => {
    sections.innerHTML = '';
    if (!cls.value) {
      sections.add(new Option('আগে শ্রেণী নির্বাচন করুন', '0'));
      return;
    }
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
    if (!Array.isArray(rows) || rows.length === 0) {
      sections.add(new Option('শাখা নেই', '0'));
      return;
    }
    rows.forEach((row) => sections.add(new Option(row.section_name_ban, row.id)));
  });

  const subjectSelect = document.getElementById('classSubjectId');
  const fullMarks = document.getElementById('fullMarks');
  const subjectMeta = JSON.parse(document.getElementById('subjectMetaJson')?.textContent || '[]');
  subjectSelect?.addEventListener('change', () => {
    const id = Number(subjectSelect.value || 0);
    const hit = subjectMeta.find((row) => row.id === id);
    if (!hit) return;
    if (fullMarks && hit.full_marks !== '') {
      fullMarks.value = hit.full_marks;
    }
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <h3 class="mb-0">পরীক্ষার রুটিন</h3>
              <p class="text-secondary mb-0">পরীক্ষা, শিক্ষাবর্ষ ও শ্রেণী অনুযায়ী তারিখভিত্তিক রুটিন তৈরি ও ব্যবস্থাপনা।</p>
            </div>
            <?php if ($loadList && $rows !== []): ?>
              <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary" type="button" onclick="window.print()">প্রিন্ট</button>
                <a class="btn btn-outline-success" href="<?= h('exam-routine.php?' . http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i>PDF ডাউনলোড
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <form method="get" class="card stat-card mb-3 no-print">
              <div class="card-body">
                <div class="row g-2 align-items-end">
                  <div class="col-md-2">
                    <label class="form-label">শিক্ষাবর্ষ</label>
                    <select class="form-select" name="session_year">
                      <?php foreach ($sessionYears as $year): ?>
                        <option value="<?= h($year) ?>" <?= $sessionYear === $year ? 'selected' : '' ?>><?= h(bnDigits($year)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">পরীক্ষা</label>
                    <select class="form-select" name="exam" required>
                      <option value="">নির্বাচন করুন</option>
                      <?php foreach ($exams as $exam): ?>
                        <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>
                          <?= h((string) ($exam['exam_name_bangla'] ?? $exam['exam_name_english'] ?? '')) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">শ্রেণী</label>
                    <select class="form-select" name="cls" id="filterClass" required>
                      <option value="">নির্বাচন করুন</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>>
                          <?= h((string) ($class['class_name_ban'] ?? $class['class_name'] ?? '')) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">শাখা</label>
                    <select class="form-select" name="sections" id="filterSection">
                      <?php if ($sections === []): ?>
                        <option value="0"><?= $classId > 0 ? 'শাখা নেই' : 'আগে শ্রেণী নির্বাচন করুন' ?></option>
                      <?php endif; ?>
                      <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                          <?= h((string) ($section['section_name_ban'] ?? $section['section_name'] ?? '')) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">দেখুন</button>
                  </div>
                </div>
              </div>
            </form>

            <?php if (!$loadList): ?>
              <div class="card stat-card">
                <div class="card-body text-secondary">শিক্ষাবর্ষ, পরীক্ষা ও শ্রেণী নির্বাচন করে রুটিন তৈরি করুন।</div>
              </div>
            <?php else: ?>
              <div class="exam-routine-print-head">
                <div class="erp-head">
                  <?php if ($printLogo !== ''): ?>
                    <img class="erp-logo" src="<?= h($printLogo) ?>" alt="">
                  <?php endif; ?>
                  <div class="erp-brand">
                    <div class="erp-school"><?= h($printSchoolName) ?></div>
                    <?php if ($printAddress !== ''): ?>
                      <div class="erp-meta"><?= h($printAddress) ?></div>
                    <?php endif; ?>
                    <?php if ($printEiin !== '' || $printMobile !== ''): ?>
                      <div class="erp-meta">
                        <?php if ($printEiin !== ''): ?>EIIN: <?= h($printEiin) ?><?php endif; ?>
                        <?php if ($printEiin !== '' && $printMobile !== ''): ?> · <?php endif; ?>
                        <?php if ($printMobile !== ''): ?>মোবাইল: <?= h($printMobile) ?><?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="erp-title">পরীক্ষার রুটিন / EXAM ROUTINE</div>
                <div class="erp-exam">
                  <?= h($examName !== '' ? $examName : 'পরীক্ষা') ?>
                  · <?= h($classLabelText) ?><?= $sectionId > 0 ? ' · ' . h($sectionLabelText) : '' ?>
                  · শিক্ষাবর্ষ <?= h(bnDigits($sessionYear)) ?>
                </div>
              </div>

              <?php if ($canEditRoutine): ?>
              <div class="card stat-card mb-3 no-print">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <h5 class="mb-0">নতুন বিষয় যোগ</h5>
                </div>
                <div class="card-body">
                  <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="exam" value="<?= $examId ?>">
                    <input type="hidden" name="cls" value="<?= $classId ?>">
                    <input type="hidden" name="sections" value="<?= $sectionId ?>">
                    <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                    <script type="application/json" id="subjectMetaJson"><?= json_encode($subjectOptionsJson, JSON_UNESCAPED_UNICODE) ?></script>

                    <div class="col-md-3">
                      <label class="form-label">তারিখ</label>
                      <input class="form-control" type="date" name="exam_date" required
                             value="">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">বিষয়</label>
                      <select class="form-select" name="class_subject_id" id="classSubjectId" required>
                        <option value="">নির্বাচন করুন</option>
                        <?php foreach ($subjects as $subject): ?>
                          <?php $sid = (int) $subject['id']; ?>
                          <option value="<?= $sid ?>">
                            <?= h(examRoutineSubjectLabel($subject)) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">পূর্ণমান</label>
                      <input class="form-control" name="full_marks" id="fullMarks"
                             value="" placeholder="১০০">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">শুরু</label>
                      <input class="form-control" type="time" name="start_time"
                             value="">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">শেষ</label>
                      <input class="form-control" type="time" name="end_time"
                             value="">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">কক্ষ / হল</label>
                      <input class="form-control" name="room"
                             value="" placeholder="হল-১">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">ক্রম</label>
                      <input class="form-control" type="number" name="sort_order"
                             value="0">
                    </div>
                    <div class="col-12">
                      <button class="btn btn-primary" type="submit">যোগ করুন</button>
                    </div>
                  </form>
                </div>
              </div>
              <?php endif; ?>

              <?php if ($rows === []): ?>
                <div class="card stat-card mb-3">
                  <div class="card-body text-secondary">এই ফিল্টারে এখনো কোনো রুটিন নেই। উপরের ফর্ম থেকে বিষয় যোগ করুন।</div>
                </div>
              <?php else: ?>
                <div class="routine-table-wrap">
                  <div class="table-responsive">
                    <table class="routine-table">
                      <thead>
                        <tr>
                          <th style="width:6%">ক্রম</th>
                          <th style="width:14%">তারিখ</th>
                          <th style="width:10%">বার</th>
                          <th>বিষয়</th>
                          <th style="width:20%">সময়</th>
                          <th style="width:9%">পূর্ণমান</th>
                          <th style="width:9%">কক্ষ</th>
                          <?php if ($canEditRoutine): ?><th class="no-print">Delete</th><?php endif; ?>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                          <?php
                            $dateValue = (string) ($row['exam_date'] ?? '');
                            $weekday = '';
                            $dt = $dateValue !== '' ? date_create($dateValue) : false;
                            if ($dt instanceof DateTimeInterface) {
                                $weekday = weekDayBangla((int) $dt->format('w'));
                            }
                            $timeLabel = examRoutineTimeLabel((string) ($row['start_time'] ?? ''), (string) ($row['end_time'] ?? ''));
                            $fullMarks = trim((string) ($row['full_marks'] ?? ''));
                            $room = trim((string) ($row['room'] ?? ''));
                          ?>
                          <tr>
                            <td class="c"><?= h(bnDigits((string) ($index + 1))) ?></td>
                            <td class="c"><?= h(examRoutineDateBn($dateValue)) ?></td>
                            <td class="c"><?= h($weekday !== '' ? $weekday : '—') ?></td>
                            <td class="subject"><?= h((string) ($row['subject_name'] ?? '')) ?></td>
                            <td class="c"><?= h($timeLabel !== '' ? $timeLabel : '—') ?></td>
                            <td class="c"><?= h($fullMarks !== '' ? bnDigits($fullMarks) : '—') ?></td>
                            <td class="c"><?= h($room !== '' ? $room : '—') ?></td>
                            <?php if ($canEditRoutine): ?>
                            <td class="act no-print">
                              <form method="post" class="d-inline" onsubmit="return confirm('এই এন্ট্রি মুছবেন?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="exam" value="<?= $examId ?>">
                                <input type="hidden" name="cls" value="<?= $classId ?>">
                                <input type="hidden" name="sections" value="<?= $sectionId ?>">
                                <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                                <?= signedRecordField((int) $row['id'], 'exam_routine') ?>
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                              </form>
                            </td>
                            <?php endif; ?>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="routine-sign">
                  <div>প্রধান শিক্ষক</div>
                </div>
              <?php endif; ?>

              <?php if ($canEditRoutine): ?>
              <div class="row g-3 no-print">
                <div class="col-lg-6">
                  <div class="card stat-card">
                    <div class="card-header"><h5 class="mb-0">অন্য শাখায় কপি</h5></div>
                    <div class="card-body">
                      <form method="post" class="row g-2 align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="copy">
                        <input type="hidden" name="exam" value="<?= $examId ?>">
                        <input type="hidden" name="cls" value="<?= $classId ?>">
                        <input type="hidden" name="sections" value="<?= $sectionId ?>">
                        <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                        <div class="col-md-5">
                          <label class="form-label">গন্তব্য শ্রেণী</label>
                          <select class="form-select" name="to_class" required>
                            <?php foreach ($classes as $class): ?>
                              <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>>
                                <?= h((string) ($class['class_name_ban'] ?? $class['class_name'] ?? '')) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">গন্তব্য শাখা</label>
                          <select class="form-select" name="to_section">
                            <option value="0">সম্পূর্ণ শ্রেণী / সকল</option>
                            <?php foreach ($sections as $section): ?>
                              <option value="<?= (int) $section['id'] ?>">
                                <?= h((string) ($section['section_name_ban'] ?? $section['section_name'] ?? '')) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <div class="form-text">গন্তব্য শ্রেণীর শাখা তালিকায় না থাকলে «সম্পূর্ণ শ্রেণী» বেছে নিন।</div>
                        </div>
                        <div class="col-md-3">
                          <button class="btn btn-outline-primary w-100" type="submit">কপি</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <div class="col-lg-6">
                  <div class="card stat-card">
                    <div class="card-header"><h5 class="mb-0">রুটিন মুছুন</h5></div>
                    <div class="card-body">
                      <form method="post" onsubmit="return confirm('এই ফিল্টারের সব রুটিন মুছে ফেলবেন?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="clear">
                        <input type="hidden" name="exam" value="<?= $examId ?>">
                        <input type="hidden" name="cls" value="<?= $classId ?>">
                        <input type="hidden" name="sections" value="<?= $sectionId ?>">
                        <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                        <p class="text-secondary small mb-3">বর্তমান পরীক্ষা, শিক্ষাবর্ষ, শ্রেণী ও শাখার সব এন্ট্রি মুছে যাবে।</p>
                        <button class="btn btn-outline-danger" type="submit">সব মুছুন</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
