<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'Class Promotion | School Admin';
$teacher = getAuthenticatedTeacher();
$classes = schoolClasses($db);
$sourceMode = (string) ($_GET['source_mode'] ?? 'active'); // active | ssc_batch
if (!in_array($sourceMode, ['active', 'ssc_batch'], true)) {
    $sourceMode = 'active';
}
$fromClass = (int) ($_GET['from_class'] ?? 0);
$fromSection = trim((string) ($_GET['from_section'] ?? ''));
$fromSession = trim((string) ($_GET['from_session'] ?? date('Y')));
$toClass = (int) ($_GET['to_class'] ?? 0);
$toSection = trim((string) ($_GET['to_section'] ?? ''));
$toSession = trim((string) ($_GET['to_session'] ?? (string) ((int) date('Y') + 1)));
$destMode = (string) ($_GET['dest_mode'] ?? ($sourceMode === 'ssc_batch' ? 'ssc_return' : 'class'));
if ($sourceMode === 'ssc_batch') {
    $destMode = 'ssc_return';
} elseif (!in_array($destMode, ['class', 'ssc'], true)) {
    $destMode = 'class';
}
$sscBatchYear = trim((string) ($_GET['ssc_batch_year'] ?? date('Y')));
$sscBatchYears = sscCandidateBatchYears($db);
if ($sscBatchYear === '' && $sscBatchYears !== []) {
    $sscBatchYear = (string) $sscBatchYears[0];
}
$fromSections = $fromClass > 0 ? schoolSections($db, $fromClass) : [];
$toSections = $toClass > 0 ? schoolSections($db, $toClass) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $promotionBatch = date('YmdHis') . '-' . bin2hex(random_bytes(6));
    $action = (string) ($_POST['action'] ?? 'promote');
    $sourceMode = (string) ($_POST['source_mode'] ?? 'active');
    if (!in_array($sourceMode, ['active', 'ssc_batch'], true)) {
        $sourceMode = 'active';
    }
    $fromClass = (int) ($_POST['from_class'] ?? 0);
    $fromSection = (string) ($_POST['from_section'] ?? '');
    $fromSession = (string) ($_POST['from_session'] ?? '');
    $toClass = (int) ($_POST['to_class'] ?? 0);
    $toSection = (string) ($_POST['to_section'] ?? '');
    $toSession = trim((string) ($_POST['to_session'] ?? ''));
    $destMode = (string) ($_POST['dest_mode'] ?? 'class');
    $sscBatchYear = trim((string) ($_POST['ssc_batch_year'] ?? ''));
    $selected = $_POST['student_id'] ?? [];
    $rolls = $_POST['to_roll'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }
    if (!is_array($rolls)) {
        $rolls = [];
    }
    try {
        if ($selected === []) {
            throw new RuntimeException('শিক্ষার্থী নির্বাচন করুন।');
        }

        if ($action === 'mark_left') {
            if ($sourceMode !== 'active') {
                throw new RuntimeException('পড়াশুনা বন্ধ শুধু সক্রিয় শ্রেণির শিক্ষার্থীদের জন্য প্রযোজ্য।');
            }
            if ($fromClass < 1) {
                throw new RuntimeException('উৎস শ্রেণি নির্বাচন করুন।');
            }
            $count = 0;
            foreach ($selected as $studentRef) {
                $studentId = idFromSignedRecordRef((string) $studentRef, 'student');
                if (markStudentLeftSchool($db, $studentId, 'Marked left during promotion review')) {
                    $count++;
                }
            }
            redirectWithMessage(
                'promotions.php?source_mode=active&from_class=' . $fromClass . '&from_session=' . urlencode($fromSession)
                    . ($fromSection !== '' ? '&from_section=' . urlencode($fromSection) : ''),
                'success',
                $count . ' জনকে পড়াশুনা বন্ধ / Left হিসেবে চিহ্নিত করা হয়েছে। পুরনো রেকর্ড সংরক্ষিত আছে।'
            );
        }

        if ($sourceMode === 'ssc_batch' || $destMode === 'ssc_return') {
            if ($toClass < 1 || $toSession === '' || $toSection === '') {
                throw new RuntimeException('গন্তব্য শ্রেণি, শাখা ও নতুন শিক্ষাবর্ষ নির্বাচন করুন।');
            }
            $update = $db->prepare(
                'UPDATE students_info
                 SET status = :status, cls = :cls, sections = :sections, session_year = :session_year, cls_roll = :roll
                 WHERE id = :id AND status = :ssc_status'
            );
            $log = $db->prepare(
                'INSERT INTO student_promotions (student_id, from_class, from_section, from_session, from_roll, to_class, to_section, to_session, to_roll, remarks, promoted_by, promotion_batch)
                 VALUES (:student_id, :from_class, :from_section, :from_session, :from_roll, :to_class, :to_section, :to_session, :to_roll, :remarks, :promoted_by, :promotion_batch)'
            );
            $plannedRolls = [];
            $count = 0;
            $db->beginTransaction();
            try {
                foreach ($selected as $studentRef) {
                    $studentId = idFromSignedRecordRef((string) $studentRef, 'student');
                    $current = fetchRows(
                        $db,
                        'SELECT cls, sections, session_year, cls_roll, ssc_batch_year FROM students_info WHERE id = :id AND status = :status',
                        ['id' => $studentId, 'status' => studentStatusSscCandidate()]
                    );
                    if ($current === []) {
                        continue;
                    }
                    $newSection = $toSection;
                    $newRoll = trim((string) ($rolls[$studentRef] ?? $current[0]['cls_roll']));
                    if ($newRoll === '') {
                        throw new RuntimeException('প্রতিটি শিক্ষার্থীর জন্য নতুন রোল দিন।');
                    }
                    $rollKey = $toClass . '|' . $toSession . '|' . $newSection . '|' . $newRoll;
                    if (isset($plannedRolls[$rollKey])) {
                        throw new RuntimeException('একই গন্তব্যে রোল ' . $newRoll . ' একাধিকবার নির্বাচিত হয়েছে।');
                    }
                    if (activeStudentRollTaken($db, $toClass, $toSession, $newSection, $newRoll, $studentId)) {
                        throw new RuntimeException('রোল ' . $newRoll . ' গন্তব্য শ্রেণি/সেশনে ইতিমধ্যে ব্যবহৃত।');
                    }
                    $plannedRolls[$rollKey] = true;
                    $update->execute([
                        'status' => studentStatusAdmitted(),
                        'cls' => $toClass,
                        'sections' => $newSection,
                        'session_year' => $toSession,
                        'roll' => $newRoll,
                        'id' => $studentId,
                        'ssc_status' => studentStatusSscCandidate(),
                    ]);
                    if ($update->rowCount() < 1) {
                        continue;
                    }
                    $fromSessionLabel = trim((string) ($current[0]['ssc_batch_year'] ?? '')) ?: (string) $current[0]['session_year'];
                    $log->execute([
                        'student_id' => $studentId,
                        'from_class' => $current[0]['cls'],
                        'from_section' => $current[0]['sections'],
                        'from_session' => $fromSessionLabel,
                        'from_roll' => $current[0]['cls_roll'],
                        'to_class' => $toClass,
                        'to_section' => $newSection,
                        'to_session' => $toSession,
                        'to_roll' => $newRoll,
                        'remarks' => 'Returned to Class 10 from SSC batch' . ($fromSessionLabel !== '' ? ' ' . $fromSessionLabel : ''),
                        'promoted_by' => (string) ($teacher['teacher_mobile'] ?? ''),
                        'promotion_batch' => $promotionBatch,
                    ]);
                    recordStudentClassPromotionEnrollments(
                        $db,
                        [
                            'student_id' => $studentId,
                            'cls' => $current[0]['cls'],
                            'sections' => $current[0]['sections'],
                            'session_year' => $current[0]['session_year'],
                            'cls_roll' => $current[0]['cls_roll'],
                        ],
                        [
                            'session_year' => $toSession,
                            'cls' => $toClass,
                            'sections' => $newSection,
                            'cls_roll' => $newRoll,
                        ],
                        'ssc_return'
                    );
                    $count++;
                }
                $db->commit();
            } catch (Throwable $inner) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $inner;
            }
            redirectWithMessage(
                'promotions.php?source_mode=active&from_class=' . $toClass . '&from_session=' . rawurlencode($toSession)
                    . ($toSection !== '' ? '&from_section=' . rawurlencode($toSection) : ''),
                'success',
                $count . ' জন শিক্ষার্থী পরীক্ষার্থী ব্যাচ থেকে ১০ম শ্রেণিতে ফেরত এসেছে।'
            );
        }

        if ($fromClass < 1) {
            throw new RuntimeException('উৎস শ্রেণি নির্বাচন করুন।');
        }

        if ($destMode === 'ssc') {
            if ($sscBatchYear === '') {
                throw new RuntimeException('পরীক্ষার্থী ব্যাচের সাল দিন।');
            }
            if (!columnExists($db, 'students_info', 'ssc_batch_year')) {
                throw new RuntimeException('SSC ব্যাচ ফিল্ড এখনো তৈরি হয়নি। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
            }
            $update = $db->prepare(
                'UPDATE students_info
                 SET status = :status, ssc_batch_year = :batch, ssc_passing_year = :passing
                 WHERE id = :id AND status = :admitted'
            );
            $log = $db->prepare(
                'INSERT INTO student_promotions (student_id, from_class, from_section, from_session, from_roll, to_class, to_section, to_session, to_roll, remarks, promoted_by, promotion_batch)
                 VALUES (:student_id, :from_class, :from_section, :from_session, :from_roll, :to_class, :to_section, :to_session, :to_roll, :remarks, :promoted_by, :promotion_batch)'
            );
            $count = 0;
            foreach ($selected as $studentRef) {
                $studentId = idFromSignedRecordRef((string) $studentRef, 'student');
                $current = fetchRows(
                    $db,
                    'SELECT cls, sections, session_year, cls_roll FROM students_info WHERE id = :id AND status = :status',
                    ['id' => $studentId, 'status' => studentStatusAdmitted()]
                );
                if ($current === []) {
                    continue;
                }
                $update->execute([
                    'status' => studentStatusSscCandidate(),
                    'batch' => $sscBatchYear,
                    'passing' => $sscBatchYear,
                    'id' => $studentId,
                    'admitted' => studentStatusAdmitted(),
                ]);
                if ($update->rowCount() < 1) {
                    continue;
                }
                $log->execute([
                    'student_id' => $studentId,
                    'from_class' => $current[0]['cls'],
                    'from_section' => $current[0]['sections'],
                    'from_session' => $current[0]['session_year'],
                    'from_roll' => $current[0]['cls_roll'],
                    'to_class' => $current[0]['cls'],
                    'to_section' => $current[0]['sections'],
                    'to_session' => $sscBatchYear,
                    'to_roll' => $current[0]['cls_roll'],
                    'remarks' => 'SSC candidate batch ' . $sscBatchYear,
                    'promoted_by' => (string) ($teacher['teacher_mobile'] ?? ''),
                    'promotion_batch' => $promotionBatch,
                ]);
                recordStudentClassPromotionEnrollments(
                    $db,
                    [
                        'student_id' => $studentId,
                        'cls' => $current[0]['cls'],
                        'sections' => $current[0]['sections'],
                        'session_year' => $current[0]['session_year'],
                        'cls_roll' => $current[0]['cls_roll'],
                    ],
                    [],
                    'ssc'
                );
                $count++;
            }
            redirectWithMessage(
                'promotions.php?source_mode=active&from_class=' . (int) $fromClass . '&from_section=' . rawurlencode($fromSection) . '&from_session=' . rawurlencode($fromSession),
                'success',
                $count . ' জন শিক্ষার্থী পরীক্ষার্থী ব্যাচ-' . $sscBatchYear . '-এ স্থানান্তরিত হয়েছে।'
            );
        }

        if ($toClass < 1 || $toSession === '' || $toSection === '') {
            throw new RuntimeException('গন্তব্য শ্রেণি, শাখা ও শিক্ষাবর্ষ নির্বাচন করুন।');
        }
        $update = $db->prepare('UPDATE students_info SET cls = :cls, sections = :sections, session_year = :session_year, cls_roll = :roll WHERE id = :id AND status = :status');
        $log = $db->prepare(
            'INSERT INTO student_promotions (student_id, from_class, from_section, from_session, from_roll, to_class, to_section, to_session, to_roll, remarks, promoted_by, promotion_batch)
             VALUES (:student_id, :from_class, :from_section, :from_session, :from_roll, :to_class, :to_section, :to_session, :to_roll, :remarks, :promoted_by, :promotion_batch)'
        );
        $plannedRolls = [];
        $count = 0;
        $db->beginTransaction();
        try {
            foreach ($selected as $studentRef) {
                $studentId = idFromSignedRecordRef((string) $studentRef, 'student');
                $current = fetchRows(
                    $db,
                    'SELECT cls, sections, session_year, cls_roll FROM students_info WHERE id = :id AND status = :status',
                    ['id' => $studentId, 'status' => studentStatusAdmitted()]
                );
                if ($current === []) {
                    continue;
                }
                $newSection = $toSection;
                $newRoll = trim((string) ($rolls[$studentRef] ?? $current[0]['cls_roll']));
                if ($newRoll === '') {
                    throw new RuntimeException('প্রতিটি শিক্ষার্থীর জন্য নতুন রোল দিন।');
                }
                $rollKey = $toClass . '|' . $toSession . '|' . $newSection . '|' . $newRoll;
                if (isset($plannedRolls[$rollKey])) {
                    throw new RuntimeException('একই গন্তব্যে রোল ' . $newRoll . ' একাধিকবার নির্বাচিত হয়েছে।');
                }
                assertActiveStudentRollAvailable($db, $toClass, $toSession, $newSection, $newRoll, $studentId);
                $plannedRolls[$rollKey] = true;
                $update->execute([
                    'cls' => $toClass,
                    'sections' => $newSection,
                    'session_year' => $toSession,
                    'roll' => $newRoll,
                    'id' => $studentId,
                    'status' => studentStatusAdmitted(),
                ]);
                if ($update->rowCount() < 1) {
                    continue;
                }
                $log->execute([
                    'student_id' => $studentId,
                    'from_class' => $current[0]['cls'],
                    'from_section' => $current[0]['sections'],
                    'from_session' => $current[0]['session_year'],
                    'from_roll' => $current[0]['cls_roll'],
                    'to_class' => $toClass,
                    'to_section' => $newSection,
                    'to_session' => $toSession,
                    'to_roll' => $newRoll,
                    'remarks' => (string) ($_POST['remarks'] ?? 'Promoted'),
                    'promoted_by' => (string) ($teacher['teacher_mobile'] ?? ''),
                    'promotion_batch' => $promotionBatch,
                ]);
                recordStudentClassPromotionEnrollments(
                    $db,
                    [
                        'student_id' => $studentId,
                        'cls' => $current[0]['cls'],
                        'sections' => $current[0]['sections'],
                        'session_year' => $current[0]['session_year'],
                        'cls_roll' => $current[0]['cls_roll'],
                    ],
                    [
                        'session_year' => $toSession,
                        'cls' => $toClass,
                        'sections' => $newSection,
                        'cls_roll' => $newRoll,
                    ],
                    'class'
                );
                $count++;
            }
            $db->commit();
        } catch (Throwable $inner) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $inner;
        }
        writeAuditLog($db, 'promote', 'student', null, $count . ' students promoted to class ' . $toClass . ', session ' . $toSession);
        redirectWithMessage('promotions.php?source_mode=active&from_class=' . $toClass . '&from_session=' . urlencode($toSession), 'success', $count . ' student(s) promoted.');
    } catch (Throwable $exception) {
        redirectWithMessage('promotions.php?source_mode=' . rawurlencode($sourceMode), 'danger', userFacingError($exception));
    }
}

$students = [];
if ($sourceMode === 'ssc_batch') {
    if ($sscBatchYear !== '' && columnExists($db, 'students_info', 'ssc_batch_year')) {
        $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.session_year, s.ssc_batch_year, s.student_pic, s.cls, s.sections,
                       c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
                FROM students_info s'
            . studentsListJoinSql() . ' WHERE s.status = :status AND s.ssc_batch_year = :batch';
        $params = ['status' => studentStatusSscCandidate(), 'batch' => $sscBatchYear];
        if ($fromClass > 0) {
            $sql .= ' AND s.cls = :cls';
            $params['cls'] = $fromClass;
        }
        if ($fromSection !== '') {
            $sql .= ' AND s.sections = :sections';
            $params['sections'] = $fromSection;
        }
        $sql .= ' ORDER BY ' . studentsListOrderSql();
        $students = fetchRows($db, $sql, $params);
        if ($toClass < 1 && $students !== []) {
            $toClass = (int) ($students[0]['cls'] ?? 0);
            $toSections = $toClass > 0 ? schoolSections($db, $toClass) : [];
        }
    }
} elseif ($fromClass > 0) {
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.session_year, s.student_pic, s.cls, s.sections,
                   c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
            FROM students_info s'
        . studentsListJoinSql() . ' WHERE s.status = :status AND s.cls = :cls AND s.session_year = :session_year';
    $params = ['status' => studentStatusAdmitted(), 'cls' => $fromClass, 'session_year' => $fromSession];
    if ($fromSection !== '') {
        $sql .= ' AND s.sections = :sections';
        $params['sections'] = $fromSection;
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql();
    $students = fetchRows($db, $sql, $params);
}

$destOccupied = $destMode === 'class' && $toClass > 0 && $toSession !== ''
    ? countActiveStudentsInClass($db, $toClass, $toSession, $toSection)
    : 0;
$returnOccupied = $sourceMode === 'ssc_batch' && $toClass > 0 && $toSession !== ''
    ? countActiveStudentsInClass($db, $toClass, $toSession, $toSection)
    : 0;

$history = [];
$latestPromotion = fetchRows(
    $db,
    'SELECT id, promotion_batch, created_at FROM student_promotions ORDER BY id DESC LIMIT 1'
);
if ($latestPromotion !== []) {
    $latestBatch = trim((string) ($latestPromotion[0]['promotion_batch'] ?? ''));
    // Rows saved before promotion_batch existed are grouped by their save timestamp.
    $history = fetchRows(
        $db,
        'SELECT p.*, s.name_ban
         FROM student_promotions p
         LEFT JOIN students_info s ON s.id = p.student_id
         WHERE ' . ($latestBatch !== '' ? 'p.promotion_batch = :batch' : 'p.promotion_batch IS NULL AND p.created_at = :batch') . '
         ORDER BY p.id ASC',
        ['batch' => $latestBatch !== '' ? $latestBatch : (string) $latestPromotion[0]['created_at']]
    );
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const bind = async (classSelect, sectionSelect, emptyLabel = 'All / Select') => {
    classSelect?.addEventListener('change', async () => {
      sectionSelect.innerHTML = '<option value="">' + emptyLabel + '</option>';
      if (!classSelect.value) return;
      const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(classSelect.value))).json();
      rows.forEach((row) => sectionSelect.add(new Option(row.section_name_ban, row.id)));
    });
  };
  bind(document.getElementById('fromClass'), document.getElementById('fromSection'), 'All');
  bind(document.getElementById('toClass'), document.getElementById('toSection'), 'শাখা বাছাই করুন');
  document.getElementById('checkAll')?.addEventListener('change', (event) => {
    document.querySelectorAll('input[name="student_id[]"]').forEach((input) => { input.checked = event.target.checked; });
  });
  const syncSource = () => {
    const source = document.querySelector('input[name="source_mode"]:checked')?.value || 'active';
    document.getElementById('sourceActiveFields')?.classList.toggle('d-none', source !== 'active');
    document.getElementById('sourceSscFields')?.classList.toggle('d-none', source !== 'ssc_batch');
    const fromClass = document.getElementById('fromClass');
    const fromSession = document.querySelector('#sourceActiveFields input[name="from_session"]');
    const batchYear = document.querySelector('#sourceSscFields select[name="ssc_batch_year"], #sourceSscFields input[name="ssc_batch_year"]');
    if (fromClass) fromClass.required = source === 'active';
    if (fromSession) fromSession.required = source === 'active';
    if (batchYear) batchYear.required = source === 'ssc_batch';
  };
  document.querySelectorAll('input[name="source_mode"]').forEach((el) => el.addEventListener('change', syncSource));
  syncSource();
  const syncDest = () => {
    const mode = document.querySelector('input[name="dest_mode"]:checked')?.value || 'class';
    document.getElementById('destClassFields')?.classList.toggle('d-none', mode !== 'class' && mode !== 'ssc_return');
    document.getElementById('destSscFields')?.classList.toggle('d-none', mode !== 'ssc');
    const toClass = document.getElementById('toClass');
    const toSection = document.getElementById('toSection');
    const toSession = document.querySelector('input[name="to_session"]');
    const sscYear = document.querySelector('#destSscFields input[name="ssc_batch_year"]');
    if (toClass) toClass.required = mode === 'class' || mode === 'ssc_return';
    if (toSection) toSection.required = mode === 'class' || mode === 'ssc_return';
    if (toSession) toSession.required = mode === 'class' || mode === 'ssc_return';
    if (sscYear) sscYear.required = mode === 'ssc';
  };
  document.querySelectorAll('input[name="dest_mode"]').forEach((el) => el.addEventListener('change', syncDest));
  syncDest();
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">শ্রেণি উন্নয়ন / Promotion</h3>
            <p class="text-secondary mb-0">সাধারণ উন্নয়ন, পরীক্ষার্থী ব্যাচে পাঠানো, অথবা খারাপ ফলাফলের পর <strong>১০ম শ্রেণিতে ফেরত</strong> আনা।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3">
              <div class="card-body">
                <div class="mb-3">
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="source_mode" id="sourceActive" value="active" <?= $sourceMode !== 'ssc_batch' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sourceActive">সক্রিয় শ্রেণি</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="source_mode" id="sourceSsc" value="ssc_batch" <?= $sourceMode === 'ssc_batch' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sourceSsc">পরীক্ষার্থী ব্যাচ → ১০ম শ্রেণিতে ফেরত</label>
                  </div>
                </div>
                <div class="row g-2 align-items-end <?= $sourceMode === 'ssc_batch' ? 'd-none' : '' ?>" id="sourceActiveFields">
                  <div class="col-md-3">
                    <label class="form-label">From class</label>
                    <select class="form-select" name="from_class" id="fromClass" <?= $sourceMode === 'active' ? 'required' : '' ?>>
                      <option value="">Select</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $fromClass === (int) $class['id'] ? 'selected' : '' ?>><?= h($class['class_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">From section</label>
                    <select class="form-select" name="from_section" id="fromSection">
                      <option value="">All</option>
                      <?php foreach ($fromSections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $fromSection === (string) $section['id'] ? 'selected' : '' ?>><?= h($section['section_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><label class="form-label">From session</label><input class="form-control" name="from_session" value="<?= h($fromSession) ?>" <?= $sourceMode === 'active' ? 'required' : '' ?>></div>
                  <div class="col-md-3"><button class="btn btn-outline-primary w-100">Load students</button></div>
                </div>
                <div class="row g-2 align-items-end <?= $sourceMode === 'ssc_batch' ? '' : 'd-none' ?>" id="sourceSscFields">
                  <div class="col-md-4">
                    <label class="form-label">পরীক্ষার্থী ব্যাচ (সাল)</label>
                    <?php if ($sscBatchYears !== []): ?>
                      <select class="form-select" name="ssc_batch_year" <?= $sourceMode === 'ssc_batch' ? 'required' : '' ?>>
                        <?php foreach ($sscBatchYears as $year): ?>
                          <option value="<?= h($year) ?>" <?= $sscBatchYear === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input class="form-control" name="ssc_batch_year" value="<?= h($sscBatchYear) ?>" placeholder="যেমন: ২০২৬" <?= $sourceMode === 'ssc_batch' ? 'required' : '' ?>>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-5">
                    <div class="form-text pt-md-4">শুধু SSC candidate ব্যাচের শিক্ষার্থী লোড হবে। নতুন শিক্ষাবর্ষ দিয়ে ১০ম শ্রেণিতে ফেরান।</div>
                  </div>
                  <div class="col-md-3"><button class="btn btn-outline-primary w-100">Load batch</button></div>
                </div>
              </div>
            </form>
            <form method="post" class="card stat-card mb-3" <?= $sourceMode === 'ssc_batch' ? 'onsubmit="return confirm(\'নির্বাচিত শিক্ষার্থীদের পরীক্ষার্থী ব্যাচ থেকে সক্রিয় ১০ম শ্রেণিতে ফেরাবেন?\');"' : '' ?>>
              <div class="card-header">Destination</div>
              <div class="card-body">
                <input type="hidden" name="source_mode" value="<?= h($sourceMode) ?>">
                <input type="hidden" name="from_class" value="<?= (int) $fromClass ?>">
                <input type="hidden" name="from_section" value="<?= h($fromSection) ?>">
                <input type="hidden" name="from_session" value="<?= h($fromSession) ?>">
                <?php if ($sourceMode === 'ssc_batch'): ?>
                <input type="hidden" name="ssc_batch_year" value="<?= h($sscBatchYear) ?>">
                <?php endif; ?>
                <?php if ($sourceMode === 'ssc_batch'): ?>
                  <input type="hidden" name="dest_mode" value="ssc_return">
                  <p class="text-secondary mb-3">পরীক্ষার্থী ব্যাচ <strong><?= h($sscBatchYear !== '' ? $sscBatchYear : '—') ?></strong> থেকে সক্রিয় শ্রেণিতে ফেরত। আগের SSC ইতিহাস মুছে যাবে না।</p>
                <?php else: ?>
                <div class="mb-3">
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="dest_mode" id="destClass" value="class" <?= $destMode !== 'ssc' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="destClass">সাধারণ শ্রেণি উন্নয়ন</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="dest_mode" id="destSsc" value="ssc" <?= $destMode === 'ssc' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="destSsc">পরীক্ষার্থী ব্যাচ (SSC)</label>
                  </div>
                </div>
                <?php endif; ?>
                <div class="row g-2 <?= $destMode === 'ssc' && $sourceMode !== 'ssc_batch' ? 'd-none' : '' ?>" id="destClassFields">
                  <div class="col-md-3">
                    <label class="form-label">To class</label>
                    <select class="form-select" name="to_class" id="toClass">
                      <option value="">শ্রেণী বাছাই করুন</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= $toClass === (int) $class['id'] ? 'selected' : '' ?>><?= h($class['class_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">To section</label>
                    <select class="form-select" name="to_section" id="toSection" required>
                      <option value="">শাখা বাছাই করুন</option>
                      <?php foreach ($toSections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= $toSection === (string) $section['id'] ? 'selected' : '' ?>><?= h($section['section_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><label class="form-label"><?= $sourceMode === 'ssc_batch' ? 'নতুন শিক্ষাবর্ষ' : 'To session' ?></label><input class="form-control" name="to_session" value="<?= h($toSession) ?>"></div>
                  <div class="col-md-3"><label class="form-label">Remarks</label><input class="form-control" name="remarks" value="<?= $sourceMode === 'ssc_batch' ? 'Returned after SSC' : 'Annual promotion' ?>"></div>
                  <?php if ($destOccupied > 0 && $sourceMode !== 'ssc_batch'): ?>
                  <div class="col-12">
                    <div class="alert alert-warning mb-0 py-2">
                      গন্তব্য শ্রেণি/সেশনে ইতিমধ্যে <strong><?= (int) $destOccupied ?></strong> জন সক্রিয় শিক্ষার্থী আছে।
                      ৯→১০ উন্নয়নের আগে বর্তমান ১০ম শ্রেণীকে <strong>পরীক্ষার্থী ব্যাচ (SSC)</strong>-এ পাঠানো উচিত।
                    </div>
                  </div>
                  <?php endif; ?>
                  <?php if ($returnOccupied > 0 && $sourceMode === 'ssc_batch'): ?>
                  <div class="col-12">
                    <div class="alert alert-info mb-0 py-2">
                      গন্তব্যে ইতিমধ্যে <strong><?= (int) $returnOccupied ?></strong> জন সক্রিয়। রোল সংঘাত হলে সিস্টেম আটকাবে—নতুন রোল দিন।
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
                <?php if ($sourceMode !== 'ssc_batch'): ?>
                <div class="row g-2 <?= $destMode === 'ssc' ? '' : 'd-none' ?>" id="destSscFields">
                  <div class="col-md-4">
                    <label class="form-label">পরীক্ষার্থী ব্যাচ (সাল)</label>
                    <input class="form-control" name="ssc_batch_year" value="<?= h($sscBatchYear) ?>" placeholder="যেমন: ২০২৬">
                  </div>
                  <div class="col-md-8">
                    <label class="form-label d-none d-md-block">&nbsp;</label>
                    <div class="form-text">নির্বাচিত শিক্ষার্থী সক্রিয় তালিকা থেকে সরে যাবে। শ্রেণি/রোল ইতিহাস সংরক্ষিত থাকবে।</div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th><input type="checkbox" id="checkAll"></th>
                      <th>Photo</th>
                      <th>Student</th>
                      <th>Current Session</th>
                      <th>Current class</th>
                      <th>Current Section</th>
                      <th>Current roll</th>
                      <th>New roll</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($students as $student): ?>
                      <?php
                        $studentRef = signedRecordRef((int) $student['id'], 'student');
                        $currentSession = $sourceMode === 'ssc_batch'
                            ? (trim((string) ($student['ssc_batch_year'] ?? '')) ?: (string) ($student['session_year'] ?? ''))
                            : (string) ($student['session_year'] ?? '');
                        $currentClass = trim((string) ($student['class_name_ban'] ?? '')) ?: trim((string) ($student['class_name'] ?? ''));
                        $currentSection = trim((string) ($student['section_name_ban'] ?? '')) ?: trim((string) ($student['section_name'] ?? ''));
                      ?>
                      <tr>
                        <td><input type="checkbox" name="student_id[]" value="<?= h($studentRef) ?>"></td>
                        <td><img src="<?= h(studentPhotoUrl($student['student_pic'] ?? '')) ?>" width="36" height="46" class="rounded" style="object-fit:cover" alt=""></td>
                        <td><?= h((string) $student['name_ban']) ?></td>
                        <td><?= h($currentSession !== '' ? $currentSession : '—') ?></td>
                        <td><?= h($currentClass !== '' ? $currentClass : '—') ?></td>
                        <td><?= h($currentSection !== '' ? $currentSection : '—') ?></td>
                        <td><?= h((string) $student['cls_roll']) ?></td>
                        <td><input class="form-control form-control-sm" name="to_roll[<?= h($studentRef) ?>]" value="<?= h((string) $student['cls_roll']) ?>"></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($students === []): ?>
                      <tr><td colspan="8" class="text-center text-secondary py-4">
                        <?= $sourceMode === 'ssc_batch' ? 'পরীক্ষার্থী ব্যাচ লোড করুন।' : 'Load a class to promote.' ?>
                      </td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-success" name="action" value="promote" <?= $students === [] ? 'disabled' : '' ?>>
                  <?= $sourceMode === 'ssc_batch' ? '১০ম শ্রেণিতে ফেরত আনুন' : 'Promote / স্থানান্তর করুন' ?>
                </button>
                <?php if ($sourceMode === 'active'): ?>
                <button class="btn btn-outline-secondary" name="action" value="mark_left" <?= $students === [] ? 'disabled' : '' ?>
                        onclick="return confirm('নির্বাচিত শিক্ষার্থীদের পড়াশুনা বন্ধ / Left হিসেবে চিহ্নিত করবেন? পুরনো রেকর্ড মুছে যাবে না।');">
                  পড়াশুনা বন্ধ (Left)
                </button>
                <?php endif; ?>
              </div>
            </form>
            <div class="card stat-card">
              <div class="card-header">Recent promotions</div>
              <div class="table-responsive">
                <table class="table mb-0">
                  <thead><tr><th>Student</th><th>From</th><th>To</th><th>When</th></tr></thead>
                  <tbody>
                    <?php foreach ($history as $row): ?>
                      <?php
                        $remarks = (string) ($row['remarks'] ?? '');
                        $isSscOut = str_contains($remarks, 'SSC candidate');
                        $isSscReturn = str_contains($remarks, 'Returned to Class 10');
                      ?>
                      <tr>
                        <td><?= h((string) $row['name_ban']) ?></td>
                        <td>
                          <?php if ($isSscReturn): ?>
                            পরীক্ষার্থী ব্যাচ <?= h((string) $row['from_session']) ?>
                          <?php else: ?>
                            <?= h((string) $row['from_session']) ?> · class <?= h((string) $row['from_class']) ?> · roll <?= h((string) $row['from_roll']) ?>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($isSscOut): ?>
                            পরীক্ষার্থী ব্যাচ <?= h((string) $row['to_session']) ?>
                          <?php else: ?>
                            <?= h((string) $row['to_session']) ?> · class <?= h((string) $row['to_class']) ?> · roll <?= h((string) $row['to_roll']) ?>
                          <?php endif; ?>
                        </td>
                        <td><?= h((string) $row['created_at']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
