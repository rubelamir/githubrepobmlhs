<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/tabulation-sheet.php';
requireAuthentication('../examples/login-v2.php');
requirePanelModule('tabulation-sheet');

$db = getDatabaseConnection();
$settings = schoolSettings($db);
$basePath = '../';
$pageTitle = 'পরীক্ষার টেবুলেশন শীট | School Admin';
$exams = schoolExams($db, true);
$classes = schoolClasses($db, true);
$examId = (int) ($_GET['exam'] ?? 0);
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$loadSheet = (string) ($_GET['load'] ?? '') === '1';
$sections = $classId > 0 ? schoolSections($db, $classId, true) : [];
$subjects = [];
$students = [];
$sheetRows = [];
$examName = '';
$className = $classId > 0 ? classLabel($db, $classId) : '';
$sectionName = $sectionId > 0 ? sectionLabel($db, $sectionId) : '';
$printLogo = !empty($settings['logo']) ? publicSiteUrl((string) $settings['logo']) : '';

foreach ($exams as $exam) {
    if ((int) $exam['id'] === $examId) {
        $examName = trim((string) ($exam['exam_name_bangla'] ?? $exam['exam_name_english'] ?? ''));
        break;
    }
}

if ($classId > 0) {
    if ($sectionId > 0) {
        $subjects = classSubjects($db, $classId, $sectionId);
        foreach ($subjects as &$subject) {
            $subject['source_ids'] = [(int) $subject['section_id'] => (int) $subject['id']];
            $subject['optional_by_section'] = [(int) $subject['section_id'] => (int) $subject['is_optional']];
            $subject['pass_marks_by_section'] = [(int) $subject['section_id'] => (int) $subject['pass_marks']];
        }
        unset($subject);
    } else {
        $classWideSubjects = classSubjects($db, $classId, 0);
        $sectionSubjectRows = [];
        foreach ($sections as $section) {
            $sourceSectionId = (int) $section['id'];
            $sectionSubjectRows[$sourceSectionId] = classSubjects($db, $classId, $sourceSectionId);
        }
        $hasSectionSubjects = array_filter($sectionSubjectRows, static fn (array $rows): bool => $rows !== []) !== [];
        if (!$hasSectionSubjects) {
            foreach ($classWideSubjects as $subject) {
                $subject['source_ids'] = [0 => (int) $subject['id']];
                $subject['optional_by_section'] = [0 => (int) $subject['is_optional']];
                $subject['pass_marks_by_section'] = [0 => (int) $subject['pass_marks']];
                $subjects[] = $subject;
            }
        } else {
            foreach ($sectionSubjectRows as $sourceSectionId => $sectionSubjects) {
                if ($sectionSubjects === []) {
                    $sectionSubjects = $classWideSubjects;
                }
                foreach ($sectionSubjects as $subject) {
                    $name = trim((string) ($subject['subject_name_ban'] ?: $subject['subject_name']));
                    $code = trim((string) ($subject['subject_code'] ?? ''));
                    $key = $code !== '' ? 'code:' . mb_strtolower($code) : 'name:' . mb_strtolower($name);
                    if (!isset($subjects[$key])) {
                        $subject['source_ids'] = [];
                        $subjects[$key] = $subject;
                    }
                    $sourceId = (int) ($subject['section_id'] ?? 0);
                    $sourceMapKey = $sourceId > 0 ? $sourceSectionId : 0;
                    $subjects[$key]['source_ids'][$sourceMapKey] = (int) $subject['id'];
                    $subjects[$key]['optional_by_section'][$sourceMapKey] = (int) $subject['is_optional'];
                    $subjects[$key]['pass_marks_by_section'][$sourceMapKey] = (int) $subject['pass_marks'];
                    $subjects[$key]['has_mcq'] = max((int) $subjects[$key]['has_mcq'], (int) $subject['has_mcq']);
                    $subjects[$key]['has_cq'] = max((int) $subjects[$key]['has_cq'], (int) $subject['has_cq']);
                    $subjects[$key]['has_practical'] = max((int) $subjects[$key]['has_practical'], (int) $subject['has_practical']);
                    $subjects[$key]['is_optional'] = min((int) $subjects[$key]['is_optional'], (int) $subject['is_optional']);
                    $subjects[$key]['full_marks'] = max((int) $subjects[$key]['full_marks'], (int) $subject['full_marks']);
                    $subjects[$key]['pass_marks'] = max((int) $subjects[$key]['pass_marks'], (int) $subject['pass_marks']);
                }
            }
            $subjects = array_values($subjects);
        }
    }
}

$query = [
    'exam' => $examId,
    'cls' => $classId,
    'sections' => $sectionId,
    'session_year' => $sessionYear,
    'load' => '1',
];
$pdfQuery = ['download' => 'pdf'] + $query;
$pdfFile = 'tabulation-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear)
    . '-e' . $examId . '-c' . $classId . ($sectionId > 0 ? '-s' . $sectionId : '') . '.pdf';

if ($loadSheet && $examName !== '' && $classId > 0 && $sessionYear !== '' && $subjects !== []) {
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.sections
            FROM students_info s' . studentsListJoinSql() . '
            WHERE s.status = 1 AND s.cls = :cls AND s.session_year = :session_year';
    $params = ['cls' => $classId, 'session_year' => $sessionYear];
    if ($sectionId > 0) {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = (string) $sectionId;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);

    $subjectIds = [];
    foreach ($subjects as $subject) {
        foreach ($subject['source_ids'] ?? [(int) $subject['section_id'] => (int) $subject['id']] as $subjectId) {
            $subjectIds[(int) $subjectId] = true;
        }
    }
    $marks = [];
    if ($students !== [] && $subjectIds !== [] && tableExists($db, 'student_marks')) {
        $idNames = [];
        $markParams = [
            'class_id' => $classId,
            'exam_id' => $examId,
            'session_year' => $sessionYear,
        ];
        foreach (array_keys($subjectIds) as $index => $subjectId) {
            $key = 'subject_' . $index;
            $idNames[] = ':' . $key;
            $markParams[$key] = $subjectId;
        }
        $markSql = 'SELECT cls_roll, section_id, class_subject_id, mcq, cq, practical, session_year
                    FROM student_marks
                    WHERE class_id = :class_id AND exam_id = :exam_id
                      AND (session_year = :session_year OR session_year = \'\')
                      AND class_subject_id IN (' . implode(', ', $idNames) . ')';
        if ($sectionId > 0) {
            $markSql .= ' AND section_id IN (:section_id, 0)';
            $markParams['section_id'] = $sectionId;
        }
        if (tableExists($db, 'mark_submissions')) {
            // Exclude subjects whose marks are still Temporary/Draft (not yet
            // Final Submitted by the subject teacher) from the admin tabulation.
            $markSql .= ' AND NOT EXISTS (
                SELECT 1 FROM mark_submissions ms
                WHERE ms.class_id = student_marks.class_id
                  AND ms.section_id = student_marks.section_id
                  AND ms.exam_id = student_marks.exam_id
                  AND ms.class_subject_id = student_marks.class_subject_id
                  AND (ms.session_year = student_marks.session_year OR student_marks.session_year = \'\')
                  AND ms.status <> \'submitted\'
            )';
        }
        $markRows = fetchRows($db, $markSql, $markParams);
        foreach ($markRows as $mark) {
            $roll = (string) $mark['cls_roll'];
            $markSectionId = (int) $mark['section_id'];
            $subjectId = (int) $mark['class_subject_id'];
            $existing = $marks[$roll][$markSectionId][$subjectId] ?? null;
            if ($existing === null || (string) $mark['session_year'] === $sessionYear) {
                $marks[$roll][$markSectionId][$subjectId] = $mark;
            }
        }
    }
    $sheetRows = buildTabulationSheetRows($students, $subjects, $marks);
}

if (($_GET['download'] ?? '') === 'pdf') {
    if (!$loadSheet || $examName === '' || $className === '' || $subjects === [] || $sheetRows === []) {
        redirectWithMessage(
            'tabulation-sheet.php?' . http_build_query(array_diff_key($query, ['load' => true])),
            'warning',
            'PDF তৈরির আগে পরীক্ষা, শ্রেণি, শিক্ষাবর্ষ ও প্রয়োজনীয় তথ্য নির্বাচন করে টেবুলেশন শীট তৈরি করুন।'
        );
        exit;
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        outputTabulationSheetPdf(
            $subjects,
            $sheetRows,
            $settings,
            $examName,
            $className,
            $sectionName,
            $sessionYear,
            $pdfFile
        );
    } catch (Throwable $exception) {
        error_log('Tabulation sheet PDF failed: ' . $exception->getMessage());
        redirectWithMessage(
            'tabulation-sheet.php?' . http_build_query(array_diff_key($query, ['load' => true])),
            'danger',
            'PDF তৈরি করা যায়নি।'
        );
    }
    exit;
}

$pageStyles = <<<'CSS'
@font-face {
  font-family: "Noto Sans Bengali";
  src: url("../../bmlhs/fonts/ttf/NotoSansBengali-Regular.ttf") format("truetype");
  font-weight: 400;
  font-display: swap;
}
@font-face {
  font-family: "Noto Sans Bengali";
  src: url("../../bmlhs/fonts/ttf/NotoSansBengali-Bold.ttf") format("truetype");
  font-weight: 700;
  font-display: swap;
}
.tabulation-card { font-family: "Noto Sans Bengali", sans-serif; }
.tabulation-school { display: flex; align-items: center; gap: 1rem; padding: .75rem 1rem; border-bottom: 2px solid #1a4d3e; }
.tabulation-logo { width: 4rem; height: 4rem; object-fit: contain; }
.tabulation-brand { flex: 1; text-align: center; }
.tabulation-brand h2 { margin: 0; color: #174936; font-size: 1.4rem; font-weight: 700; }
.tabulation-brand p { margin: .1rem 0 0; color: #43584e; }
.tabulation-title { text-align: center; padding: .8rem; }
.tabulation-title h4 { margin: 0 0 .25rem; font-weight: 700; }
.tabulation-table { width: 100%; min-width: max-content; table-layout: fixed; font-size: 1rem; }
.tabulation-table thead th { text-align: center; vertical-align: middle; background: #e5ede9; color: #173b2d; white-space: nowrap; }
.tabulation-table col.mark-cell { width: 3.5rem; }
.tabulation-table .subject-label { display: block; width: 4rem; max-width: 100%; margin: 0 auto; white-space: normal; overflow-wrap: anywhere; }
.tabulation-table tbody td { vertical-align: middle; }
.tabulation-table .name-cell { box-sizing: border-box; width: 11rem; min-width: 11rem; max-width: 11rem; white-space: normal; overflow-wrap: anywhere; }
.tabulation-table .mark-cell { box-sizing: border-box; width: 3.5rem; min-width: 3.5rem; max-width: 3.5rem; text-align: center; white-space: nowrap; }
.tabulation-table .roll-cell { box-sizing: border-box; width: 3rem; min-width: 3rem; max-width: 3rem; text-align: center; white-space: nowrap; font-weight: 700; }
.tabulation-table .total-cell, .tabulation-table .rank-cell { box-sizing: border-box; width: 3.5rem; min-width: 3.5rem; max-width: 3.5rem; text-align: center; white-space: nowrap; }
.tabulation-table .result-cell { box-sizing: border-box; width: 5rem; min-width: 5rem; max-width: 5rem; text-align: center; white-space: nowrap; font-weight: 700; }
.tabulation-table .result-pass { color: #12613d; font-weight: 700; }
.tabulation-table .result-fail { color: #a12727; font-weight: 700; }
.tabulation-table .result-pending { color: #725314; font-weight: 700; }
.tabulation-table .mark-below-pass { color: #c62828; font-weight: 700; }
.tabulation-print-header { display: none; }
@media print {
  @page { size: legal landscape; margin: 8mm; }
  html, body { background: #fff !important; }
  .app-sidebar, .app-header, .app-footer, .app-content-header, .no-print { display: none !important; }
  .app-wrapper, .app-main, .app-content, .app-content > .container-fluid { display: block !important; width: 100% !important; max-width: none !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
  .tabulation-card { border: 0 !important; box-shadow: none !important; }
  .tabulation-card > .card-body { padding: 0 !important; }
  .tabulation-school { padding: 0 0 2mm; }
  .tabulation-logo { width: 16mm; height: 16mm; }
  .tabulation-brand h2 { font-size: 16pt; }
  .tabulation-brand p { font-size: 8pt; }
  .tabulation-title { padding: 2mm 0; }
  .tabulation-title h4 { font-size: 11pt; }
  .tabulation-title p { margin: 0; font-size: 8pt; }
  .tabulation-table { width: 100% !important; min-width: 0 !important; table-layout: fixed; font-size: 8.2pt; }
  .tabulation-card .table-responsive { overflow: visible !important; }
  .tabulation-table th, .tabulation-table td { padding: 1mm .6mm !important; color: #000 !important; background: #fff !important; }
  .tabulation-table .mark-below-pass { color: #c62828 !important; font-weight: 700 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .tabulation-table thead th { background: #e5ede9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .tabulation-table tbody tr { break-inside: avoid; }
  .tabulation-table col.mark-cell { width: 7mm; }
  .tabulation-table .subject-label { width: 14mm; max-width: 100%; white-space: normal; overflow-wrap: anywhere; }
  .tabulation-table .name-cell { width: 30mm; min-width: 30mm; max-width: 30mm; white-space: normal; overflow-wrap: anywhere; }
  .tabulation-table .mark-cell { box-sizing: border-box; width: 7mm; min-width: 7mm; max-width: 7mm; }
  .tabulation-table .roll-cell { width: 10mm; min-width: 10mm; max-width: 10mm; font-weight: 700; }
  .tabulation-table .total-cell, .tabulation-table .rank-cell { width: 9mm; min-width: 9mm; max-width: 9mm; }
  .tabulation-table .result-cell { width: 17mm; min-width: 17mm; max-width: 17mm; font-weight: 700; }
  .tabulation-note { font-size: 7pt; }
}
CSS;

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const classSelect = document.getElementById('tabulationClass');
  const sectionSelect = document.getElementById('tabulationSection');
  const sectionStatus = document.getElementById('tabulationSectionStatus');
  let requestController;

  classSelect?.addEventListener('change', async () => {
    if (!sectionSelect) return;
    requestController?.abort();
    const controller = new AbortController();
    requestController = controller;
    sectionSelect.replaceChildren(new Option('সকল শাখা', '0', true, true));
    sectionSelect.disabled = !classSelect.value;
    if (sectionStatus) sectionStatus.textContent = '';
    if (!classSelect.value) {
      sectionSelect.disabled = false;
      return;
    }

    const params = new URLSearchParams({
      type: 'sections',
      class_id: classSelect.value,
      active: '1'
    });
    try {
      const response = await fetch('academic-api.php?' + params.toString(), {
        signal: controller.signal
      });
      if (!response.ok) throw new Error('শাখার তালিকা লোড করা যায়নি।');
      const sections = await response.json();
      if (!Array.isArray(sections)) throw new Error('শাখার তালিকার তথ্য সঠিক নয়।');
      sections.forEach((section) => {
        const label = section.section_name_ban || section.section_name;
        if (label) sectionSelect.add(new Option(label, section.id));
      });
      if (sectionStatus) sectionStatus.textContent = sections.length ? '' : 'এই শ্রেণিতে কোনো সক্রিয় শাখা নেই।';
    } catch (error) {
      if (error.name !== 'AbortError' && sectionStatus) {
        sectionStatus.textContent = error.message || 'শাখার তালিকা লোড করা যায়নি।';
      }
    } finally {
      if (requestController === controller && !controller.signal.aborted) sectionSelect.disabled = false;
    }
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid">
            <h3 class="mb-0">পরীক্ষার টেবুলেশন শীট</h3>
            <p class="text-secondary mb-0">বিষয়ভিত্তিক নম্বর, ফলাফল ও মেধাক্রম একসাথে তৈরি, PDF ডাউনলোড ও Legal landscape-এ প্রিন্ট করুন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="no-print"><?php include __DIR__ . '/../includes/flash.php'; ?></div>
            <form method="get" class="card stat-card mb-3 no-print">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label">পরীক্ষা</label>
                  <select class="form-select" name="exam" required>
                    <option value="">নির্বাচন করুন</option>
                    <?php foreach ($exams as $exam): ?>
                      <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>><?= h((string) ($exam['exam_name_bangla'] ?? $exam['exam_name_english'] ?? '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">শিক্ষাবর্ষ</label>
                  <input class="form-control" name="session_year" maxlength="20" required value="<?= h($sessionYear) ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label">শ্রেণি</label>
                  <select class="form-select" name="cls" id="tabulationClass" required>
                    <option value="">নির্বাচন করুন</option>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">শাখা</label>
                  <select class="form-select" name="sections" id="tabulationSection">
                    <option value="0" <?= $sectionId === 0 ? 'selected' : '' ?>>সকল শাখা</option>
                    <?php foreach ($sections as $section): ?>
                      <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text text-danger" id="tabulationSectionStatus" role="status" aria-live="polite"></div>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" name="load" value="1">শীট তৈরি করুন</button></div>
              </div>
            </form>

            <?php if ($loadSheet): ?>
              <?php if ($sheetRows === []): ?>
                <div class="alert alert-warning">এই নির্বাচন অনুযায়ী শিক্ষার্থী বা সক্রিয় বিষয় পাওয়া যায়নি। পরীক্ষা, শ্রেণি, শিক্ষাবর্ষ এবং বিষয় তালিকা যাচাই করুন।</div>
              <?php else: ?>
                <div class="d-flex justify-content-end gap-2 mb-2 no-print">
                  <a class="btn btn-outline-success" href="<?= h('tabulation-sheet.php?' . http_build_query($pdfQuery)) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF ডাউনলোড (Legal Landscape)</a>
                  <button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>প্রিন্ট</button>
                </div>
                <div class="card stat-card tabulation-card">
                  <div class="card-body">
                    <header class="tabulation-school">
                      <?php if ($printLogo !== ''): ?><img class="tabulation-logo" src="<?= h($printLogo) ?>" alt=""><?php endif; ?>
                      <div class="tabulation-brand">
                        <h2><?= h((string) ($settings['school_name_bn'] ?? '')) ?></h2>
                        <?php $address = schoolPlainAddress((string) ($settings['address'] ?? '')); ?>
                        <?php if ($address !== ''): ?><p><?= h($address) ?></p><?php endif; ?>
                        <p>
                          <?php if (trim((string) ($settings['eiin'] ?? '')) !== ''): ?>EIIN: <?= h((string) $settings['eiin']) ?><?php endif; ?>
                          <?php if (trim((string) ($settings['eiin'] ?? '')) !== '' && trim((string) ($settings['mobile'] ?? '')) !== ''): ?> · <?php endif; ?>
                          <?php if (trim((string) ($settings['mobile'] ?? '')) !== ''): ?>মোবাইল: <?= h(publicStaffMobileDisplay((string) $settings['mobile'])) ?><?php endif; ?>
                        </p>
                      </div>
                    </header>
                    <div class="tabulation-title">
                      <h4>পরীক্ষার টেবুলেশন শীট</h4>
                      <p><?= h($examName) ?> · <?= h($className) ?><?= $sectionName !== '' ? ' · ' . h($sectionName) : '' ?> · শিক্ষাবর্ষ <?= h(bnDigits($sessionYear)) ?></p>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-bordered table-sm table-striped tabulation-table mb-0">
                        <colgroup>
                          <col class="roll-cell">
                          <col class="name-cell">
                          <?php foreach ($subjects as $subject): ?>
                            <?php if ((int) $subject['has_mcq'] === 1): ?><col class="mark-cell"><?php endif; ?>
                            <?php if ((int) $subject['has_cq'] === 1): ?><col class="mark-cell"><?php endif; ?>
                            <?php if ((int) $subject['has_practical'] === 1): ?><col class="mark-cell"><?php endif; ?>
                            <?php if (tabulationSubjectColumnCount($subject) === 1 && (int) $subject['has_mcq'] + (int) $subject['has_cq'] + (int) $subject['has_practical'] === 0): ?><col class="mark-cell"><?php endif; ?>
                          <?php endforeach; ?>
                          <col class="total-cell">
                          <col class="result-cell">
                          <col class="rank-cell">
                        </colgroup>
                        <thead>
                          <tr>
                            <th class="roll-cell" rowspan="2">রোল</th><th class="name-cell" rowspan="2">নাম</th>
                            <?php foreach ($subjects as $subject): ?>
                              <?php $subjectLabel = tabulationSubjectDisplayName((string) ($subject['subject_name_ban'] ?: $subject['subject_name'])); ?>
                              <th colspan="<?= tabulationSubjectColumnCount($subject) ?>">
                                <span class="subject-label">
                                  <?= h($subjectLabel) ?>
                                  <?= (int) $subject['is_optional'] === 1 ? ' *' : '' ?>
                                </span>
                              </th>
                            <?php endforeach; ?>
                            <th class="total-cell" rowspan="2">মোট</th><th class="result-cell" rowspan="2">ফলাফল</th><th class="rank-cell" rowspan="2">মেধাক্রম</th>
                          </tr>
                          <tr>
                            <?php foreach ($subjects as $subject): ?>
                              <?php if ((int) $subject['has_mcq'] === 1): ?><th class="mark-cell">MCQ</th><?php endif; ?>
                              <?php if ((int) $subject['has_cq'] === 1): ?><th class="mark-cell">CQ</th><?php endif; ?>
                              <?php if ((int) $subject['has_practical'] === 1): ?><th class="mark-cell">Pr</th><?php endif; ?>
                              <?php if (tabulationSubjectColumnCount($subject) === 1 && (int) $subject['has_mcq'] + (int) $subject['has_cq'] + (int) $subject['has_practical'] === 0): ?><th class="mark-cell">—</th><?php endif; ?>
                            <?php endforeach; ?>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($sheetRows as $row): ?>
                            <tr>
                              <td class="roll-cell"><?= h((string) $row['student']['cls_roll']) ?></td>
                              <td class="name-cell"><?= h((string) ($row['student']['name_ban'] ?: $row['student']['name_eng'])) ?></td>
                              <?php foreach ($subjects as $index => $subject): ?>
                                <?php $cell = $row['cells'][$index]; ?>
                                <?php if ((int) $subject['has_mcq'] === 1): ?><td class="mark-cell<?= $cell['mcq'] !== null && $cell['mcq'] < $cell['pass_marks'] ? ' mark-below-pass' : '' ?>"><?= $cell['mcq'] !== null ? h(formatMarkValue(number_format((float) $cell['mcq'], 2, '.', ''))) : '—' ?></td><?php endif; ?>
                                <?php if ((int) $subject['has_cq'] === 1): ?><td class="mark-cell<?= $cell['cq'] !== null && $cell['cq'] < $cell['pass_marks'] ? ' mark-below-pass' : '' ?>"><?= $cell['cq'] !== null ? h(formatMarkValue(number_format((float) $cell['cq'], 2, '.', ''))) : '—' ?></td><?php endif; ?>
                                <?php if ((int) $subject['has_practical'] === 1): ?><td class="mark-cell<?= $cell['practical'] !== null && $cell['practical'] < $cell['pass_marks'] ? ' mark-below-pass' : '' ?>"><?= $cell['practical'] !== null ? h(formatMarkValue(number_format((float) $cell['practical'], 2, '.', ''))) : '—' ?></td><?php endif; ?>
                                <?php if (tabulationSubjectColumnCount($subject) === 1 && (int) $subject['has_mcq'] + (int) $subject['has_cq'] + (int) $subject['has_practical'] === 0): ?><td class="mark-cell">—</td><?php endif; ?>
                              <?php endforeach; ?>
                              <?php
                                $resultCode = (string) ($row['result'] ?? '');
                                $resultClass = match ($resultCode) {
                                    'উত্তীর্ণ' => 'result-pass',
                                    'অকৃতকার্য' => 'result-fail',
                                    'অসম্পূর্ণ' => 'result-pending',
                                    default => 'result-pending',
                                };
                                $resultText = (string) ($row['display_result'] ?? $resultCode);
                              ?>
                              <td class="total-cell fw-semibold"><?= h(formatMarkValue(number_format((float) $row['total'], 2, '.', ''))) ?></td>
                              <td class="result-cell <?= $resultClass ?>"><?= $resultText !== '' ? h($resultText) : '' ?></td>
                              <td class="rank-cell"><?= $row['rank'] !== null ? h(bnDigits((string) $row['rank'])) : '—' ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <p class="small text-secondary mt-2 mb-0 tabulation-note">* ঐচ্ছিক বিষয়; অকৃতকার্য নির্ধারণে গণনা করা হয় না। কোনো আবশ্যিক বিষয়ের নম্বর অনুপস্থিত থাকলে ফলাফল অসম্পূর্ণ দেখাবে এবং মেধাক্রমে অন্তর্ভুক্ত হবে না।</p>
                  </div>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="card stat-card no-print"><div class="card-body text-secondary">পরীক্ষা, শিক্ষাবর্ষ ও শ্রেণি নির্বাচন করে টেবুলেশন শীট তৈরি করুন।</div></div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
