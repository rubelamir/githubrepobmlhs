<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Student Details List | School Admin';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/student-details-pdf.php';
$schoolSettings = schoolSettings($db);
$printLogo = schoolLogoUrl($schoolSettings);
if ($printLogo !== '' && preg_match('#^(?:https?:)?//#i', $printLogo) !== 1 && !str_starts_with($printLogo, '/')) {
    $printLogo = publicSiteUrl($printLogo);
}
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
$classLabel = 'All classes';
foreach ($classes as $class) {
    if ((int) $class['id'] === $classId) {
        $classLabel = (string) $class['class_name_ban'];
        break;
    }
}
$sectionLabel = 'All sections';
foreach ($sections as $section) {
    if ((int) $section['id'] === $sectionId) {
        $sectionLabel = (string) $section['section_name_ban'];
        break;
    }
}
$sessionYears = [];
for ($year = (int) date('Y') + 1; $year >= 2015; $year--) {
    $sessionYears[] = (string) $year;
}
if ($sessionYear !== '' && !in_array($sessionYear, $sessionYears, true)) {
    array_unshift($sessionYears, $sessionYear);
}
$students = [];
$filterGet = [
    'q' => $search,
    'session_year' => $sessionYear,
    'cls' => $classId > 0 ? (string) $classId : '',
    'sections' => $sectionId > 0 ? (string) $sectionId : '',
    'cls_roll' => $rollFilter,
];
$pdfQuery = ['download' => 'pdf'] + array_filter($filterGet, static fn (string $value): bool => $value !== '') + ['load' => '1'];
$pdfFilename = 'student-details-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear)
    . ($classId > 0 ? '-c' . $classId : '') . ($sectionId > 0 ? '-s' . $sectionId : '') . '.pdf';
if ($loadList) {
    $districtName = tableExists($db, 'districts')
        ? (columnExists($db, 'districts', 'bn_name')
            ? "COALESCE(NULLIF(districts.bn_name, ''), districts.name)"
            : 'districts.name')
        : 'NULL';
    $upazilaName = tableExists($db, 'upazilas')
        ? (columnExists($db, 'upazilas', 'bn_name')
            ? "COALESCE(NULLIF(upazilas.bn_name, ''), upazilas.name)"
            : 'upazilas.name')
        : 'NULL';
    $districtJoin = tableExists($db, 'districts') ? 'LEFT JOIN districts ON s.district = districts.id' : '';
    $upazilaJoin = tableExists($db, 'upazilas') ? 'LEFT JOIN upazilas ON s.upazilla = upazilas.id' : '';
    $optionalColumns = ['fprofession', 'mprofession', 'last_class', 'passing_year', 'scholarship_stipend_info', 'last_school_name', 'session_year', 'reg_no'];
    $optionalSelect = '';
    foreach ($optionalColumns as $optionalColumn) {
        $optionalSelect .= columnExists($db, 'students_info', $optionalColumn)
            ? "s.{$optionalColumn}, "
            : "'' AS {$optionalColumn}, ";
    }
    $sql = "SELECT s.id, s.name_eng, s.fname_eng, s.father_nid_no, s.mname_eng, s.mother_nid_no,
                   s.cls_roll, s.birth_date, s.birth_reg_no, s.gender, s.religion, s.mobile,
                   s.house_name, s.village, s.village_en, s.post_office, {$optionalSelect}
                   c.class_name_ban AS class_name, sec.section_name_ban AS section_name,
                   s.post_office_en, s.student_pic, {$districtName} AS district_name,
                   {$upazilaName} AS upazila_name
            FROM students_info s
            LEFT JOIN cls c ON s.cls = c.id
            LEFT JOIN sections sec ON s.sections = sec.id
            {$districtJoin}
            {$upazilaJoin}";
    [$where, $params] = studentsQueryFilters($filterGet);
    applyAssignedClassStudentScope($db, $where, $sessionYear, $classId, $sectionId, $panelTeacher);
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 400';
    $students = fetchRows($db, $sql, $params);
}

if (($_GET['download'] ?? '') === 'pdf') {
    if (!$loadList || $students === []) {
        redirectWithMessage(
            'student-details-list.php?' . http_build_query(array_diff_key($pdfQuery, ['download' => true])),
            'warning',
            'PDF তৈরির আগে ফিল্টার করে শিক্ষার্থীদের তালিকা দেখুন।'
        );
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    outputStudentDetailsListPdf(
        $students,
        $schoolSettings,
        $sessionYear,
        $classLabel,
        $sectionLabel,
        $pdfFilename
    );
    exit;
}

$pageStyles = <<<'CSS'
.student-details-print-head { display: none; }
.student-details-print-table { font-size: .9rem; min-width: 1000px; border-color: #dbe4df; }
.student-details-print-table thead th {
  background: #174936; color: #fff; font-weight: 600; font-size: .78rem;
  text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; vertical-align: middle;
}
.student-details-print-table td { vertical-align: top; }
.student-details-print-table tbody tr:hover > td { background: #e3f0ea !important; color: #12241c !important; --bs-table-bg-state: #e3f0ea; --bs-table-color-state: #12241c; }
.student-details-print-table tbody tr:hover .sd-name { color: #0b2a1e; }
.student-details-print-table tbody tr:hover .sd-line > span:first-child { color: #44564d; }
.student-details-print-table tbody tr:hover .sd-badge { background: #c9e3d8; color: #0f3d2b; }
.student-details-print-table tbody tr:hover .sd-badge-warm { background: #ffe7a8; color: #5c3d00; }
[data-bs-theme="dark"] .student-details-print-table tbody td { color: #e6efe9; }
[data-bs-theme="dark"] .sd-name { color: #bfe3d2; }
[data-bs-theme="dark"] .sd-line > span:first-child { color: #9fb3a9; }
[data-bs-theme="dark"] .student-details-print-table tbody tr:hover > td { background: #24382f !important; color: #f1f7f4 !important; --bs-table-bg-state: #24382f; --bs-table-color-state: #f1f7f4; }
[data-bs-theme="dark"] .student-details-print-table tbody tr:hover .sd-name { color: #d5f0e3; }
[data-bs-theme="dark"] .student-details-print-table tbody tr:hover .sd-line > span:first-child { color: #b4c7bd; }
[data-bs-theme="dark"] .student-details-print-table tbody tr:hover .sd-badge { background: #35574a; color: #e6f6ee; }
[data-bs-theme="dark"] .student-details-print-table tbody tr:hover .sd-badge-warm { background: #6b5316; color: #fff3cf; }
.sd-roll { display: inline-grid; place-items: center; min-width: 2rem; height: 2rem; padding: 0 .4rem; border-radius: 50%; background: #e5f1ec; color: #174936; font-weight: 700; }
.sd-photo { width: 52px; height: 64px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 0 0 1px #cfd9d4; }
.sd-name { font-weight: 700; color: #12372a; margin-bottom: .15rem; }
.sd-line { display: flex; gap: .35rem; line-height: 1.45; font-size: .82rem; }
.sd-line > span:first-child { flex: 0 0 auto; min-width: 4.4rem; color: #6a7a72; }
.sd-line > span:last-child { min-width: 0; overflow-wrap: anywhere; }
.sd-badge { display: inline-block; padding: .1rem .5rem; margin: 0 .25rem .25rem 0; border-radius: 999px; background: #e5f1ec; color: #174936; font-size: .75rem; font-weight: 600; }
.sd-badge.sd-badge-warm { background: #fff3d6; color: #7a5200; }
.sd-subhead { font-size: .72rem; font-weight: 700; text-transform: uppercase; color: #174936; margin-bottom: .2rem; }
.sd-empty { color: #9aa7a0; }
.student-details-address { font-size: .82rem; line-height: 1.5; }
@media print {
  @page { size: A4 portrait; margin: 12mm; }
  html, body { background: #fff !important; }
  .app-content-header, .student-details-filters, .no-print { display: none !important; }
  .app-wrapper, .app-main, .app-content, .app-content > .container-fluid {
    display: block !important;
    width: 100% !important;
    max-width: none !important;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }
  .student-details-print-area { display: block !important; width: 100% !important; }
  .student-details-print-head {
    display: flex !important;
    align-items: center;
    gap: 4mm;
    padding: 0 0 2mm;
    margin: 0 0 2mm;
    border-bottom: .5mm solid #315849;
    text-align: center;
  }
  .student-details-print-logo { width: 17mm; height: 17mm; object-fit: contain; }
  .student-details-print-brand { flex: 1; }
  .student-details-print-brand h1 { margin: 0; color: #174936; font-size: 15pt; }
  .student-details-print-brand p { margin: .4mm 0 0; color: #374b43; font-size: 7.5pt; }
  .student-details-print-title { margin: 2mm 0 3mm; text-align: center; }
  .student-details-print-title strong { font-size: 10pt; }
  .student-details-print-title span { display: block; font-size: 8pt; }
  .student-details-print-card { border: 0 !important; box-shadow: none !important; }
  .student-details-print-card .card-header { display: none !important; }
  .student-details-print-card .table-responsive { overflow: visible !important; }
  .student-details-print-table { width: 100% !important; table-layout: fixed; border-collapse: collapse !important; font-size: 8pt; }
  .student-details-print-table th, .student-details-print-table td {
    border: 1px solid #555 !important;
    padding: 1.3mm 1mm !important;
    color: #111 !important;
    background: #fff !important;
  }
  .student-details-print-table thead th {
    background: #e5ede9 !important;
    color: #173b2d !important;
    font-weight: 700 !important;
    text-align: center;
    vertical-align: middle;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .student-details-print-table thead { display: table-header-group; }
  .student-details-print-table tr { break-inside: avoid; }
  .student-details-print-table { min-width: 0 !important; }
  .student-details-print-table th:nth-child(1), .student-details-print-table td:nth-child(1) { width: 15mm; }
  .student-details-print-table th:nth-child(2), .student-details-print-table td:nth-child(2) { width: 38mm; }
  .student-details-print-table th:nth-child(3), .student-details-print-table td:nth-child(3) { width: 38mm; }
  .student-details-print-table th:nth-child(4), .student-details-print-table td:nth-child(4) { width: 52mm; }
  .student-details-print-table th:nth-child(5), .student-details-print-table td:nth-child(5) { width: auto; }
  .student-details-print-table thead th { font-size: 6.5pt !important; letter-spacing: 0; white-space: normal; }
  .student-details-address, .sd-line { white-space: normal !important; overflow-wrap: anywhere; word-break: break-word; font-size: 6.5pt !important; line-height: 1.3; }
  .sd-line > span:first-child { min-width: 11mm; color: #4d5d54 !important; }
  .sd-photo { width: 11mm; height: 14mm; box-shadow: none; border: 0; }
  .sd-roll { background: none !important; min-width: 0; height: auto; }
  .sd-name { font-size: 7.5pt; }
  .sd-badge { border: 1px solid #999; background: #fff !important; color: #111 !important; font-size: 6.5pt; padding: 0 1mm; }
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
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Student Details List</h3>
            <p class="text-secondary mb-0">Filter students and view their English details and address.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3 student-details-filters">
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
              <div class="card stat-card"><div class="card-body text-secondary">Apply filters to display matching student records.</div></div>
            <?php else: ?>
              <div class="d-flex justify-content-end mb-2 no-print">
                <button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print list</button>
                <a class="btn btn-primary" href="student-details-list.php?<?= h(http_build_query($pdfQuery)) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
              </div>
              <section class="student-details-print-area">
                <header class="student-details-print-head">
                  <?php if ($printLogo !== ''): ?><img class="student-details-print-logo" src="<?= h($printLogo) ?>" alt=""><?php endif; ?>
                  <div class="student-details-print-brand">
                    <h1><?= h((string) ($schoolSettings['school_name_bn'] ?? '')) ?></h1>
                    <?php $schoolAddress = schoolPlainAddress((string) ($schoolSettings['address'] ?? '')); ?>
                    <?php if ($schoolAddress !== ''): ?><p><?= h($schoolAddress) ?></p><?php endif; ?>
                    <p>
                      <?php if (trim((string) ($schoolSettings['eiin'] ?? '')) !== ''): ?>EIIN: <?= h((string) $schoolSettings['eiin']) ?><?php endif; ?>
                      <?php if (trim((string) ($schoolSettings['eiin'] ?? '')) !== '' && trim((string) ($schoolSettings['mobile'] ?? '')) !== ''): ?> · <?php endif; ?>
                      <?php if (trim((string) ($schoolSettings['mobile'] ?? '')) !== ''): ?>Mobile: <?= h((string) $schoolSettings['mobile']) ?><?php endif; ?>
                      <?php if (trim((string) ($schoolSettings['email'] ?? '')) !== ''): ?> · <?= h((string) $schoolSettings['email']) ?><?php endif; ?>
                    </p>
                  </div>
                </header>
                <div class="student-details-print-title">
                  <strong>Student Details List</strong>
                  <span>Session: <?= h($sessionYear) ?> · Class: <?= h($classLabel) ?> · Section: <?= h($sectionLabel) ?></span>
                </div>
              <div class="card stat-card student-details-print-card">
                <div class="card-header"><strong><?= count($students) ?></strong> records (maximum 400)</div>
                <div class="table-responsive">
                  <table class="table table-bordered table-sm align-middle mb-0 student-details-print-table">
                    <thead>
                      <tr><th class="text-center">Roll / Photo</th><th>Student</th><th>Parents</th><th>Academic</th><th>Address</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($students as $student): ?>
                        <?php
                          $v = static fn (string $key): string => trim((string) ($student[$key] ?? ''));
                          $row = static function (string $label, string $value): string {
                              return '<div class="sd-line"><span>' . h($label) . '</span><span>'
                                  . ($value !== '' ? h($value) : '<span class="sd-empty">—</span>') . '</span></div>';
                          };
                          $addressParts = [
                              'বাড়ি' => $v('house_name'),
                              'গ্রাম' => $v('village') !== '' ? $v('village') : $v('village_en'),
                              'ডাকঘর' => $v('post_office') !== '' ? $v('post_office') : $v('post_office_en'),
                              'উপজেলা' => $v('upazila_name'),
                              'জেলা' => $v('district_name'),
                          ];
                          $addressParts = array_filter($addressParts, static fn (string $value): bool => $value !== '');
                          $birthDate = $v('birth_date');
                          if ($birthDate !== '' && !str_starts_with($birthDate, '0000-00-00')) {
                              $parsedBirthDate = date_create($birthDate);
                              $birthDate = $parsedBirthDate instanceof DateTimeInterface
                                  ? $parsedBirthDate->format('d-m-Y')
                                  : $birthDate;
                          } else {
                              $birthDate = '';
                          }
                          $gender = match ($v('gender')) {
                              'male' => 'Male',
                              'female' => 'Female',
                              default => '',
                          };
                          $scholarshipLabel = match ($v('scholarship_stipend_info')) {
                              'scholarship' => 'বৃত্তি প্রাপ্ত',
                              'stipend' => 'উপবৃত্তি প্রাপ্ত',
                              default => '',
                          };
                          $passing = $v('passing_year');
                          $hasPreviousSchool = false;
                          foreach (['last_school_name', 'last_class', 'passing_year'] as $previousKey) {
                              if ($v($previousKey) !== '') { $hasPreviousSchool = true; break; }
                          }
                        ?>
                        <tr>
                          <td class="text-center">
                            <span class="sd-roll"><?= h($v('cls_roll')) ?></span>
                            <div class="mt-2"><img src="<?= h(studentPhotoUrl($student['student_pic'] ?? '')) ?>" class="rounded sd-photo" alt="<?= h($v('name_eng') !== '' ? $v('name_eng') : 'Student') ?>" loading="lazy"></div>
                          </td>
                          <td>
                            <div class="sd-name"><?= h($v('name_eng')) ?></div>
                            <?= $row('Birth Reg.', $v('birth_reg_no')) ?>
                            <?= $row('DOB', $birthDate) ?>
                            <?= $row('Gender', $gender) ?>
                            <?= $row('Religion', $v('religion')) ?>
                            <?= $row('Mobile', $v('mobile')) ?>
                          </td>
                          <td>
                            <?= $row('Father', $v('fname_eng')) ?>
                            <?= $row('NID', $v('father_nid_no')) ?>
                            <?= $row('Profession', $v('fprofession')) ?>
                            <hr class="my-1">
                            <?= $row('Mother', $v('mname_eng')) ?>
                            <?= $row('NID', $v('mother_nid_no')) ?>
                            <?= $row('Profession', $v('mprofession')) ?>
                          </td>
                          <td>
                            <span class="sd-badge"><?= h($v('class_name') !== '' ? $v('class_name') : '—') ?></span>
                            <span class="sd-badge"><?= h($v('section_name') !== '' ? $v('section_name') : '—') ?></span>
                            <?= $row('Session', $v('session_year')) ?>
                            <?= $row('Reg. No.', $v('reg_no')) ?>
                            <?php if ($scholarshipLabel !== ''): ?><span class="sd-badge sd-badge-warm"><?= h($scholarshipLabel) ?></span><?php endif; ?>
                            <?php if ($hasPreviousSchool): ?>
                            <hr class="my-2">
                            <div class="sd-subhead">Previous School</div>
                            <?= $row('School', $v('last_school_name')) ?>
                            <?= $row('Class', $v('last_class')) ?>
                            <?= $row('Year', $passing) ?>
                            <?php endif; ?>
                          </td>
                          <td class="student-details-address">
                            <?php foreach ($addressParts as $label => $value): ?>
                              <div><strong><?= h($label) ?>:</strong> <?= h($value) ?></div>
                            <?php endforeach; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if ($students === []): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">No students found.</td></tr>
                                              <?php endif; ?>
                                            </tbody>
                  </table>
                </div>
              </div>
              </section>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
