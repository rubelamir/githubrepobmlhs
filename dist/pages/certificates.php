<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/certificate-border.php';
require_once dirname(__DIR__, 2) . '/shared/id-cards-ui.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$forcedCertificateType = $forcedCertificateType ?? null;
$certificateFormAction = $certificateFormAction ?? 'certificates.php';
$pageTitle = $pageTitle ?? 'Certificates | School Admin';
$settings = schoolSettings($db);
$types = certificateTypes();
unset($types['testimonial']); // Dedicated Testimonial sidebar page.
$cardTypes = certificateCardTypes();
$classes = schoolClasses($db);
$id = requestRecordId('student');

if (is_string($forcedCertificateType) && isset($cardTypes[$forcedCertificateType])) {
    $type = $forcedCertificateType;
} else {
    $type = (string) ($_GET['type'] ?? 'studentship');
    // Old certificate-dropdown links → dedicated sidebar pages.
    if ($type === 'idcard' || $type === 'seatlevel') {
        $target = $type === 'idcard' ? 'id-cards.php' : 'seat-level.php';
        $q = $_GET;
        unset($q['type']);
        $qs = http_build_query($q);
        header('Location: ' . $target . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
        exit;
    }
    if ($type === 'testimonial') {
        header('Location: testimonials.php', true, 302);
        exit;
    }
    if (!isset($types[$type])) {
        $type = 'studentship';
    }
}
$isIdCard = $type === 'idcard';
$isSeatLevel = $type === 'seatlevel';
$usesIdCardUi = $isIdCard || $isSeatLevel;
$hasForcedCardType = is_string($forcedCertificateType) && isset($cardTypes[$forcedCertificateType]);
$lang = certificateLanguage((string) ($_GET['lang'] ?? 'bn'));
$typeTitle = certificateTypeTitle($type, $lang);
$sheetCopy = certificateSheetCopy($lang);
$schoolHeading = certificateSchoolHeading($settings, $lang);
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$rollFilter = trim((string) ($_GET['cls_roll'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$examId = $isSeatLevel ? (int) ($_GET['exam'] ?? 0) : 0;
$exams = $isSeatLevel ? schoolExams($db) : [];
$examName = '';
if ($isSeatLevel && $examId > 0) {
    foreach ($exams as $exam) {
        if ((int) ($exam['id'] ?? 0) === $examId) {
            $examName = trim((string) ($exam['exam_name_bangla'] ?? ''));
            if ($examName === '') {
                $examName = trim((string) ($exam['exam_name_english'] ?? ''));
            }
            break;
        }
    }
    if ($examName === '') {
        $examId = 0;
    }
}
$loadList = (string) ($_GET['load'] ?? '') === '1';
$cardModule = $isIdCard ? 'id-cards' : 'seat-level';
$seeAllClasses = !$usesIdCardUi || canManageModule($cardModule);
$scope = ['entire' => [], 'sections' => []];
if (!$seeAllClasses) {
    $scope = classTeacherScope($db, getAuthenticatedTeacher(), $sessionYear);
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
}$sessionYears = [];
for ($year = (int) date('Y') + 1; $year >= 2015; $year--) {
    $sessionYears[] = (string) $year;
}
if ($sessionYear !== '' && !in_array($sessionYear, $sessionYears, true)) {
    array_unshift($sessionYears, $sessionYear);
}

$geoSelect = '';
$geoJoin = '';
if (tableExists($db, 'districts')) {
    $geoJoin .= ' LEFT JOIN districts ON s.district = districts.id';
    if (columnExists($db, 'districts', 'name')) {
        $geoSelect .= ', districts.name AS district_en';
    }
    if (columnExists($db, 'districts', 'bn_name')) {
        $geoSelect .= ', districts.bn_name AS district_bn';
    }
}
if (tableExists($db, 'upazilas')) {
    $geoJoin .= ' LEFT JOIN upazilas ON s.upazilla = upazilas.id';
    if (columnExists($db, 'upazilas', 'name')) {
        $geoSelect .= ', upazilas.name AS upazilla_en';
    }
    if (columnExists($db, 'upazilas', 'bn_name')) {
        $geoSelect .= ', upazilas.bn_name AS upazilla_bn';
    }
}

$student = [];
if ($id > 0) {
    $rows = fetchRows(
        $db,
        "SELECT s.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name{$geoSelect}
         FROM students_info s
         LEFT JOIN cls c ON s.cls = c.id
         LEFT JOIN sections sec ON s.sections = sec.id
         {$geoJoin}
         WHERE s.id = :id LIMIT 1",
        ['id' => $id]
    );
    $student = $rows[0] ?? [];
    if ($student !== [] && !$seeAllClasses && !classTeacherScopeAllows($scope, (int) ($student['cls'] ?? 0), (int) ($student['sections'] ?? 0))) {
        $student = [];
    }
}

$matches = [];
if ($loadList) {
    $sql = "SELECT s.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name{$geoSelect}
            FROM students_info s
            LEFT JOIN cls c ON s.cls = c.id
            LEFT JOIN sections sec ON s.sections = sec.id
            {$geoJoin}
            WHERE s.status = 1";
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
    if ($search !== '') {
        $sql .= ' AND (s.name_ban LIKE :q_name_ban OR s.name_eng LIKE :q_name_eng OR s.mobile LIKE :q_mobile OR s.cls_roll LIKE :q_roll OR s.admission_token LIKE :q_token)';
        $like = '%' . $search . '%';
        $params['q_name_ban'] = $like;
        $params['q_name_eng'] = $like;
        $params['q_mobile'] = $like;
        $params['q_roll'] = $like;
        $params['q_token'] = $like;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 200';
    $matches = fetchRows($db, $sql, $params);
}

$issueDate = certificateDisplayDate($lang);
$certificateStudents = [];
if ($loadList) {
    $certificateStudents = $matches;
} elseif ($student !== []) {
    $certificateStudents = [$student];
}

$pdfFile = $isSeatLevel
    ? 'seat-label'
    : ($isIdCard
        ? 'student-id-cards'
        : ('certificates-' . preg_replace('/[^A-Za-z0-9]+/', '', $type) . '-' . $lang));
if ($sessionYear !== '') {
    $pdfFile .= '-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear);
}
if ($classId > 0) {
    $pdfFile .= '-class' . $classId;
}
if ($sectionId > 0) {
    $pdfFile .= '-sec' . $sectionId;
}
if ($isSeatLevel && $examId > 0) {
    $pdfFile .= '-exam' . $examId;
}
if ($rollFilter !== '') {
    $pdfFile .= '-roll' . preg_replace('/[^A-Za-z0-9]+/', '', asciiDigits($rollFilter));
}
$pdfFile .= '.pdf';

if (($_GET['download'] ?? '') === 'pdf') {
    if ($certificateStudents === []) {
        $fallback = $isSeatLevel ? 'seat-level.php' : ($isIdCard ? 'id-cards.php' : 'certificates.php');
        header('Location: ' . $fallback, true, 302);
        exit;
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if ($isSeatLevel) {
            require_once dirname(__DIR__, 2) . '/shared/id-cards-pdf.php';
            outputSeatLevelCardsPdf($certificateStudents, $settings, $pdfFile, $examName, date('Y'));
        } elseif ($isIdCard) {
            require_once dirname(__DIR__, 2) . '/shared/id-cards-pdf.php';
            outputStudentIdCardsPdf(
                $certificateStudents,
                $settings,
                $pdfFile,
                schoolPlainAddress((string) ($settings['address'] ?? '')),
                publicStaffMobileDisplay((string) ($settings['mobile'] ?? '')),
                trim((string) ($settings['email'] ?? ''))
            );
        } else {
            require_once dirname(__DIR__, 2) . '/shared/certificates-pdf.php';
            outputCertificatesPdf(
                $certificateStudents,
                $settings,
                $type,
                $typeTitle,
                $issueDate,
                $pdfFile,
                $lang
            );
        }
    } catch (Throwable $exception) {
        error_log(($isSeatLevel ? 'Seat level' : ($isIdCard ? 'ID card' : 'Certificate')) . ' PDF failed: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF তৈরি করা যায়নি।';
    }
    exit;
}

$pdfQuery = ['download' => 'pdf', 'type' => $type];
if (!$usesIdCardUi) {
    $pdfQuery['lang'] = $lang;
}
if ($loadList) {
    $pdfQuery['load'] = '1';
    if ($sessionYear !== '') {
        $pdfQuery['session_year'] = $sessionYear;
    }
    if ($classId > 0) {
        $pdfQuery['cls'] = $classId;
    }
    if ($sectionId > 0) {
        $pdfQuery['sections'] = $sectionId;
    }
    if ($isSeatLevel && $examId > 0) {
        $pdfQuery['exam'] = $examId;
    }
    if ($rollFilter !== '') {
        $pdfQuery['cls_roll'] = $rollFilter;
    }
    if ($search !== '') {
        $pdfQuery['q'] = $search;
    }
}
$signedRef = trim((string) ($_GET['ref'] ?? ''));
if ($signedRef !== '') {
    $pdfQuery['ref'] = $signedRef;
}

if ($usesIdCardUi) {
    $pageStyles = idCardsUiCss();
    $pageScripts = [idCardsUiScripts()];
} else {
$certOuterMm = certificateOuterMarginMm();
$pageStyles = <<<CSS
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
@page {
  size: A4 portrait;
  margin: 0;
}
.certificate-sheet {
  width: 210mm;
  max-width: 210mm;
  min-height: 297mm;
  height: 297mm;
  margin: 0 auto 1.25rem;
  padding: {$certOuterMm}mm;
  box-sizing: border-box;
  background: #fff !important;
  color: #000 !important;
  font-family: "Hind Siliguri", "Noto Sans Bengali", "Segoe UI", sans-serif;
  font-weight: 500;
  border: 0;
  box-shadow: 0 12px 32px rgba(15, 23, 42, .12);
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
  page-break-inside: avoid;
  break-inside: avoid;
}
CSS
    . certificateBorderCss('html') . <<<CSS
.certificate-sheet, .certificate-sheet * {
  color: #000 !important;
}
.certificate-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 3mm;
}
.certificate-brand-side {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex-shrink: 0;
  width: 28mm;
}
.certificate-logo {
  height: 24mm;
  width: auto;
  max-width: 28mm;
  object-fit: contain;
}
.certificate-title-row {
  display: flex;
  align-items: center;
  margin: 0 0 6mm;
}
.certificate-title-row .certificate-qr {
  margin-top: 0;
}
.certificate-qr {
  width: 26mm;
  height: 26mm;
  padding: 1.5mm;
  border: 1px solid #1e3a5f;
  background: #fff;
  box-sizing: border-box;
  display: block;
}
.certificate-qr svg {
  width: 100%;
  height: 100%;
  display: block;
}
.certificate-id-wrap {
  display: flex;
  align-items: flex-start;
  justify-content: flex-end;
  flex-shrink: 0;
  width: 28mm;
}
.certificate-brand {
  text-align: center;
  flex: 1 1 auto;
  min-width: 0;
}
.certificate-brand .h3 {
  font-size: 20pt;
  font-weight: 700;
  line-height: 1.15;
  margin: 0 0 2mm;
  color: #000 !important;
  white-space: nowrap;
}
.certificate-head.is-english .certificate-brand .h3 {
  font-size: 18pt;
}
.certificate-meta {
  font-size: 11pt;
  font-weight: 600;
  line-height: 1.45;
}
.certificate-photo {
  width: 24mm;
  height: 30mm;
  object-fit: cover;
  border: 1px solid #1e3a5f;
}
.certificate-head.is-english .certificate-photo {
  transform: translateX(5mm);
}
.certificate-type {
  flex: 1 1 auto;
  text-align: center;
  margin: 0;
}
.certificate-type span {
  display: inline-block;
  border: 1.5px solid #000;
  padding: 2mm 7mm;
  font-size: 13pt;
  font-weight: 700;
}
.certificate-issued {
  text-align: right;
  font-size: 11pt;
  font-weight: 600;
  margin-bottom: 6mm;
}
.certificate-copy {
  flex: 0 0 auto;
  text-align: justify;
}
.certificate-body {
  font-size: 14pt;
  font-weight: 600;
  line-height: 1.95;
  text-align: justify;
  text-justify: inter-word;
  margin: 0 0 6mm;
}
.certificate-note {
  font-size: 12pt;
  font-weight: 600;
  text-align: justify;
  text-justify: inter-word;
  margin: 0;
}
.certificate-signs {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-top: 3em;
  padding-top: 0;
  font-size: 12pt;
  font-weight: 700;
}
.certificate-sign-head {
  margin-left: auto;
  text-align: center;
  max-width: 55%;
}
.certificate-sheet:last-child,
.certificate-sheet:last-of-type {
  margin-bottom: 0;
}
@media print {
  a[href]::after,
  a[href]::before {
    content: none !important;
  }
  html, body {
    background: #fff !important;
    margin: 0 !important;
    padding: 0 !important;
    height: auto !important;
    overflow: hidden !important;
  }
  body.layout-fixed {
    min-height: 0 !important;
    height: auto !important;
  }
  .app-wrapper,
  .app-main,
  .app-content,
  .container-fluid {
    display: block !important;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    min-height: 0 !important;
    height: auto !important;
    overflow: hidden !important;
  }
  .app-footer {
    display: none !important;
  }
  .certificate-sheet {
    width: 210mm !important;
    max-width: 210mm !important;
    min-height: 0 !important;
    height: auto !important;
    margin: 0 !important;
    padding: {$certOuterMm}mm !important;
    box-shadow: none;
    overflow: hidden !important;
    box-sizing: border-box !important;
    page-break-after: always !important;
    break-after: page !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
  }
  .certificate-sheet:last-of-type {
    page-break-after: auto !important;
    break-after: auto !important;
  }
  .cert-frame,
  .cert-ring-gold,
  .cert-ring-inner {
    min-height: 0 !important;
    height: auto !important;
    box-sizing: border-box;
  }
  .cert-frame {
    border: 0.7mm solid #1e3a5f !important;
  }
  .cert-ring-gold {
    border: 0.45mm solid #c4a35a !important;
  }
  .cert-ring-inner {
    border: 0.55mm double #1e3a5f !important;
  }
}
CSS;

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  cls?.addEventListener('change', async () => {
    sections.innerHTML = '<option value="">সকল শাখা</option>';
    if (!cls.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
    if (!Array.isArray(rows)) return;
    rows.forEach((row) => sections.add(new Option(row.section_name_ban, row.id)));
  });
});
JS];
} // end !usesIdCardUi styles/scripts

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <?php if ($isSeatLevel): ?>
                <h3 class="mb-0">পরীক্ষার সিট লেবেল / Seat Label</h3>
                <p class="text-secondary mb-0">A4 পেজে সিট লেবেল—শিরোনাম SEAT LABEL ও নিচে পরীক্ষার নাম।</p>
              <?php elseif ($isIdCard): ?>
                <h3 class="mb-0">আইডি কার্ড / Student ID cards</h3>
                <p class="text-secondary mb-0">সার্টিফিকেট পেজ থেকেই আইডি কার্ড তৈরি করুন। রোল ঐচ্ছিক।</p>
              <?php else: ?>
                <h3 class="mb-0">সার্টিফিকেট / Certificates</h3>
                <p class="text-secondary mb-0">ফিল্টার করলে মিল থাকা সকল শিক্ষার্থীর সার্টিফিকেট একসাথে তৈরি হবে। রোল ঐচ্ছিক।</p>
              <?php endif; ?>
            </div>
            <?php if ($certificateStudents !== []): ?>
              <?php if ($isSeatLevel): ?>
              <div class="id-card-actions">
                <button class="btn btn-primary" type="button" onclick="window.print()">সিট লেবেল প্রিন্ট</button>
                <a class="btn btn-outline-success" href="<?= h($certificateFormAction . '?' . http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i><span>PDF ডাউনলোড</span>
                </a>
              </div>
              <?php elseif ($isIdCard): ?>
              <div class="id-card-actions">
                <button class="btn btn-primary" type="button" id="idCardPrintFront">Print cards</button>
                <button class="btn btn-outline-primary" type="button" id="idCardPrintBack">Print back</button>
                <a class="btn btn-outline-success" href="<?= h($certificateFormAction . '?' . http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i><span>PDF ডাউনলোড</span>
                </a>
              </div>
              <?php else: ?>
              <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="button" onclick="window.print()">
                  <?= count($certificateStudents) > 1 ? 'সকল সার্টিফিকেট প্রিন্ট (' . count($certificateStudents) . ')' : 'Print certificate' ?>
                </button>
                <a class="btn btn-outline-success" href="<?= h($certificateFormAction . '?' . http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
                </a>
              </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" action="<?= h($certificateFormAction) ?>" class="card stat-card mb-3 no-print">
              <div class="card-body">
                <div class="row g-2 align-items-end">
                  <div class="col-md-<?= $isSeatLevel ? '3' : '4' ?>">
                    <label class="form-label" for="filterSession">শিক্ষাবর্ষ</label>
                    <select class="form-select" name="session_year" id="filterSession">
                      <?php foreach ($sessionYears as $year): ?>
                        <option value="<?= h($year) ?>" <?= $sessionYear === $year ? 'selected' : '' ?>><?= h(bnDigits($year)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php if ($isSeatLevel): ?>
                  <div class="col-md-3">
                    <label class="form-label" for="filterExam">পরীক্ষার নাম</label>
                    <select class="form-select" name="exam" id="filterExam" required>
                      <option value="">পরীক্ষা বাছাই করুন</option>
                      <?php foreach ($exams as $exam): ?>
                        <?php
                          $examOption = trim((string) ($exam['exam_name_bangla'] ?? ''));
                          if ($examOption === '') {
                              $examOption = trim((string) ($exam['exam_name_english'] ?? ''));
                          }
                        ?>
                        <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>><?= h($examOption) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php endif; ?>
                  <div class="col-md-<?= $isSeatLevel ? '3' : '4' ?>">
                    <label class="form-label" for="filterClass">শ্রেণী</label>
                    <select class="form-select" name="cls" id="filterClass">
                      <option value="">সকল শ্রেণী</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h($class['class_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-<?= $isSeatLevel ? '3' : '4' ?>">
                    <label class="form-label" for="filterSection">শাখা</label>
                    <select class="form-select" name="sections" id="filterSection">
                      <option value="">সকল শাখা</option>
                      <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h($section['section_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <input type="hidden" name="load" value="1">
                <?php if ($hasForcedCardType): ?>
                  <input type="hidden" name="type" value="<?= h($type) ?>">
                <?php endif; ?>
                <div class="mt-3">
                  <div class="d-flex gap-2 align-items-end">
                    <?php if (!$usesIdCardUi): ?>
                    <div class="flex-shrink-0" style="min-width: 150px;">
                      <label class="form-label" for="filterLang">ভাষা / Language</label>
                      <select class="form-select" name="lang" id="filterLang">
                        <option value="bn" <?= $lang === 'bn' ? 'selected' : '' ?>>বাংলা</option>
                        <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                      </select>
                    </div>
                    <?php endif; ?>
                    <?php if (!$hasForcedCardType): ?>
                    <div class="flex-shrink-0" style="min-width: 220px; flex: 1 1 30%;">
                      <label class="form-label" for="filterType">সার্টিফিকেটের ধরন</label>
                      <select class="form-select" name="type" id="filterType">
                        <?php foreach ($types as $key => $label): ?>
                          <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                      <label class="form-label" for="filterRoll">রোল <span class="text-secondary fw-normal">(ঐচ্ছিক)</span></label>
                      <input class="form-control" type="text" name="cls_roll" id="filterRoll" value="<?= h($rollFilter) ?>" placeholder="যেমন: ১, ৫, ৮-১২">
                    </div>
                    <div class="flex-grow-1">
                      <label class="form-label" for="filterSearch">Search <span class="text-secondary fw-normal">(Optional)</span></label>
                      <input class="form-control" type="text" name="q" id="filterSearch" value="<?= h($search) ?>" placeholder="Name, roll or mobile">
                    </div>
                    <div class="flex-shrink-0">
                      <label class="form-label d-none d-sm-block">&nbsp;</label>
                      <button class="btn btn-outline-primary px-4" type="submit">ফিল্টার করুন</button>
                    </div>
                  </div>
                  <div class="form-text">নির্দিষ্ট রোল কমা দিয়ে, রেঞ্জ হাইফেন দিয়ে। যেমন: ১, ৫, ৮-১২</div>
                </div>
              </div>
            </form>
            <?php if ($usesIdCardUi): ?>
              <?php renderStudentIdCardSheets(
                  $certificateStudents,
                  $settings,
                  $loadList || $certificateStudents !== [],
                  [
                      'mode' => $isSeatLevel ? 'seatlevel' : 'idcard',
                      'exam_name' => $isSeatLevel ? $examName : '',
                      'year' => $isSeatLevel ? date('Y') : '',
                  ]
              ); ?>
            <?php elseif ($loadList && $certificateStudents === []): ?>
              <div class="card stat-card"><div class="card-body text-secondary">এই ফিল্টারে কোনো শিক্ষার্থী নেই।</div></div>
            <?php elseif ($certificateStudents === []): ?>
              <div class="card stat-card"><div class="card-body text-secondary">ফিল্টার করুন—মিল থাকা সকল শিক্ষার্থীর সার্টিফিকেট একসাথে তৈরি হবে।</div></div>
            <?php else: ?>
              <?php foreach ($certificateStudents as $sheetStudent): ?>
                <?php
                  $sheetId = (int) ($sheetStudent['id'] ?? 0);
                  $sheetBody = certificateDocumentBody($sheetStudent, $type, $settings, $lang);
                  $studentName = $lang === 'en'
                    ? trim((string) ($sheetStudent['name_eng'] ?? $sheetStudent['name_ban'] ?? 'Student'))
                    : trim((string) ($sheetStudent['name_ban'] ?? $sheetStudent['name_eng'] ?? 'শিক্ষার্থী'));
                  $profileUrl = $sheetId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($sheetId)) : '';
                  $profileQr = $profileUrl !== '' ? qrCodeSvg($profileUrl, 'certificate-qr-svg', $studentName) : '';
                  $schoolNameDisplay = $lang === 'en'
                    ? mb_strtoupper($schoolHeading['name'], 'UTF-8')
                    : $schoolHeading['name'];
                ?>
                <div class="certificate-sheet" id="cert-<?= $sheetId ?>">
                  <?= certificateBorderOpenHtml('html') ?>
                  <div class="certificate-head<?= $lang === 'en' ? ' is-english' : '' ?>">
                    <div class="certificate-brand-side">
                      <?php if (!empty($settings['logo'])): ?>
                        <img class="certificate-logo" src="<?= h(publicSiteUrl((string) $settings['logo'])) ?>" alt="">
                      <?php endif; ?>
                    </div>
                    <div class="certificate-brand">
                      <div class="h3"><?= h($schoolNameDisplay) ?></div>
                      <div class="certificate-meta"><?= h($schoolHeading['address']) ?></div>
                      <div class="certificate-meta">EIIN: <?= h($settings['eiin']) ?> · <?= h($sheetCopy['mobile']) ?>: <?= h($settings['mobile']) ?></div>
                    </div>
                    <div class="certificate-id-wrap">
                      <img class="certificate-photo" src="<?= h(studentPhotoUrl($sheetStudent['student_pic'] ?? '')) ?>" alt="">
                    </div>
                  </div>
                  <div class="certificate-title-row">
                    <div class="certificate-brand-side">
                      <?php if ($profileQr !== ''): ?>
                        <a class="certificate-qr" href="<?= h($profileUrl) ?>" target="_blank" rel="noopener" title="<?= h($sheetCopy['qrTitle']) ?>">
                          <?= $profileQr ?>
                        </a>
                      <?php endif; ?>
                    </div>
                    <div class="certificate-type">
                      <span><?= h($typeTitle) ?></span>
                    </div>
                    <div class="certificate-id-wrap" aria-hidden="true"></div>
                  </div>
                  <div class="certificate-issued"><?= h($sheetCopy['date']) ?>: <?= h($issueDate) ?></div>
                  <div class="certificate-copy">
                    <?php foreach (preg_split("/\n\s*\n/", trim($sheetBody)) ?: [$sheetBody] as $para): ?>
                      <?php if (trim((string) $para) !== ''): ?>
                        <p class="certificate-body"><?= certificateBodyHtml(trim((string) $para)) ?></p>
                      <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!certificateSkipGenericNote($type, $lang)): ?>
                    <p class="certificate-note"><?= h($sheetCopy['note']) ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="certificate-signs">
                    <div><?= h($sheetCopy['date']) ?>: <?= h($issueDate) ?></div>
                    <div class="certificate-sign-head">
                      <div><?= h($sheetCopy['headTeacher']) ?></div>
                      <div><?= h($schoolNameDisplay) ?></div>
                    </div>
                  </div>
                  <?= certificateBorderCloseHtml('html') ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
