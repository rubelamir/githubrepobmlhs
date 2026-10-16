<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');
if (!officeToolsAllowed()) {
    denyPanelAccess();
}

$db = getDatabaseConnection();
$basePath = '../';
$pageTitle = 'SMS | School Admin';
$classes = schoolClasses($db);
$audience = (string) ($_GET['audience'] ?? '') === 'teachers' ? 'teachers' : 'students';
$search = trim((string) ($_GET['q'] ?? ''));
$sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
$classId = (int) ($_GET['cls'] ?? 0);
$sectionId = (int) ($_GET['sections'] ?? 0);
$rollFilter = trim((string) ($_GET['cls_roll'] ?? ''));
$designation = trim((string) ($_GET['designation'] ?? ''));
$designationOptions = getDesignationOptions();
if ($designation !== '' && !array_key_exists($designation, $designationOptions)) {
    $designation = '';
}
$loadList = (string) ($_GET['load'] ?? '') === '1' || $audience === 'teachers';
$sections = $classId > 0 ? schoolSections($db, $classId) : [];
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

$returnQuery = static function (array $source): string {
    $group = (string) ($source['audience'] ?? '') === 'teachers' ? 'teachers' : 'students';
    $query = ['load' => '1', 'audience' => $group];
    $keys = $group === 'teachers'
        ? ['designation', 'q']
        : ['session_year', 'cls', 'sections', 'cls_roll', 'q'];
    foreach ($keys as $key) {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value !== '' && $value !== '0') {
            $query[$key] = $value;
        }
    }

    return http_build_query($query);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'bulk-sms.php?' . $returnQuery($_POST);
    try {
        if (!smsGatewayReady($db)) {
            throw new RuntimeException('Set the SMS provider, API token and sender ID in School Settings first.');
        }
        $message = trim((string) ($_POST['message'] ?? ''));
        if (mb_strlen($message, 'UTF-8') < 4) {
            throw new RuntimeException('Write a message of at least 4 characters.');
        }
        $sendTeachers = (string) ($_POST['audience'] ?? '') === 'teachers';
        $rawRefs = $_POST[$sendTeachers ? 'teachers' : 'students'] ?? [];
        if (!is_array($rawRefs)) {
            $rawRefs = [];
        }
        $scope = $sendTeachers ? 'teacher' : 'student';
        $ids = array_slice(idsFromSignedRecordRefs($rawRefs, $scope), 0, 400);
        if ($ids === []) {
            throw new RuntimeException($sendTeachers ? 'Select at least one teacher.' : 'Select at least one student.');
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $mobileColumn = $sendTeachers ? 'teacher_mobile' : 'mobile';
        $table = $sendTeachers ? 'teacher_info' : 'students_info';
        $rows = fetchRows(
            $db,
            'SELECT ' . $mobileColumn . ' AS mobile FROM ' . $table . ' WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params
        );
        $mobiles = [];
        foreach ($rows as $row) {
            $mobile = normalizeBdMobile((string) ($row['mobile'] ?? ''));
            if ($mobile !== '') {
                $mobiles[$mobile] = $mobile;
            }
        }
        if ($mobiles === []) {
            throw new RuntimeException($sendTeachers
                ? 'None of the selected teachers have a usable mobile number.'
                : 'None of the selected students have a usable mobile number.');
        }
        $summary = sendSchoolSmsBatch($db, array_values($mobiles), $message, $sendTeachers ? 'bulk_teacher' : 'bulk');
        writeAuditLog(
            $db,
            'sms',
            $sendTeachers ? 'teachers' : 'students',
            null,
            sprintf('SMS to %d selected %s numbers. Sent %d, failed %d.', count($mobiles), $sendTeachers ? 'teacher' : 'student', (int) $summary['sent'], (int) $summary['failed'])
        );
        redirectWithMessage(
            $back,
            (int) $summary['failed'] > 0 ? 'warning' : 'success',
            sprintf('Sent %d, failed %d.', (int) $summary['sent'], (int) $summary['failed'])
        );
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$students = [];
if ($loadList && $audience === 'students') {
    [$where, $params] = studentsQueryFilters($filterGet);
    $sql = 'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.mobile, s.student_pic,
                   c.class_name_ban AS class_name, sec.section_name_ban AS section_name
            FROM students_info s' . studentsListJoinSql();
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . studentsListOrderSql() . ' LIMIT 400';
    $students = fetchRows($db, $sql, $params);
}

$teachers = [];
if ($loadList && $audience === 'teachers' && tableExists($db, 'teacher_info')) {
    $where = [];
    $params = [];
    if ($designation !== '') {
        $where[] = 'teacher_designation = :designation';
        $params['designation'] = $designation;
    }
    if ($search !== '') {
        $where[] = '(teacher_name LIKE :q_name OR teacher_mobile LIKE :q_mobile)';
        $like = '%' . $search . '%';
        $params['q_name'] = $like;
        $params['q_mobile'] = $like;
    }
    $sql = 'SELECT id, teacher_name, teacher_designation, teacher_mobile, teacher_pic FROM teacher_info';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . teacherListOrderSql($db) . ' LIMIT 400';
    $teachers = fetchRows($db, $sql, $params);
}

$smsAccount = smsAccountOverview($db);

$pageStyles = <<<'CSS'
.sms-person-photo { width: 40px; height: 50px; object-fit: cover; }
.sms-balance-card { background: #f4f5f7; border: 0; }
.sms-balance-card .sms-balance-col { min-height: 9.5rem; }
.sms-balance-card .sms-balance-label { font-size: .9rem; color: #6c757d; margin-bottom: .35rem; }
.sms-balance-card .sms-balance-value { color: #dc3545; font-weight: 700; font-size: 1.15rem; }
.sms-balance-card .sms-rate-value { color: #0d6efd; font-weight: 700; font-size: 1.15rem; }
.sms-balance-card .sms-expire-ring {
  width: 8.5rem; height: 8.5rem; margin: 0 auto;
  border-radius: 50%; border: 8px solid #28a745;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  background: #fff;
}
.sms-balance-card .sms-expire-pct { font-size: 1.5rem; font-weight: 700; color: #6c757d; line-height: 1.1; }
.sms-balance-card .sms-expire-note { font-size: .72rem; color: #6c757d; max-width: 6.8rem; text-align: center; }
@media (max-width: 767.98px) {
  .sms-balance-card .sms-balance-col { min-height: 0; padding-top: .75rem; padding-bottom: .75rem; }
}
CSS;
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  const selected = sections?.dataset.selected || '';
  cls?.addEventListener('change', async () => {
    if (!sections) return;
    sections.innerHTML = '<option value="">All sections</option>';
    if (!cls.value) return;
    const response = await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value));
    if (!response.ok) return;
    const rows = await response.json();
    rows.forEach((row) => {
      const option = new Option(row.section_name_ban, row.id);
      if (String(row.id) === String(selected)) option.selected = true;
      sections.add(option);
    });
  });

  const master = document.getElementById('sms-select-all');
  const count = document.getElementById('sms-selected-count');
  const audience = document.getElementById('filterAudience');
  const studentFilters = document.getElementById('sms-student-filters');
  const teacherFilters = document.getElementById('sms-teacher-filters');
  const rollField = document.getElementById('sms-roll-field');
  const rollHint = document.getElementById('sms-roll-hint');
  const search = document.getElementById('filterSearch');
  const syncAudience = () => {
    const teachers = audience?.value === 'teachers';
    studentFilters?.classList.toggle('d-none', teachers);
    teacherFilters?.classList.toggle('d-none', !teachers);
    rollField?.classList.toggle('d-none', teachers);
    rollHint?.classList.toggle('d-none', teachers);
    if (search) search.placeholder = teachers ? 'Name or mobile' : 'Name, roll or mobile';
  };
  audience?.addEventListener('change', () => {
    syncAudience();
    if (audience.value === 'teachers') {
      audience.form?.requestSubmit();
    }
  });
  syncAudience();

  const boxes = () => Array.from(document.querySelectorAll('.sms-recipient:not(:disabled)'));
  const refresh = () => {
    const available = boxes();
    const chosen = available.filter((box) => box.checked).length;
    if (count) count.textContent = String(chosen);
    if (!master) return;
    master.checked = available.length > 0 && chosen === available.length;
    master.indeterminate = chosen > 0 && chosen < available.length;
  };
  master?.addEventListener('change', () => {
    boxes().forEach((box) => { box.checked = master.checked; });
    refresh();
  });
  document.querySelectorAll('.sms-recipient').forEach((box) => box.addEventListener('change', refresh));
  refresh();
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">SMS</h3>
            <p class="text-secondary mb-0">Filter students or teachers, tick who should receive the text, then send.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <?php if (!smsGatewayReady($db)): ?>
              <div class="alert alert-warning">Set the SMS provider, API token and sender ID in <a href="school-settings.php">School Settings</a> before sending.</div>
            <?php endif; ?>
            <?php if (!empty($smsAccount['ok'])): ?>
              <?php
                $balance = $smsAccount['balance'];
                $rate = $smsAccount['rate'];
                $smsCount = $smsAccount['sms_count'];
                $expireDate = (string) ($smsAccount['expire_date'] ?? '');
                $daysRemaining = $smsAccount['days_remaining'];
                $expirePct = $daysRemaining === null ? null : (int) min(100, max(0, (int) round(($daysRemaining / 90) * 100)));
              ?>
              <div class="card sms-balance-card mb-3">
                <div class="card-body py-3">
                  <div class="row align-items-center g-3 text-center">
                    <div class="col-md-4 sms-balance-col d-flex flex-column justify-content-center">
                      <div class="sms-balance-label">Current balance</div>
                      <div class="sms-balance-value"><?= h($balance !== null ? number_format((float) $balance, 2) . ' BDT' : '—') ?></div>
                      <div class="sms-balance-value mt-1"><?= h($smsCount !== null ? number_format((float) $smsCount, 2) . ' SMS' : '—') ?></div>
                      <?php if ($expireDate !== ''): ?>
                        <div class="text-secondary small mt-2">Expire: <?= h($expireDate) ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="col-md-4 sms-balance-col d-flex flex-column justify-content-center">
                      <div class="sms-balance-label">SMS Rate</div>
                      <div class="sms-rate-value"><?= h($rate !== null ? number_format((float) $rate, 2) . ' BDT/SMS' : '—') ?></div>
                    </div>
                    <div class="col-md-4 sms-balance-col d-flex flex-column justify-content-center">
                      <?php if ($daysRemaining !== null): ?>
                        <div class="sms-expire-ring" style="border-color: <?= $daysRemaining <= 15 ? '#dc3545' : ($daysRemaining <= 30 ? '#ffc107' : '#28a745') ?>;">
                          <div class="sms-expire-pct"><?= h((string) ($expirePct ?? 0)) ?>%</div>
                          <div class="sms-expire-note"><?= h((string) $daysRemaining) ?> Days Remain to Expire your balance</div>
                        </div>
                      <?php else: ?>
                        <div class="text-secondary">Expiry unavailable</div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php elseif (($smsAccount['detail'] ?? '') === 'provider_unsupported' && smsGatewayReady($db)): ?>
              <div class="alert alert-info">Balance is shown for BD Bulk SMS (GreenWeb). Switch provider in <a href="school-settings.php">School Settings</a> to see it here.</div>
            <?php endif; ?>
            <form method="get" class="card stat-card mb-3">
              <div class="card-body">
                <input type="hidden" name="load" value="1">
                <div class="row g-2 align-items-end mb-3">
                  <div class="col-md-4">
                    <label class="form-label" for="filterAudience">Recipients</label>
                    <select class="form-select" name="audience" id="filterAudience">
                      <option value="students" <?= $audience === 'students' ? 'selected' : '' ?>>Students</option>
                      <option value="teachers" <?= $audience === 'teachers' ? 'selected' : '' ?>>Teachers &amp; staff</option>
                    </select>
                  </div>
                </div>
                <div id="sms-student-filters" class="<?= $audience === 'teachers' ? 'd-none' : '' ?>">
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
                </div>
                <div id="sms-teacher-filters" class="<?= $audience === 'teachers' ? '' : 'd-none' ?>">
                  <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                      <label class="form-label" for="filterDesignation">Designation</label>
                      <select class="form-select" name="designation" id="filterDesignation">
                        <option value="">All designations</option>
                        <?php foreach ($designationOptions as $value => $label): ?>
                          <option value="<?= h((string) $value) ?>" <?= $designation === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="d-flex gap-2 align-items-end flex-wrap mt-3">
                  <div class="flex-grow-1<?= $audience === 'teachers' ? ' d-none' : '' ?>" id="sms-roll-field" style="min-width: 140px;">
                    <label class="form-label" for="filterRoll">Roll <span class="text-secondary fw-normal">(Optional)</span></label>
                    <input class="form-control" type="text" name="cls_roll" id="filterRoll" value="<?= h($rollFilter) ?>" placeholder="e.g. 1, 5, 8-12">
                  </div>
                  <div class="flex-grow-1" style="min-width: 160px;">
                    <label class="form-label" for="filterSearch">Search <span class="text-secondary fw-normal">(Optional)</span></label>
                    <input class="form-control" type="text" name="q" id="filterSearch" value="<?= h($search) ?>" placeholder="<?= $audience === 'teachers' ? 'Name or mobile' : 'Name, roll or mobile' ?>">
                  </div>
                  <div class="flex-shrink-0">
                    <button class="btn btn-outline-primary px-4" type="submit">Filter</button>
                  </div>
                </div>
                <div class="form-text mt-2<?= $audience === 'teachers' ? ' d-none' : '' ?>" id="sms-roll-hint">Use commas for specific rolls and a hyphen for a range, e.g. 1, 5, 8-12.</div>
              </div>
            </form>

            <?php if (!$loadList): ?>
              <div class="card stat-card"><div class="card-body text-secondary">Apply filters to choose recipients, then write the message.</div></div>
            <?php elseif ($audience === 'teachers'): ?>
              <form method="post" class="card stat-card">
                <?= csrfField() ?>
                <input type="hidden" name="audience" value="teachers">
                <input type="hidden" name="designation" value="<?= h($designation) ?>">
                <input type="hidden" name="q" value="<?= h($search) ?>">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <strong><?= count($teachers) ?></strong> <span class="text-secondary">teachers &amp; staff (maximum 400). Selected: <span id="sms-selected-count">0</span></span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th class="text-center" style="width: 2.75rem;">
                          <input class="form-check-input" type="checkbox" id="sms-select-all" aria-label="Select all teachers">
                        </th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th>Mobile</th>
                        <th>Photo</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($teachers === []): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">No teachers found.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($teachers as $teacher): ?>
                        <?php
                          $teacherId = (int) ($teacher['id'] ?? 0);
                          $teacherName = trim((string) ($teacher['teacher_name'] ?? ''));
                          $teacherMobile = normalizeBdMobile((string) ($teacher['teacher_mobile'] ?? ''));
                          $displayMobile = trim((string) ($teacher['teacher_mobile'] ?? ''));
                          $teacherPhoto = schoolFileUrl((string) ($teacher['teacher_pic'] ?? ''), 'teacher_pic');
                        ?>
                        <tr>
                          <td class="text-center">
                            <input class="form-check-input sms-recipient" type="checkbox" name="teachers[]" value="<?= h(signedRecordRef($teacherId, 'teacher')) ?>" aria-label="Select <?= h($teacherName !== '' ? $teacherName : 'teacher') ?>" <?= $teacherMobile === '' ? 'disabled' : '' ?>>
                          </td>
                          <td><?= h($teacherName) ?></td>
                          <td><?= h(getDesignationLabel((string) ($teacher['teacher_designation'] ?? ''))) ?></td>
                          <td>
                            <?php if ($teacherMobile === ''): ?>
                              <span class="text-secondary">No mobile</span>
                            <?php else: ?>
                              <?= h($displayMobile) ?>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($teacherPhoto !== ''): ?>
                              <img src="<?= h($teacherPhoto) ?>" alt="<?= h($teacherName) ?>" class="sms-person-photo rounded">
                            <?php else: ?>
                              <span class="sms-person-photo rounded d-inline-flex align-items-center justify-content-center bg-body-secondary text-secondary"><?= h($teacherName !== '' ? mb_strtoupper(mb_substr($teacherName, 0, 1, 'UTF-8'), 'UTF-8') : '—') ?></span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-body border-top">
                  <label class="form-label" for="sms-message">Message</label>
                  <textarea class="form-control mb-3" id="sms-message" name="message" rows="4" maxlength="480" required placeholder="বিদ্যালয়ের বার্তা"></textarea>
                  <button class="btn btn-success" type="submit">Send to selected</button>
                </div>
              </form>
            <?php else: ?>
              <form method="post" class="card stat-card">
                <?= csrfField() ?>
                <input type="hidden" name="audience" value="students">
                <?php foreach (['session_year', 'cls', 'sections', 'cls_roll', 'q'] as $filterKey): ?>
                  <input type="hidden" name="<?= h($filterKey) ?>" value="<?= h((string) ($filterGet[$filterKey] ?? '')) ?>">
                <?php endforeach; ?>
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <strong><?= count($students) ?></strong> <span class="text-secondary">students (maximum 400). Selected: <span id="sms-selected-count">0</span></span>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th class="text-center" style="width: 2.75rem;">
                          <input class="form-check-input" type="checkbox" id="sms-select-all" aria-label="Select all students">
                        </th>
                        <th>Roll</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Mobile</th>
                        <th>Photo</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($students === []): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No students found.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($students as $student): ?>
                        <?php
                          $studentId = (int) ($student['id'] ?? 0);
                          $name = trim((string) ($student['name_ban'] ?? ''));
                          if ($name === '') {
                              $name = trim((string) ($student['name_eng'] ?? ''));
                          }
                          $englishName = trim((string) ($student['name_eng'] ?? ''));
                          $mobile = normalizeBdMobile((string) ($student['mobile'] ?? ''));
                          $displayMobile = trim((string) ($student['mobile'] ?? ''));
                          $studentPhoto = studentPhotoUrl((string) ($student['student_pic'] ?? ''));
                        ?>
                        <tr>
                          <td class="text-center">
                            <input class="form-check-input sms-recipient" type="checkbox" name="students[]" value="<?= h(signedRecordRef($studentId, 'student')) ?>" aria-label="Select <?= h($name !== '' ? $name : 'student') ?>" <?= $mobile === '' ? 'disabled' : '' ?>>
                          </td>
                          <td><?= h((string) ($student['cls_roll'] ?? '')) ?></td>
                          <td>
                            <?= h($name) ?>
                            <?php if ($englishName !== '' && $englishName !== $name): ?>
                              <div class="small text-secondary"><?= h($englishName) ?></div>
                            <?php endif; ?>
                          </td>
                          <td><?= h((string) ($student['class_name'] ?? '')) ?></td>
                          <td><?= h((string) ($student['section_name'] ?? '')) ?></td>
                          <td>
                            <?php if ($mobile === ''): ?>
                              <span class="text-secondary">No mobile</span>
                            <?php else: ?>
                              <?= h($displayMobile) ?>
                            <?php endif; ?>
                          </td>
                          <td>
                            <img src="<?= h($studentPhoto) ?>" alt="<?= h($name) ?>" class="sms-person-photo rounded">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="card-body border-top">
                  <label class="form-label" for="sms-message">Message</label>
                  <textarea class="form-control mb-3" id="sms-message" name="message" rows="4" maxlength="480" required placeholder="বিদ্যালয়ের বার্তা"></textarea>
                  <button class="btn btn-success" type="submit">Send to selected</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
