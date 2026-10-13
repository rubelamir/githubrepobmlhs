<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Student Profile | School Admin';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once __DIR__ . '/../includes/student-profile-sheet.php';
require_once dirname(__DIR__, 2) . '/shared/student-profiles-pdf.php';
$classes = schoolClasses($db);
$search = trim((string) ($_GET['q'] ?? ''));
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$rollFilter = trim((string) ($_GET['cls_roll'] ?? ''));
$loadList = (string) ($_GET['load'] ?? '') === '1';
$panelTeacher = getAuthenticatedTeacher();
$seeAllClasses = panelSeesAllStudents($panelTeacher);
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
$filterGet = [
    'q' => $search,
    'session_year' => $sessionYear,
    'cls' => $classId > 0 ? (string) $classId : '',
    'sections' => $sectionId > 0 ? (string) $sectionId : '',
    'cls_roll' => $rollFilter,
];
$students = [];
$sheets = [];
if ($loadList) {
    $sql = 'SELECT s.id FROM students_info s LEFT JOIN cls c ON s.cls = c.id LEFT JOIN sections sec ON s.sections = sec.id';
    [$where, $params] = studentsQueryFilters($filterGet);
    applyAssignedClassStudentScope($db, $where, $sessionYear, $classId, $sectionId, $panelTeacher);
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);
    foreach ($students as $student) {
        $vars = studentProfileSheetData($db, (int) $student['id']);
        if ($vars !== null) {
            $sheets[] = $vars;
        }
    }
}

$pdfQuery = ['download' => 'pdf', 'load' => '1'] + array_filter($filterGet, static fn (string $value): bool => $value !== '');
if (($_GET['download'] ?? '') === 'pdf') {
    if ($sheets === []) {
        header('Location: student-profiles.php', true, 302);
        exit;
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        outputStudentProfilesPdf($sheets, 'student-profiles-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear)
            . ($classId > 0 ? '-class' . $classId : '') . ($sectionId > 0 ? '-sec' . $sectionId : '') . '.pdf');
    } catch (Throwable $exception) {
        error_log('Student profile PDF failed: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF তৈরি করা যায়নি।';
    }
    exit;
}
$pageCssFiles = [
    'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&family=Hind+Siliguri:wght@400;600;700&display=swap',
    $basePath . 'css/student-profile-a4.css?v=' . (int) @filemtime(__DIR__ . '/../css/student-profile-a4.css'),
];
$pageStyles = <<<'CSS'
.sp-list.adm-a4-wrap { background: transparent; padding: 0; }
.sp-sheet-item { margin-bottom: 1.25rem; }
.sp-sheet-item .adm-a4-sheet { box-shadow: 0 12px 32px rgba(15, 23, 42, .12); }
@media print {
  .app-content-header, .no-print, .app-sidebar, .app-header, .app-footer { display: none !important; }
  html, body, .app-wrapper, .app-main, .app-content, .app-content > .container-fluid, .sp-list {
    background: #fff !important; margin: 0 !important; padding: 0 !important; max-width: none !important;
  }
  .sp-sheet-item { margin: 0; break-after: page; page-break-after: always; }
  .sp-sheet-item:last-child { break-after: auto; page-break-after: auto; }
}
CSS;
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  const selected = sections?.dataset.selected || '';
  cls?.addEventListener('change', async () => {
    sections.innerHTML = '<option value="">All sections</option>';
    if (!cls.value) return;
    const response = await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value));
    if (!response.ok) throw new Error('Could not load sections.');
    const rows = await response.json();
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
        <div class="app-content-header no-print">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0">Student Profile</h3>
              <p class="text-secondary mb-0">Filter students and view their profiles one after another.</p>
            </div>
            <?php if ($sheets !== []): ?>
              <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="button" onclick="window.print()">
                  <?= count($sheets) > 1 ? 'সকল প্রোফাইল প্রিন্ট (' . count($sheets) . ')' : 'Print profile' ?>
                </button>
                <a class="btn btn-outline-success" href="student-profiles.php?<?= h(http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3 no-print">
              <div class="card-body">
                <input type="hidden" name="load" value="1">
                <div class="row g-2 align-items-end">
                  <div class="col-md-4">
                    <label class="form-label" for="filterSession">Session</label>
                    <select class="form-select" name="session_year" id="filterSession">
                      <?php foreach ($sessionYears as $year): ?>
                        <option value="<?= h($year) ?>" <?= $sessionYear === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="filterClass">Class</label>
                    <select class="form-select" name="cls" id="filterClass">
                      <option value="">All classes</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h((string) $class['class_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="filterSection">Section</label>
                    <select class="form-select" name="sections" id="filterSection" data-selected="<?= $sectionId > 0 ? (string) $sectionId : '' ?>">
                      <option value="">All sections</option>
                      <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h((string) $section['section_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="d-flex gap-2 align-items-end flex-wrap mt-3">
                  <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="form-label" for="filterRoll">Roll <span class="text-secondary fw-normal">(Optional)</span></label>
                    <input class="form-control" type="text" name="cls_roll" id="filterRoll" value="<?= h($rollFilter) ?>" placeholder="e.g. 1, 5, 8-12">
                  </div>
                  <div class="flex-grow-1" style="min-width: 160px;">
                    <label class="form-label" for="filterSearch">Search <span class="text-secondary fw-normal">(Optional)</span></label>
                    <input class="form-control" type="text" name="q" id="filterSearch" value="<?= h($search) ?>" placeholder="Name, roll or mobile">
                  </div>
                  <div class="flex-shrink-0">
                    <button class="btn btn-outline-primary px-4" type="submit">Filter</button>
                  </div>
                </div>
                <div class="form-text mt-2">Use commas for specific rolls and a hyphen for a range, e.g. 1, 5, 8-12.</div>
              </div>
            </form>

            <?php if (!$loadList): ?>
              <div class="card stat-card"><div class="card-body text-secondary">Apply filters to display matching students.</div></div>
            <?php else: ?>
              <?php if ($sheets === []): ?>
                <div class="card stat-card"><div class="card-body text-center text-secondary">No students found.</div></div>
              <?php else: ?>
                <div class="adm-a4-wrap sp-list">
                  <?php foreach ($sheets as $sheetVars): ?>
                    <div class="sp-sheet-item">
                      <?= studentProfileSheetHtml($sheetVars) ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
