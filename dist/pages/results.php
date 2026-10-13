<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/results-csv.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$settings = schoolSettings($db);
$teacher = getAuthenticatedTeacher();
$teacherMobile = (string) ($teacher['teacher_mobile'] ?? '');
$seeAll = canViewAllMarksheets($teacher);
$basePath = '../';
$pageTitle = 'Mark Entry | School Admin';

$exams = schoolExams($db, true);
$assignedSubjects = $seeAll ? [] : teacherAssignedSubjects($db, $teacherMobile);
$classes = schoolClasses($db);
if (!$seeAll) {
    $classIds = [];
    foreach ($assignedSubjects as $row) {
        $classIds[(int) $row['class_id']] = true;
    }
    $classes = array_values(array_filter(
        $classes,
        static fn (array $class): bool => isset($classIds[(int) $class['id']])
    ));
}

$examId = (int) ($_GET['exam'] ?? 0);
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$sections = $classId > 0 ? schoolSections($db, $classId) : [];
if ($classId > 0 && $sectionId === 0 && $sections !== [] && classSubjects($db, $classId, 0) === []) {
    $sectionId = (int) $sections[0]['id'];
}
$subjects = ($classId > 0) ? classSubjects($db, $classId, $sectionId) : [];
if (!$seeAll) {
    $subjects = filterSubjectsForTeacher($subjects, $teacherMobile);
}

$selectedSubject = null;
foreach ($subjects as $subject) {
    if ((int) $subject['id'] === $subjectId) {
        $selectedSubject = $subject;
        break;
    }
}
if ($selectedSubject === null && count($subjects) === 1) {
    $selectedSubject = $subjects[0];
    $subjectId = (int) $selectedSubject['id'];
}

$students = [];
$markMap = [];
$submission = null;
$isFinal = false;
$isOwnerTeacher = $selectedSubject !== null && canEditClassSubject($selectedSubject, $teacher);
if ($classId > 0 && $sessionYear !== '' && $selectedSubject !== null && $examId > 0) {
    $submission = markSubmissionRow($db, $classId, $sectionId, $sessionYear, $examId, $subjectId);
    $isFinal = $submission !== null && (string) $submission['status'] === 'submitted';

    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.student_pic FROM students_info s'
        . studentsListJoinSql() . '
            WHERE s.status = 1 AND s.cls = :cls AND s.session_year = :session_year';
    $params = ['cls' => $classId, 'session_year' => $sessionYear];
    if ($sectionId > 0) {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = (string) $sectionId;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);
    // The teacher who owns this subject can always see their own draft work;
    // everyone else (head/admin viewers) only sees marks after Final Submit.
    $markMap = marksByStudentForSubject($db, $classId, $sectionId, $sessionYear, $examId, $subjectId, !$isOwnerTeacher);
}

$canEditMarks = $isOwnerTeacher && !$isFinal;
$canReopenSubmission = $seeAll && $isFinal;
$examName = '';
foreach ($exams as $exam) {
    if ((int) $exam['id'] === $examId) {
        $examName = trim((string) ($exam['exam_name_bangla'] ?? $exam['exam_name_english'] ?? ''));
        break;
    }
}
$className = '';
foreach ($classes as $class) {
    if ((int) $class['id'] === $classId) {
        $className = trim((string) ($class['class_name_ban'] ?: $class['class_name']));
        break;
    }
}
$sectionName = '';
foreach ($sections as $section) {
    if ((int) $section['id'] === $sectionId) {
        $sectionName = trim((string) ($section['section_name_ban'] ?: $section['section_name']));
        break;
    }
}
$printLogo = !empty($settings['logo']) ? publicSiteUrl((string) $settings['logo']) : '';
$printSchoolName = trim((string) ($settings['school_name_bn'] ?? ''));
$printAddress = schoolPlainAddress((string) ($settings['address'] ?? ''));
$printEiin = trim((string) ($settings['eiin'] ?? ''));
$printMobile = publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''));
$resultsQuery = [
    'exam' => $examId,
    'cls' => $classId,
    'sections' => $sectionId,
    'session_year' => $sessionYear,
    'subject_id' => $subjectId,
];
$pdfQuery = ['download' => 'pdf'] + $resultsQuery;
$pdfFile = 'marksheet-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear)
    . '-e' . $examId . '-c' . $classId . '-s' . $subjectId . '.pdf';
$csvFile = 'marks-' . preg_replace('/[^A-Za-z0-9]+/', '', $sessionYear)
    . '-e' . $examId . '-c' . $classId . '-s' . $subjectId . '.csv';

if (in_array((string) ($_GET['download'] ?? ''), ['csv', 'template'], true)) {
    if ($selectedSubject === null || $examId < 1 || $classId < 1 || $sessionYear === '' || $students === []) {
        redirectWithMessage(
            'results.php?' . http_build_query($resultsQuery),
            'warning',
            'CSV তৈরির জন্য পরীক্ষা, শ্রেণি, বিষয় নির্বাচন করে মার্কশিট লোড করুন।'
        );
        exit;
    }

    if (($_GET['download'] ?? '') === 'csv' && !$seeAll && !$isOwnerTeacher) {
        http_response_code(403);
        exit('এই বিষয়ের ফলাফল export করার অনুমতি নেই।');
    }

    $csvRows = [['roll', 'student_name', 'mcq', 'cq', 'practical', 'total', 'grade']];
    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $mark = $markMap['id:' . $studentId] ?? $markMap['roll:' . (string) $student['cls_roll']] ?? [];
        $mcq = formatMarkValue($mark['mcq'] ?? '');
        $cq = formatMarkValue($mark['cq'] ?? '');
        $practical = formatMarkValue($mark['practical'] ?? '');
        $hasMarks = $mcq !== '' || $cq !== '' || $practical !== '';
        $total = (float) $mcq + (float) $cq + (float) $practical;
        $fullMarks = (float) ($selectedSubject['full_marks'] ?? 100);
        $csvRows[] = [
            (string) $student['cls_roll'],
            (string) ($student['name_ban'] ?: $student['name_eng']),
            ($_GET['download'] ?? '') === 'template' ? '' : $mcq,
            ($_GET['download'] ?? '') === 'template' ? '' : $cq,
            ($_GET['download'] ?? '') === 'template' ? '' : $practical,
            ($_GET['download'] ?? '') === 'template' || !$hasMarks ? '' : (string) $total,
            ($_GET['download'] ?? '') === 'template' || !$hasMarks
                ? ''
                : letterGrade($total, $fullMarks > 0 ? $fullMarks : 100),
        ];
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . ($_GET['download'] ?? '') . '-' . $csvFile . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo encodeResultsCsv($csvRows);
    exit;
}

$pageStyles = <<<'CSS'
.results-print-heading { display: none; }
@media print {
  @page { margin: 12mm; }
  body { background: #fff !important; }
  .app-content-header, .results-filters, .results-flash, .results-edit-hint, .results-save { display: none !important; }
  .results-mark-sheet { border: 0 !important; box-shadow: none !important; }
  .results-mark-sheet .card-header { display: none !important; }
  .results-mark-sheet .card-body { padding: 0 !important; }
  .results-mark-sheet .table-responsive { overflow: visible !important; }
  .results-mark-sheet table { width: 100% !important; }
  .results-mark-sheet th, .results-mark-sheet td { color: #000 !important; background: #fff !important; }
  .results-mark-sheet tr { break-inside: avoid; }
  .results-print-heading { display: block !important; margin-bottom: 1rem; }
  .results-print-brand { display: flex; align-items: center; gap: 1rem; padding-bottom: .75rem; border-bottom: 1px solid #444; }
  .results-print-logo { width: 72px; height: 72px; object-fit: contain; }
  .results-print-school { flex: 1; text-align: center; }
  .results-print-school h2 { margin: 0 0 .2rem; font-size: 1.4rem; }
  .results-print-school p { margin: 0; }
  .results-print-title { margin-top: .75rem; text-align: center; }
  .results-print-title h3, .results-print-title p { margin: 0; }
  .results-print-title h3 { font-size: 1.15rem; }
  .results-mark-sheet .mark-input { display: none !important; }
  .results-mark-sheet .print-mark { display: inline !important; }
}
CSS;

if (($_GET['download'] ?? '') === 'pdf') {
    if ($selectedSubject === null || $examId < 1 || $classId < 1 || $sessionYear === '' || $students === []) {
        redirectWithMessage(
            'results.php?' . http_build_query($resultsQuery),
            'warning',
            'PDF তৈরির জন্য পরীক্ষা, শ্রেণি, বিষয় নির্বাচন করে মার্কশিট লোড করুন।'
        );
        exit;
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        require_once dirname(__DIR__, 2) . '/shared/results-pdf.php';
        outputResultsPdf(
            $students,
            $markMap,
            $selectedSubject,
            $settings,
            $examName,
            $className,
            $sectionName,
            $sessionYear,
            $pdfFile
        );
    } catch (Throwable $exception) {
        error_log('Results PDF failed: ' . $exception->getMessage());
        redirectWithMessage(
            'results.php?' . http_build_query($resultsQuery),
            'danger',
            'PDF তৈরি করা যায়নি।'
        );
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $examId = (int) ($_POST['exam'] ?? 0);
    $classId = (int) ($_POST['cls'] ?? 0);
    $sectionId = (int) ($_POST['sections'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $sessionYear = trim((string) ($_POST['session_year'] ?? ''));
    $q = http_build_query([
        'exam' => $examId,
        'cls' => $classId,
        'sections' => $sectionId,
        'session_year' => $sessionYear,
        'subject_id' => $subjectId,
    ]);
    try {
        if ($examId < 1 || $classId < 1 || $subjectId < 1 || $sessionYear === '') {
            throw new RuntimeException('Select exam, class, subject and session.');
        }
        $subject = classSubjectById($db, $subjectId);
        if ($subject === null) {
            throw new RuntimeException('Subject not found.');
        }
        $allowedSubjects = classSubjects($db, $classId, $sectionId);
        $allowedSubjectIds = array_map(static fn (array $row): int => (int) $row['id'], $allowedSubjects);
        if (!in_array($subjectId, $allowedSubjectIds, true)) {
            throw new RuntimeException('Subject not found in this class / section.');
        }

        if (isset($_POST['reopen_submission'])) {
            if (!$seeAll) {
                throw new RuntimeException('এই বিষয়ের submission reopen করার অনুমতি আপনার নেই।');
            }
            if (!isMarkSubmissionFinal($db, $classId, $sectionId, $sessionYear, $examId, $subjectId)) {
                throw new RuntimeException('এই বিষয়ের ফলাফল এখনো Final Submit করা হয়নি।');
            }
            reopenMarkSubmission($db, $classId, $sectionId, $sessionYear, $examId, $subjectId, $teacherMobile);
            redirectWithMessage(
                'results.php?' . $q,
                'success',
                'বিষয়টি পুনরায় সম্পাদনার জন্য খুলে দেওয়া হয়েছে। বিষয় শিক্ষক আবার নম্বর সম্পাদনা করতে পারবেন।'
            );
        }

        if (!canEditClassSubject($subject, $teacher)) {
            throw new RuntimeException('Only the assigned subject teacher can enter or change marks.');
        }

        if (isMarkSubmissionFinal($db, $classId, $sectionId, $sessionYear, $examId, $subjectId)) {
            throw new RuntimeException('এই বিষয়ের ফলাফল ইতিমধ্যে Final Submit করা হয়েছে; আর সম্পাদনা করা যাবে না।');
        }

        if (isset($_POST['final_submit'])) {
            finalizeMarkSubmission($db, $classId, $sectionId, $sessionYear, $examId, $subjectId, $teacherMobile);
            redirectWithMessage(
                'results.php?' . $q,
                'success',
                'ফলাফল Final Submit করা হয়েছে। এই বিষয়ের নম্বর এখন মূল ফলাফল তালিকায় যুক্ত হয়েছে এবং আর সম্পাদনা করা যাবে না।'
            );
        }

        $sql = 'SELECT id, cls_roll FROM students_info
                WHERE status = 1 AND cls = :cls AND session_year = :session_year';
        $params = ['cls' => $classId, 'session_year' => $sessionYear];
        if ($sectionId > 0) {
            $sql .= ' AND sections = :sections';
            $params['sections'] = (string) $sectionId;
        }
        $classStudents = fetchRows($db, $sql, $params);
        $studentRolls = [];
        $studentIdsByRoll = [];
        foreach ($classStudents as $row) {
            $studentId = (int) $row['id'];
            $roll = (string) $row['cls_roll'];
            $studentRolls[$studentId] = $roll;
            if (isset($studentIdsByRoll[$roll])) {
                throw new RuntimeException('এই শ্রেণি/শাখায় একই roll একাধিক শিক্ষার্থীর আছে; আগে roll ঠিক করুন।');
            }
            $studentIdsByRoll[$roll] = $studentId;
        }

        $isImport = isset($_POST['import_csv']);
        $importRows = [];
        if ($isImport) {
            $upload = $_FILES['results_csv'] ?? null;
            if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('CSV ফাইল নির্বাচন করুন।');
            }
            if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new RuntimeException('CSV ফাইল সর্বোচ্চ ২ MB হতে পারবে।');
            }
            $stream = fopen((string) $upload['tmp_name'], 'rb');
            if ($stream === false) {
                throw new RuntimeException('CSV ফাইলটি পড়া যাচ্ছে না।');
            }
            try {
                $requiredColumns = ['roll'];
                foreach (['mcq', 'cq', 'practical'] as $component) {
                    if ((int) ($subject['has_' . $component] ?? 0) === 1) {
                        $requiredColumns[] = $component;
                    }
                }
                $csvRows = readResultsCsv($stream, $requiredColumns);
            } finally {
                fclose($stream);
            }

            $seenRolls = [];
            $fullMarks = (float) ($subject['full_marks'] ?? 100);
            foreach ($csvRows as $csvRow) {
                $rowNumber = (int) $csvRow['line'];
                $values = $csvRow['values'];
                $roll = (string) ($values['roll'] ?? '');
                if ($roll === '' || !isset($studentIdsByRoll[$roll])) {
                    throw new RuntimeException('CSV-এর row ' . $rowNumber . '-এ roll এই শ্রেণি/শাখা/শিক্ষাবর্ষে পাওয়া যায়নি।');
                }
                if (isset($seenRolls[$roll])) {
                    throw new RuntimeException('CSV-এর row ' . $rowNumber . '-এ duplicate roll আছে।');
                }
                $seenRolls[$roll] = true;

                $marks = [];
                foreach (['mcq', 'cq', 'practical'] as $component) {
                    $rawMark = (string) ($values[$component] ?? '');
                    $enabled = (int) ($subject['has_' . $component] ?? 0) === 1;
                    if (!$enabled && $rawMark !== '') {
                        throw new RuntimeException('CSV-এর row ' . $rowNumber . '-এ এই বিষয়ের জন্য চালু নয় এমন ' . strtoupper($component) . ' নম্বর দেওয়া হয়েছে।');
                    }
                    if ($enabled && $rawMark !== '' && (!is_numeric($rawMark) || !is_finite((float) $rawMark) || (float) $rawMark < 0)) {
                        throw new RuntimeException('CSV-এর row ' . $rowNumber . '-এর ' . strtoupper($component) . ' নম্বর শূন্য বা তার বেশি numeric value হতে হবে।');
                    }
                    $marks[$component] = $rawMark === '' ? null : (float) $rawMark;
                }
                if ($marks['mcq'] === null && $marks['cq'] === null && $marks['practical'] === null) {
                    continue;
                }
                $totalMark = (float) ($marks['mcq'] ?? 0)
                    + (float) ($marks['cq'] ?? 0)
                    + (float) ($marks['practical'] ?? 0);
                if ($fullMarks > 0 && $totalMark > $fullMarks) {
                    throw new RuntimeException('CSV-এর row ' . $rowNumber . '-এর মোট নম্বর পূর্ণমান ' . $fullMarks . ' অতিক্রম করেছে।');
                }
                $studentId = $studentIdsByRoll[$roll];
                $importRows[$studentId] = ['roll' => $roll] + $marks;
            }
        }

        $mcqIn = $_POST['mcq'] ?? [];
        $cqIn = $_POST['cq'] ?? [];
        $practicalIn = $_POST['practical'] ?? [];
        if (!$isImport && (!is_array($mcqIn) || !is_array($cqIn) || !is_array($practicalIn))) {
            throw new RuntimeException('Invalid mark data.');
        }

        $saved = 0;
        $cleared = 0;
        $db->beginTransaction();
        $targetStudentRolls = $isImport
            ? array_intersect_key($studentRolls, $importRows)
            : $studentRolls;
        foreach ($targetStudentRolls as $studentId => $roll) {
            $ref = signedRecordRef((int) $studentId, 'student');
            $mcqRaw = $isImport
                ? (string) ($importRows[$studentId]['mcq'] ?? '')
                : trim((string) ($mcqIn[$ref] ?? ''));
            $cqRaw = $isImport
                ? (string) ($importRows[$studentId]['cq'] ?? '')
                : trim((string) ($cqIn[$ref] ?? ''));
            $practicalRaw = $isImport
                ? (string) ($importRows[$studentId]['practical'] ?? '')
                : trim((string) ($practicalIn[$ref] ?? ''));
            if ($mcqRaw === '' && $cqRaw === '' && $practicalRaw === '') {
                if (!$isImport) {
                    deleteStudentSubjectMark($db, $roll, $classId, $sectionId, $sessionYear, $examId, $subjectId);
                    $cleared++;
                }
                continue;
            }
            foreach (['MCQ' => $mcqRaw, 'CQ' => $cqRaw, 'practical' => $practicalRaw] as $label => $rawMark) {
              if ($rawMark !== '' && !is_numeric($rawMark)) {
                throw new RuntimeException($label . ' mark must be numeric.');
              }
            }
            $mcq = $mcqRaw === '' ? null : (float) $mcqRaw;
            $cq = $cqRaw === '' ? null : (float) $cqRaw;
            $practical = $practicalRaw === '' ? null : (float) $practicalRaw;
            foreach (['MCQ' => $mcq, 'CQ' => $cq, 'practical' => $practical] as $label => $mark) {
              if ($mark !== null && (!is_finite($mark) || $mark < 0)) {
                throw new RuntimeException($label . ' mark must be zero or greater.');
              }
            }
            $totalMark = (float) ($mcq ?? 0) + (float) ($cq ?? 0) + (float) ($practical ?? 0);
            $fullMarks = (float) ($subject['full_marks'] ?? 100);
            if ($fullMarks > 0 && $totalMark > $fullMarks) {
              $fullMarksLabel = rtrim(rtrim(number_format($fullMarks, 2, '.', ''), '0'), '.');
              throw new RuntimeException('The total mark cannot exceed ' . $fullMarksLabel . '.');
            }
            upsertStudentSubjectMark(
                $db,
                $studentId,
                $roll,
                $classId,
                $sectionId,
                $sessionYear,
                $examId,
                $subjectId,
                $mcq,
                $cq,
                $practical,
                $teacherMobile
            );
            $saved++;
        }
        $db->commit();
        redirectWithMessage(
            'results.php?' . $q,
            'success',
            ($isImport ? 'CSV import saved ' : 'Bulk marks saved for ')
                . $saved . ' student(s)'
                . ($isImport && count($csvRows) > $saved ? '; skipped ' . (count($csvRows) - $saved) . ' blank row(s).' : '')
                . (!$isImport && $cleared > 0 ? '; cleared ' . $cleared . '.' : '.')
        );
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        redirectWithMessage('results.php?' . $q, 'danger', userFacingError($exception));
    }
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  window.addEventListener('beforeprint', () => {
    document.querySelectorAll('.results-mark-sheet .mark-input').forEach((input) => {
      const printValue = input.nextElementSibling;
      if (printValue) printValue.textContent = input.value.trim() || '—';
    });
  });
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  const subject = document.getElementById('filterSubject');
  const reloadSubjects = async () => {
    if (!subject) return;
    const previous = subject.value;
    subject.innerHTML = '<option value="">Select</option>';
    if (!cls?.value) return;
    const sectionId = sections?.value || '0';
    const url = 'academic-api.php?type=subjects&for=marks&class_id=' + encodeURIComponent(cls.value)
      + '&section_id=' + encodeURIComponent(sectionId);
    try {
      const rows = await (await fetch(url)).json();
      if (!Array.isArray(rows)) return;
      rows.forEach((row) => {
        const label = row.subject_name_ban || row.subject_name;
        const option = new Option(label, row.id);
        if (String(previous) === String(row.id)) option.selected = true;
        subject.add(option);
      });
    } catch (error) {
      subject.innerHTML = '<option value="">Select</option>';
    }
  };
  cls?.addEventListener('change', async () => {
    if (sections) {
      sections.innerHTML = '<option value="0">Entire class</option>';
      if (cls.value) {
        try {
          const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
          if (Array.isArray(rows)) {
            rows.forEach((row) => sections.add(new Option(row.section_name_ban || row.section_name, row.id)));
            if (rows.length > 0) {
              sections.value = String(rows[0].id);
            }
          }
        } catch (error) {
          sections.innerHTML = '<option value="0">Entire class</option>';
        }
      }
    }
    await reloadSubjects();
  });
  sections?.addEventListener('change', reloadSubjects);
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">মার্ক এন্ট্রি / Mark Entry</h3>
            <p class="text-secondary mb-0">
              <?php if ($seeAll): ?>
                সব শ্রেণীর মার্কশিট দেখা যাবে। নম্বর এন্ট্রি বা পরিবর্তন কেবল সেই বিষয়ের বিষয় শিক্ষক করতে পারবেন। আপনি যদি কোনো বিষয়ের বিষয় শিক্ষক হন, সেই বিষয়গুলো সম্পাদনা করতে পারবেন।
              <?php else: ?>
                কেবল আপনার বিষয়গুলোর নম্বর এন্ট্রি ও পরিবর্তন করতে পারবেন।
              <?php endif; ?>
            </p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="results-flash"><?php include __DIR__ . '/../includes/flash.php'; ?></div>
            <?php if (!$seeAll && $assignedSubjects === []): ?>
              <div class="alert alert-warning">আপনার মোবাইল নম্বরে এখনো কোনো বিষয় শিক্ষক হিসেবে নির্ধারণ করা হয়নি। অন্য শিক্ষকের মার্কশিট দেখা যাবে না। প্রধান শিক্ষক Academic structure → Subjects থেকে আপনাকে বিষয় শিক্ষক হিসেবে যোগ করুন।</div>
            <?php endif; ?>
            <?php if ($selectedSubject && $examId > 0 && $students !== [] && ($isOwnerTeacher || ($seeAll && $submission !== null))): ?>
              <div class="card stat-card mb-3">
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div>
                    <?php if ($isFinal): ?>
                      <span class="badge text-bg-success mb-1"><i class="bi bi-check-circle me-1"></i>চূড়ান্তভাবে জমা দেওয়া হয়েছে (Final Submitted)</span>
                      <p class="small text-secondary mb-0">
                        <?= h((string) ($submission['submitted_by'] ?? '')) ?> কর্তৃক
                        <?= $submission['submitted_at'] ? h(date('d M Y, h:i A', strtotime((string) $submission['submitted_at']))) : '' ?>-এ জমা দেওয়া হয়েছে।
                        এই বিষয়ের নম্বর এখন মূল ফলাফল তালিকায় (Admin panel ও public পেইজে) প্রদর্শিত হচ্ছে এবং বিষয় শিক্ষক আর সম্পাদনা করতে পারবেন না।
                      </p>
                    <?php elseif ($isOwnerTeacher): ?>
                      <span class="badge text-bg-warning text-dark mb-1"><i class="bi bi-hourglass-split me-1"></i>Temporary / Draft অবস্থায় আছে</span>
                      <p class="small text-secondary mb-0">
                        এই নম্বর এখনো Admin panel বা public ফলাফল পেইজে প্রদর্শিত হচ্ছে না। "Final Submit" চাপার পরেই এটি মূল ফলাফল তালিকায় যুক্ত হবে এবং আর সম্পাদনা করা যাবে না।
                      </p>
                    <?php else: ?>
                      <span class="badge text-bg-warning text-dark mb-1"><i class="bi bi-hourglass-split me-1"></i>Temporary / Draft অবস্থায় আছে</span>
                      <p class="small text-secondary mb-0">
                        বিষয় শিক্ষক এই বিষয়ে Temporary ভাবে নম্বর Save করেছেন, কিন্তু এখনো Final Submit করেননি। তাই এটি এখনো Admin panel বা public পেইজে প্রদর্শিত হচ্ছে না।
                      </p>
                    <?php endif; ?>
                  </div>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($canEditMarks): ?>
                      <form method="post" onsubmit="return confirm('Final Submit করলে এই বিষয়ের নম্বর মূল ফলাফল তালিকায় যুক্ত হবে এবং আপনি আর সম্পাদনা করতে পারবেন না। আপনি কি নিশ্চিত?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="exam" value="<?= (int) $examId ?>">
                        <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                        <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                        <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                        <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
                        <button class="btn btn-success" type="submit" name="final_submit" value="1">
                          <i class="bi bi-send-check me-1"></i>Final Submit
                        </button>
                      </form>
                    <?php endif; ?>
                    <?php if ($canReopenSubmission): ?>
                      <form method="post" onsubmit="return confirm('বিষয়টি পুনরায় সম্পাদনার জন্য খুলে দিতে চান? এতে বিষয় শিক্ষক আবার নম্বর পরিবর্তন করতে পারবেন এবং Final Submit করার আগ পর্যন্ত এটি ফলাফল তালিকা থেকে লুকানো থাকবে।');">
                        <?= csrfField() ?>
                        <input type="hidden" name="exam" value="<?= (int) $examId ?>">
                        <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                        <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                        <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                        <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
                        <button class="btn btn-outline-danger" type="submit" name="reopen_submission" value="1">
                          <i class="bi bi-unlock me-1"></i>Unlock / পুনরায় খুলুন
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($selectedSubject && $examId > 0 && $students !== [] && ($seeAll || $isOwnerTeacher)): ?>
              <div class="card stat-card mb-3 results-filters">
                <div class="card-body d-flex flex-wrap align-items-end justify-content-between gap-3">
                  <div>
                    <h4 class="h6 mb-1">CSV থেকে নম্বর Import / Export</h4>
                    <p class="small text-secondary mb-0">
                      <?php if ($canEditMarks): ?>
                        প্রথমে template ডাউনলোড করুন; roll ও নম্বর পূরণ করে CSV ফাইল upload করুন। ফাঁকা mark-সহ row অপরিবর্তিত থাকবে।
                      <?php elseif ($isFinal): ?>
                        এই বিষয়ের ফলাফল Final Submit হয়ে গেছে; শুধু দেখা ও export করা যাবে, নতুন import করা যাবে না।
                      <?php else: ?>
                        এই ফলাফল দেখা ও export করা যাবে; import করতে বিষয়টির নির্ধারিত শিক্ষক হতে হবে।
                      <?php endif; ?>
                    </p>
                  </div>
                  <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary" href="<?= h('results.php?' . http_build_query(['download' => 'template'] + $resultsQuery)) ?>">
                      <i class="bi bi-file-earmark-arrow-down me-1"></i>CSV Template
                    </a>
                    <a class="btn btn-outline-success" href="<?= h('results.php?' . http_build_query(['download' => 'csv'] + $resultsQuery)) ?>">
                      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Results
                    </a>
                  </div>
                  <?php if ($canEditMarks): ?>
                  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-2 w-100">
                    <?= csrfField() ?>
                    <input type="hidden" name="exam" value="<?= (int) $examId ?>">
                    <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                    <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                    <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                    <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
                    <div class="flex-grow-1">
                      <label class="form-label" for="resultsCsv">CSV file (maximum 2 MB)</label>
                      <input class="form-control" type="file" id="resultsCsv" name="results_csv" accept=".csv,text/csv" required>
                    </div>
                    <button class="btn btn-primary" type="submit" name="import_csv" value="1">
                      <i class="bi bi-upload me-1"></i>Import CSV
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
            <form method="get" class="card stat-card mb-3 results-filters">
              <div class="card-body row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label">Exam</label>
                  <select class="form-select" name="exam" required>
                    <option value="">Select</option>
                    <?php foreach ($exams as $exam): ?>
                      <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>><?= h((string) $exam['exam_name_bangla']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2"><label class="form-label">Session</label><input class="form-control" name="session_year" value="<?= h($sessionYear) ?>"></div>
                <div class="col-md-2">
                  <label class="form-label">Class</label>
                  <select class="form-select" name="cls" id="filterClass" required>
                    <option value="">Select</option>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?= (int) $class['id'] ?>" <?= $classId === (int) $class['id'] ? 'selected' : '' ?>><?= h((string) ($class['class_name_ban'] ?: $class['class_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Section / group</label>
                  <select class="form-select" name="sections" id="filterSection">
                    <option value="0" <?= $sectionId === 0 ? 'selected' : '' ?>>Entire class</option>
                    <?php foreach ($sections as $section): ?>
                      <option value="<?= (int) $section['id'] ?>" <?= $sectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h((string) ($section['section_name_ban'] ?: $section['section_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Subject</label>
                  <select class="form-select" name="subject_id" id="filterSubject">
                    <option value="">Select</option>
                    <?php foreach ($subjects as $subject): ?>
                      <option value="<?= (int) $subject['id'] ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= h((string) ($subject['subject_name_ban'] ?: $subject['subject_name'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($classId > 0 && $subjects === []): ?>
                    <div class="form-text text-danger"><?= $seeAll ? 'এই শাখায় কোনো বিষয় নেই। একটি শাখা নির্বাচন করুন।' : 'এই শাখায় আপনার কোনো বিষয় নেই। অন্য শিক্ষকের বিষয় দেখা যাবে না।' ?></div>
                  <?php endif; ?>
                </div>
                <div class="col-md-1"><button class="btn btn-outline-primary w-100">Load</button></div>
              </div>
            </form>

            <?php if ($selectedSubject && $examId > 0 && $students !== []): ?>
              <?php if ($canEditMarks): ?>
              <form method="post" class="card stat-card results-mark-sheet">
                <?= csrfField() ?>
                <input type="hidden" name="exam" value="<?= (int) $examId ?>">
                <input type="hidden" name="cls" value="<?= (int) $classId ?>">
                <input type="hidden" name="sections" value="<?= (int) $sectionId ?>">
                <input type="hidden" name="session_year" value="<?= h($sessionYear) ?>">
                <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
              <?php else: ?>
              <div class="card stat-card results-mark-sheet">
              <?php endif; ?>
                <div class="card-header d-flex justify-content-between flex-wrap gap-2">
                  <div>
                    <strong><?= h((string) ($selectedSubject['subject_name_ban'] ?: $selectedSubject['subject_name'])) ?></strong>
                    <div class="small text-secondary">
                      Full <?= (int) ($selectedSubject['full_marks'] ?? 100) ?>
                      <?= (int) $selectedSubject['is_optional'] === 1 ? ' · Optional' : '' ?>
                      · <?= count($students) ?> students
                    </div>
                    <?php if ($canEditMarks): ?>
                      <div class="small text-success mt-1 results-edit-hint">আপনি এই বিষয়ের বিষয় শিক্ষক। নম্বর এন্ট্রি ও পরিবর্তন করতে পারবেন।</div>
                    <?php elseif ($isOwnerTeacher && $isFinal): ?>
                      <div class="small text-secondary mt-1">Final Submit হয়ে গেছে বলে এখন শুধু দেখা যাবে; সম্পাদনার জন্য Head/Admin-কে Unlock করতে বলুন।</div>
                    <?php else: ?>
                      <div class="small text-secondary mt-1">শুধু দেখা যাবে। নম্বর এন্ট্রি বা পরিবর্তন কেবল এই বিষয়ের বিষয় শিক্ষক করতে পারবেন।</div>
                    <?php endif; ?>
                  </div>
                  <div class="d-flex align-items-center gap-2 no-print">
                    <a class="btn btn-outline-success" href="<?= h('results.php?' . http_build_query($pdfQuery)) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF ডাউনলোড</a>
                    <button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>প্রিন্ট</button>
                    <?php if ($canEditMarks): ?>
                      <button class="btn btn-success results-save">Save all marks</button>
                    <?php else: ?>
                      <span class="badge text-bg-secondary align-self-center">View only</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="results-print-heading">
                  <div class="results-print-brand">
                    <?php if ($printLogo !== ''): ?>
                      <img class="results-print-logo" src="<?= h($printLogo) ?>" alt="">
                    <?php endif; ?>
                    <div class="results-print-school">
                      <h2><?= h($printSchoolName) ?></h2>
                      <?php if ($printAddress !== ''): ?><p><?= h($printAddress) ?></p><?php endif; ?>
                      <p>
                        <?php if ($printEiin !== ''): ?>EIIN: <?= h($printEiin) ?><?php endif; ?>
                        <?php if ($printEiin !== '' && $printMobile !== ''): ?> · <?php endif; ?>
                        <?php if ($printMobile !== ''): ?>মোবাইল: <?= h($printMobile) ?><?php endif; ?>
                      </p>
                    </div>
                  </div>
                  <div class="results-print-title">
                    <h3>মার্কশিট</h3>
                    <p><?= h($examName) ?> · <?= h($className) ?><?= $sectionName !== '' ? ' · ' . h($sectionName) : '' ?> · <?= h($sessionYear) ?></p>
                    <p><?= h((string) ($selectedSubject['subject_name_ban'] ?: $selectedSubject['subject_name'])) ?></p>
                  </div>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                      <thead>
                        <tr>
                          <th style="width:4rem">Roll</th>
                          <th>Student</th>
                          <?php if ((int) $selectedSubject['has_mcq'] === 1): ?><th style="width:7rem">MCQ</th><?php endif; ?>
                          <?php if ((int) $selectedSubject['has_cq'] === 1): ?><th style="width:7rem">CQ / Written</th><?php endif; ?>
                          <?php if ((int) $selectedSubject['has_practical'] === 1): ?><th style="width:7rem">Practical</th><?php endif; ?>
                          <th style="width:5rem">Total</th>
                          <th style="width:5rem">Grade</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($students as $student): ?>
                          <?php
                            $id = (int) $student['id'];
                            $ref = signedRecordRef($id, 'student');
                            $mark = $markMap['id:' . $id] ?? $markMap['roll:' . (string) $student['cls_roll']] ?? [];
                            $mcq = formatMarkValue($mark['mcq'] ?? '');
                            $cq = formatMarkValue($mark['cq'] ?? '');
                            $practical = formatMarkValue($mark['practical'] ?? '');
                            $hasMarks = $mcq !== '' || $cq !== '' || $practical !== '';
                            $total = (float) $mcq + (float) $cq + (float) $practical;
                            $full = (float) ($selectedSubject['full_marks'] ?? 100);
                          ?>
                          <tr>
                            <td><?= h((string) $student['cls_roll']) ?></td>
                            <td><?= h((string) $student['name_ban']) ?></td>
                            <?php if ((int) $selectedSubject['has_mcq'] === 1): ?>
                              <td>
                                <?php if ($canEditMarks): ?>
                                  <input class="form-control form-control-sm mark-input" name="mcq[<?= h($ref) ?>]" value="<?= h($mcq) ?>" inputmode="decimal"><span class="print-mark" style="display:none"><?= $mcq !== '' ? h($mcq) : '—' ?></span>
                                <?php else: ?>
                                  <?= $mcq !== '' ? h($mcq) : '—' ?>
                                <?php endif; ?>
                              </td>
                            <?php endif; ?>
                            <?php if ((int) $selectedSubject['has_cq'] === 1): ?>
                              <td>
                                <?php if ($canEditMarks): ?>
                                  <input class="form-control form-control-sm mark-input" name="cq[<?= h($ref) ?>]" value="<?= h($cq) ?>" inputmode="decimal"><span class="print-mark" style="display:none"><?= $cq !== '' ? h($cq) : '—' ?></span>
                                <?php else: ?>
                                  <?= $cq !== '' ? h($cq) : '—' ?>
                                <?php endif; ?>
                              </td>
                            <?php endif; ?>
                            <?php if ((int) $selectedSubject['has_practical'] === 1): ?>
                              <td>
                                <?php if ($canEditMarks): ?>
                                  <input class="form-control form-control-sm mark-input" name="practical[<?= h($ref) ?>]" value="<?= h($practical) ?>" inputmode="decimal"><span class="print-mark" style="display:none"><?= $practical !== '' ? h($practical) : '—' ?></span>
                                <?php else: ?>
                                  <?= $practical !== '' ? h($practical) : '—' ?>
                                <?php endif; ?>
                              </td>
                            <?php endif; ?>
                            <td class="mark-total"><?= $hasMarks ? h((string) (int) round($total)) : '—' ?></td>
                            <td><?= $hasMarks ? h(letterGrade($total, $full > 0 ? $full : 100)) : '—' ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <?php if ($canEditMarks): ?>
                <div class="card-footer text-end results-save"><button class="btn btn-success">Save all marks</button></div>
              </form>
                <?php else: ?>
              </div>
                <?php endif; ?>
            <?php elseif ($examId > 0 && $classId > 0 && $selectedSubject): ?>
              <div class="card stat-card"><div class="card-body text-secondary">No students found for this class, section and session.</div></div>
            <?php else: ?>
              <div class="card stat-card"><div class="card-body text-secondary"><?= $seeAll ? 'পরীক্ষা, শ্রেণি ও বিষয় বেছে মার্কশিট দেখুন। সম্পাদনা কেবল বিষয় শিক্ষক করতে পারবেন।' : 'পরীক্ষা, শ্রেণি ও আপনার বিষয় বেছে নম্বর এন্ট্রি করুন। অন্য শিক্ষকের মার্কশিট দেখা যাবে না।' ?></div></div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
