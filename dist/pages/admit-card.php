<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/admit-cards-ui.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'প্রবেশপত্র | School Admin';
$settings = schoolSettings($db);

$exams = schoolExams($db, true);
$classes = schoolClasses($db);
$panelTeacher = getAuthenticatedTeacher();
$seeAllClasses = canManageModule('admit-card', $panelTeacher);
$sessionYears = [];
for ($year = (int) date('Y') + 1; $year >= 2015; $year--) {
    $sessionYears[] = (string) $year;
}

$examId = (int) ($_GET['exam'] ?? 0);
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$rollFilter = trim((string) ($_GET['cls_roll'] ?? ''));
$loadList = (string) ($_GET['load'] ?? '') === '1';
$studentId = requestRecordId('student');

if ($sessionYear !== '' && !in_array($sessionYear, $sessionYears, true)) {
    array_unshift($sessionYears, $sessionYear);
}
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

$examName = '';
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $examId) {
        $examName = trim((string) ($exam['exam_name_bangla'] ?? $exam['exam_name_english'] ?? ''));
        break;
    }
}

$students = [];
if ($studentId > 0) {
    $rows = fetchRows(
        $db,
        'SELECT s.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
         FROM students_info s
         LEFT JOIN cls c ON s.cls = c.id
         LEFT JOIN sections sec ON s.sections = sec.id
         WHERE s.id = :id LIMIT 1',
        ['id' => $studentId]
    );
    $students = $rows !== [] ? [$rows[0]] : [];
    if ($students !== [] && !$seeAllClasses && !classTeacherScopeAllows($scope, (int) ($students[0]['cls'] ?? 0), (int) ($students[0]['sections'] ?? 0))) {
        $students = [];
    }
    if ($students !== []) {
        $loadList = true;
        if ($classId < 1) {
            $classId = (int) ($students[0]['cls'] ?? 0);
        }
        if ($sectionId < 1) {
            $sectionId = (int) ($students[0]['sections'] ?? 0);
        }
        if ($sessionYear === '') {
            $sessionYear = trim((string) ($students[0]['session_year'] ?? date('Y')));
        }
        $sections = $classId > 0 ? schoolSections($db, $classId) : [];
    }
} elseif ($loadList) {
    $sql = 'SELECT s.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
            FROM students_info s
            LEFT JOIN cls c ON s.cls = c.id
            LEFT JOIN sections sec ON s.sections = sec.id
            WHERE s.status = 1';
    $params = [];
    if ($sessionYear !== '') {
        $sql .= ' AND s.session_year = :session_year';
        $params['session_year'] = $sessionYear;
    }
    if (!$seeAllClasses && $classId < 1) {
        $sql .= ' AND 1 = 0';
    }
    if ($classId > 0) {
        $sql .= ' AND s.cls = :cls';
        $params['cls'] = $classId;
    }
    if ($sectionId > 0) {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = $sectionId;
    } elseif (!$seeAllClasses && $classId > 0 && !isset($scope['entire'][$classId])) {
        $allowedSections = array_map('intval', array_keys($scope['sections'][$classId] ?? []));
        $sql .= ' AND s.sections IN (' . ($allowedSections === [] ? '0' : implode(', ', $allowedSections)) . ')';
    }
    if ($rollFilter !== '') {
        $rollValues = studentIdCardRollFilterValues($rollFilter);
        if ($rollValues !== []) {
            $placeholders = [];
            foreach ($rollValues as $index => $value) {
                $key = 'roll_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $value;
            }
            $sql .= ' AND s.cls_roll IN (' . implode(', ', $placeholders) . ')';
        }
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 200';
    $students = fetchRows($db, $sql, $params);
}

$papers = [];
if ($examId > 0 && $sessionYear !== '' && $classId > 0) {
    $papers = examRoutineRows($db, $examId, $sessionYear, $classId, $sectionId);
}

$canPrint = $loadList && $students !== [] && $examId > 0;

$pdfQuery = [
    'download' => 'pdf',
    'load' => '1',
    'exam' => $examId,
    'cls' => $classId,
    'sections' => $sectionId,
    'session_year' => $sessionYear,
];
if ($rollFilter !== '') {
    $pdfQuery['cls_roll'] = $rollFilter;
}
if ($studentId > 0) {
    $pdfQuery['student'] = $studentId;
}
$pdfFile = 'admit-cards-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear !== '' ? $sessionYear : 'session')
    . '-e' . $examId . '-c' . $classId . '.pdf';

if (($_GET['download'] ?? '') === 'pdf') {
    if (!$canPrint) {
        header('Location: admit-card.php', true, 302);
        exit;
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        require_once dirname(__DIR__, 2) . '/shared/admit-cards-pdf.php';
        outputAdmitCardsPdf($students, $settings, $examName, $sessionYear, $papers, $pdfFile);
    } catch (Throwable $exception) {
        error_log('Admit card PDF failed: ' . $exception->getMessage());
        redirectWithMessage(
            'admit-card.php?' . http_build_query(array_diff_key($pdfQuery, ['download' => true])),
            'danger',
            'PDF তৈরি করা যায়নি।'
        );
    }
    exit;
}

$pageStyles = admitCardUiCss();
$pageScripts = [admitCardUiScripts()];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <h3 class="mb-0">প্রবেশপত্র / Admit Card</h3>
              <p class="text-secondary mb-0">পরীক্ষা ও শ্রেণী অনুযায়ী শিক্ষার্থীদের অ্যাডমিট কার্ড তৈরি ও প্রিন্ট।</p>
            </div>
            <?php if ($canPrint): ?>
              <div class="admit-actions">
                <button class="btn btn-primary" type="button" id="admitCardPrint">প্রিন্ট</button>
                <a class="btn btn-outline-success" href="<?= h('admit-card.php?' . http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i>PDF ডাউনলোড
                </a>
                <a class="btn btn-outline-secondary" href="exam-routine.php?<?= h(http_build_query([
                    'exam' => $examId,
                    'cls' => $classId,
                    'sections' => $sectionId,
                    'session_year' => $sessionYear,
                ])) ?>">Exam routine</a>
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
                  <div class="col-md-2">
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
                      <option value="0">সকল শাখা</option>
                      <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>>
                          <?= h((string) ($section['section_name_ban'] ?? $section['section_name'] ?? '')) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">রোল (ঐচ্ছিক)</label>
                    <input class="form-control" type="text" name="cls_roll" value="<?= h($rollFilter) ?>" placeholder="১, ৫, ৮-১২">
                  </div>
                  <div class="col-md-1">
                    <input type="hidden" name="load" value="1">
                    <button class="btn btn-primary w-100" type="submit">তৈরি</button>
                  </div>
                </div>
                <div class="form-text mt-2">বিষয়ের তালিকা <strong>Exam routine</strong> থেকে আসবে। আগে রুটিন তৈরি করুন।</div>
              </div>
            </form>

            <?php
              renderAdmitCardSheets(
                  $students,
                  $settings,
                  $examName,
                  $sessionYear,
                  $papers,
                  $loadList && $examId > 0
              );
            ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
