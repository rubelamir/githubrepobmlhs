<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$id = requestRecordId('student');
$student = [];
if ($id > 0) {
    $rows = fetchRows($db, 'SELECT * FROM students_info WHERE id = :id LIMIT 1', ['id' => $id]);
    $student = $rows[0] ?? [];
    if ($student === []) {
        redirectWithMessage('students.php', 'danger', 'Student not found.');
    }
}

$panelTeacher = getAuthenticatedTeacher();
$fullAdmission = canManageModule('admissions', $panelTeacher);
$admissionYear = $id > 0 ? trim((string) ($student['session_year'] ?? '')) : (string) date('Y');
$scope = ['entire' => [], 'sections' => []];
if (!$fullAdmission) {
    $scope = classTeacherScope($db, $panelTeacher, $admissionYear);
    if ($id > 0 && !classTeacherScopeAllows($scope, (int) ($student['cls'] ?? 0), (int) ($student['sections'] ?? 0))) {
        redirectWithMessage('student-form.php', 'danger', 'You can edit only students of the class assigned to you as class teacher.');
    }
}
$pageTitle = ($id > 0 ? 'Edit Student' : 'New Admission') . ' | School Admin';
$classes = schoolClasses($db);
if (!$fullAdmission) {
    $classes = array_values(array_filter(
        $classes,
        static fn (array $class): bool => isset($scope['entire'][(int) $class['id']]) || isset($scope['sections'][(int) $class['id']])
    ));
}
$hasRegNo = columnExists($db, 'students_info', 'reg_no');
$values = [
    'name_ban' => '', 'name_eng' => '', 'fname_ban' => '', 'fname_eng' => '', 'father_nid_no' => '',
    'mname_ban' => '', 'mname_eng' => '', 'mother_nid_no' => '',
    'gender' => '', 'religion' => 'Islam', 'birth_date' => '', 'birth_reg_no' => '',
    'cls' => '', 'sections' => '', 'cls_roll' => '', 'session_year' => date('Y'),
    'mobile' => '', 'division' => '', 'district' => '', 'upazilla' => '',
    'post_office' => '', 'post_office_en' => '', 'village' => '', 'village_en' => '', 'house_name' => '',
    'reg_no' => '', 'last_class' => '', 'passing_year' => '',
    'scholarship_stipend_info' => 'not_applicable', 'last_school_name' => '', 'status' => '1',
];
foreach ($values as $field => $default) {
    $values[$field] = (string) ($student[$field] ?? $default);
}
if (!$fullAdmission) {
    $values['session_year'] = $admissionYear;
}

$sections = $values['cls'] !== '' ? schoolSections($db, (int) $values['cls']) : [];
$birthRegDisplayUrl = $id > 0 && !empty($student['birth_reg_pic'])
    ? signedRecordUrl('private-media.php', $id, 'student', ['kind' => 'student-birth'])
    : '';
$divisions = tableExists($db, 'divisions') ? fetchRows($db, 'SELECT id, name FROM divisions ORDER BY name') : [];
$districtLabel = '';
$upazilaLabel = '';
if ((int) $values['district'] > 0 && tableExists($db, 'districts')) {
    $districtSql = columnExists($db, 'districts', 'bn_name')
        ? 'SELECT COALESCE(NULLIF(bn_name, \'\'), name) AS name FROM districts WHERE id = :id LIMIT 1'
        : 'SELECT name FROM districts WHERE id = :id LIMIT 1';
    $districtRows = fetchRows($db, $districtSql, ['id' => (int) $values['district']]);
    $districtLabel = trim((string) ($districtRows[0]['name'] ?? ''));
}
if ((int) $values['upazilla'] > 0 && tableExists($db, 'upazilas')) {
    $upazilaSql = columnExists($db, 'upazilas', 'bn_name')
        ? 'SELECT COALESCE(NULLIF(bn_name, \'\'), name) AS name FROM upazilas WHERE id = :id LIMIT 1'
        : 'SELECT name FROM upazilas WHERE id = :id LIMIT 1';
    $upazilaRows = fetchRows($db, $upazilaSql, ['id' => (int) $values['upazilla']]);
    $upazilaLabel = trim((string) ($upazilaRows[0]['name'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        try {
            if ($id <= 0) {
                throw new RuntimeException('Student not found.');
            }
            deleteStudentRecord($db, $id);
            redirectWithMessage('students.php', 'success', 'Student record and files deleted.');
        } catch (Throwable $exception) {
            redirectWithMessage(signedRecordUrl('student-form.php', $id, 'student'), 'danger', userFacingError($exception));
        }
    }
    foreach ($values as $field => $unused) {
        $values[$field] = trim((string) ($_POST[$field] ?? $values[$field]));
    }
    try {
        if (!$fullAdmission) {
            $values['session_year'] = $admissionYear;
            if (!classTeacherScopeAllows($scope, (int) $values['cls'], (int) $values['sections'])) {
                throw new RuntimeException('You can admit students only to the class assigned to you as class teacher.');
            }
        }
        if ($values['name_ban'] === '' || $values['name_eng'] === '' || $values['session_year'] === '' || $values['gender'] === '') {
            throw new RuntimeException('Name, gender and session are required.');
        }
        $picData = (string) ($_POST['student_pic_data'] ?? '');
        $birthData = (string) ($_POST['birth_reg_pic_data'] ?? '');
        $pictureName = (string) ($student['student_pic'] ?? '');
        $birthName = (string) ($student['birth_reg_pic'] ?? '');
        if ($picData !== '') {
            $pictureName = saveCroppedJpeg($picData, 'student_pic', '', 100 * 1024, 108, 137);
            replacePublicMediaFile('student_pic', (string) ($student['student_pic'] ?? ''), $pictureName);
        }
        if ($birthData !== '') {
            $birthName = savePrivateCroppedJpeg($birthData, 'birth_reg_pic', '', 300 * 1024);
            replacePrivateMediaFile('birth_reg_pic', (string) ($student['birth_reg_pic'] ?? ''), $birthName);
        }
        if ($id === 0 && $pictureName === '') {
            throw new RuntimeException('Student photograph is required. Please crop the photo.');
        }

        $payload = $values;
        if (!$hasRegNo || $id === 0) {
            unset($payload['reg_no']);
        }
        if (!in_array($payload['scholarship_stipend_info'], ['not_applicable', 'scholarship', 'stipend'], true)) {
            $payload['scholarship_stipend_info'] = 'not_applicable';
        }
        $payload['student_pic'] = $pictureName;
        $payload['birth_reg_pic'] = $birthName;
        $payload['cls'] = $payload['cls'] !== '' ? (int) $payload['cls'] : null;
        $payload['cls_roll'] = $payload['cls_roll'] !== '' ? (int) $payload['cls_roll'] : null;
        $payload['division'] = (int) ($payload['division'] ?: 0);
        $payload['district'] = (int) ($payload['district'] ?: 0);
        $payload['upazilla'] = (int) ($payload['upazilla'] ?: 0);
        $payload['status'] = (int) ($payload['status'] ?: 1);

        if (
            (int) $payload['status'] === studentStatusAdmitted()
            && $payload['cls'] !== null
            && $payload['cls_roll'] !== null
            && trim((string) $payload['session_year']) !== ''
        ) {
            assertActiveStudentRollAvailable(
                $db,
                (int) $payload['cls'],
                trim((string) $payload['session_year']),
                trim((string) ($payload['sections'] ?? '')),
                (string) $payload['cls_roll'],
                $id
            );
        }

        if ($id > 0) {
            $sets = [];
            foreach ($payload as $column => $value) {
                $sets[] = "`{$column}` = :{$column}";
            }
            $payload['id'] = $id;
            $db->prepare('UPDATE students_info SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($payload);
            redirectWithMessage(signedRecordUrl('student-form.php', $id, 'student'), 'success', 'Student record updated.');
        }

        $payload['admission_token'] = uniqueAdmissionToken($db);
        $payload['exam_roll'] = '';
        $initialPassword = $values['mobile'] !== '' ? substr($values['mobile'], -6) : '123456';
        $payload['pass'] = hashPassword($initialPassword);
        $columns = array_keys($payload);
        $placeholders = array_map(static fn ($column) => ':' . $column, $columns);
        $db->prepare(
            'INSERT INTO students_info (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')'
        )->execute($payload);
        $newId = (int) $db->lastInsertId();
        redirectWithMessage($fullAdmission ? signedRecordUrl('student-form.php', $newId, 'student') : 'student-form.php', 'success', 'Admission completed. Token: ' . $payload['admission_token'] . '. Initial password is the last 6 digits of the mobile number.');
    } catch (Throwable $exception) {
        $_SESSION['flash_type'] = 'danger';
        $_SESSION['flash_message'] = userFacingError($exception);
    }
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#studentForm', [
    { input: '#studentPic', hidden: '#studentPicData', image: '#studentPicImage', preview: '#studentPicPreview', aspectRatio: 108/137, width: 108, height: 137, maxLength: 136500, errorMessage: 'Student photo must be under 100KB.' },
    { input: '#birthPic', hidden: '#birthPicData', image: '#birthPicImage', preview: '#birthPicPreview', maxWidth: 1200, maxHeight: 1200, maxLength: 408000, errorMessage: 'Birth registration image must be under 300KB.' }
  ]);
  const cls = document.getElementById('cls');
  const sections = document.getElementById('sections');
  const selectedSection = sections?.dataset.selected || '';
  const loadSections = async () => {
    sections.innerHTML = '<option value="">Select section</option>';
    if (!cls.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value) + (sections.dataset.scoped === '1' ? '&for=attendance' : ''))).json();
    rows.forEach((row) => {
      const option = new Option(row.section_name_ban + ' / ' + row.section_name, row.id);
      if (String(row.id) === String(selectedSection)) option.selected = true;
      sections.add(option);
    });
  };
  cls?.addEventListener('change', loadSections);
  if (cls?.value) loadSections();

  const division = document.getElementById('division');
  const district = document.getElementById('district');
  const upazilla = document.getElementById('upazilla');
  const loadLocation = async (parent, child, type, selectedId = '') => {
    if (!parent || !child) return;
    child.innerHTML = '<option value="">Select</option>';
    child.disabled = !parent.value;
    if (!parent.value) return;
    const rows = await (await fetch('academic-api.php?location=' + type + '&parent_id=' + encodeURIComponent(parent.value))).json();
    (rows || []).forEach((row) => {
      const option = new Option(row.name, row.id);
      if (String(row.id) === String(selectedId)) option.selected = true;
      child.add(option);
    });
  };
  division?.addEventListener('change', async () => {
    if (upazilla) {
      upazilla.innerHTML = '<option value="">Select</option>';
      upazilla.disabled = true;
    }
    await loadLocation(division, district, 'district');
  });
  district?.addEventListener('change', async () => {
    await loadLocation(district, upazilla, 'upazila');
  });
  if (division?.value) {
    loadLocation(division, district, 'district', district?.dataset.selected || '').then(() => {
      if (district?.value) {
        return loadLocation(district, upazilla, 'upazila', upazilla?.dataset.selected || '');
      }
    });
  }
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0"><?= $id > 0 ? 'Edit student / ভর্তি তথ্য সংশোধন' : 'Office admission / অফিস ভর্তি' ?></h3>
              <p class="text-secondary mb-0">Use Cropper.js for photographs. Required fields are marked.</p>
            </div>
            <a class="btn btn-outline-secondary" href="students.php">Back to list</a>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="post" id="studentForm" class="card stat-card">
              <?= csrfField() ?>
              <?php if ($id > 0): ?><?= signedRecordField($id, 'student') ?><?php endif; ?>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-12"><h2 class="h6 text-primary">Photograph</h2></div>
                  <div class="col-md-6">
                    <?php renderCropperField('studentPic', 'student_pic_data', 'Student photo', 'Output: 108×137px JPEG, maximum 100KB.', $id === 0); ?>
                    <?php if (!empty($student['student_pic'])): ?>
                      <img src="<?= h(studentPhotoUrl((string) $student['student_pic'])) ?>" width="54" height="68" class="border" alt="">
                    <?php endif; ?>
                  </div>
                  <div class="col-md-6">
                    <?php renderCropperField('birthPic', 'birth_reg_pic_data', 'Birth registration / NID copy', 'Free crop, JPEG, maximum 300KB.'); ?>
                    <?php if ($birthRegDisplayUrl !== ''): ?>
                      <img src="<?= h($birthRegDisplayUrl) ?>" width="54" height="68" class="border" alt="">
                    <?php endif; ?>
                  </div>

                  <div class="col-12"><h2 class="h6 text-primary mt-2">Academic placement</h2></div>
                  <div class="col-md-3">
                    <label class="form-label">Session</label>
                    <input class="form-control" name="session_year" value="<?= h($values['session_year']) ?>" required<?= $fullAdmission ? '' : ' readonly' ?>>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Class</label>
                    <select class="form-select" name="cls" id="cls">
                      <option value="">Select</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int) $class['id'] ?>" <?= (string) $class['id'] === $values['cls'] ? 'selected' : '' ?>><?= h($class['class_name_ban'] . ' / ' . $class['class_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Section</label>
                    <select class="form-select" name="sections" id="sections" data-selected="<?= h($values['sections']) ?>" data-scoped="<?= $fullAdmission ? '0' : '1' ?>">
                      <option value="">Select</option>
                      <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>" <?= (string) $section['id'] === $values['sections'] ? 'selected' : '' ?>><?= h($section['section_name_ban']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Class roll</label>
                    <input class="form-control" name="cls_roll" value="<?= h($values['cls_roll']) ?>">
                  </div>
                  <?php if ($id > 0 && $hasRegNo): ?>
                  <div class="col-md-3">
                    <label class="form-label">Registration no.</label>
                    <input class="form-control" name="reg_no" value="<?= h($values['reg_no']) ?>" maxlength="255">
                  </div>
                  <?php endif; ?>
                  <?php if ($id > 0): ?>
                  <div class="col-md-3">
                    <label class="form-label">Scholarship / Stipend</label>
                    <select class="form-select" name="scholarship_stipend_info">
                      <option value="not_applicable" <?= $values['scholarship_stipend_info'] === 'not_applicable' ? 'selected' : '' ?>>Not applicable / প্রযোজ্য নয়</option>
                      <option value="scholarship" <?= $values['scholarship_stipend_info'] === 'scholarship' ? 'selected' : '' ?>>Scholarship / বৃত্তি প্রাপ্ত</option>
                      <option value="stipend" <?= $values['scholarship_stipend_info'] === 'stipend' ? 'selected' : '' ?>>Stipend / উপবৃত্তি প্রাপ্ত</option>
                    </select>
                  </div>
                  <?php endif; ?>
                  <div class="col-md-1">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                      <option value="0" <?= $values['status'] === '0' ? 'selected' : '' ?>>Pending</option>
                      <option value="1" <?= $values['status'] === '1' ? 'selected' : '' ?>>Admitted</option>
                      <option value="2" <?= $values['status'] === '2' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                  </div>

                  <div class="col-12"><h2 class="h6 text-primary mt-2">Student information</h2></div>
                  <div class="col-md-6"><label class="form-label">Name (Bangla) *</label><input class="form-control" name="name_ban" value="<?= h($values['name_ban']) ?>" required></div>
                  <div class="col-md-6"><label class="form-label">Name (English) *</label><input class="form-control" name="name_eng" value="<?= h($values['name_eng']) ?>" required></div>
                  <div class="col-md-4"><label class="form-label">Gender *</label>
                    <select class="form-select" name="gender" required>
                      <option value="">Select</option>
                      <?php foreach (['male' => 'Male / ছাত্র', 'female' => 'Female / ছাত্রী'] as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $values['gender'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label">Religion</label>
                    <select class="form-select" name="religion">
                      <?php foreach (['Islam', 'Hindu', 'Buddhist', 'Christian'] as $religion): ?>
                        <option <?= $values['religion'] === $religion ? 'selected' : '' ?>><?= h($religion) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label">Date of birth</label><input class="form-control" type="date" name="birth_date" value="<?= h($values['birth_date']) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Birth registration no.</label><input class="form-control" name="birth_reg_no" value="<?= h($values['birth_reg_no']) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Mobile</label><input class="form-control" name="mobile" value="<?= h($values['mobile']) ?>"></div>

                  <div class="col-12"><h2 class="h6 text-primary mt-2">Parents</h2></div>
                  <div class="col-md-6"><label class="form-label">Father (Bangla)</label><input class="form-control" name="fname_ban" value="<?= h($values['fname_ban']) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Father (English)</label><input class="form-control" name="fname_eng" value="<?= h($values['fname_eng']) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Father NID</label><input class="form-control" name="father_nid_no" value="<?= h($values['father_nid_no']) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Mother (Bangla)</label><input class="form-control" name="mname_ban" value="<?= h($values['mname_ban']) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Mother (English)</label><input class="form-control" name="mname_eng" value="<?= h($values['mname_eng']) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Mother NID</label><input class="form-control" name="mother_nid_no" value="<?= h($values['mother_nid_no']) ?>"></div>

                  <div class="col-12"><h2 class="h6 text-primary mt-2">Address</h2></div>
                  <div class="col-md-4"><label class="form-label">Division</label>
                    <select class="form-select" name="division" id="division">
                      <option value="">Select</option>
                      <?php foreach ($divisions as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= $values['division'] === (string) $row['id'] ? 'selected' : '' ?>><?= h((string) $row['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label">District</label>
                    <select class="form-select" name="district" id="district" data-selected="<?= h($values['district']) ?>"<?= $values['division'] === '' ? ' disabled' : '' ?>>
                      <option value="">Select</option>
                      <?php if ($values['district'] !== ''): ?>
                        <option value="<?= h($values['district']) ?>" selected><?= h($districtLabel !== '' ? $districtLabel : $values['district']) ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label">Upazila</label>
                    <select class="form-select" name="upazilla" id="upazilla" data-selected="<?= h($values['upazilla']) ?>"<?= $values['district'] === '' ? ' disabled' : '' ?>>
                      <option value="">Select</option>
                      <?php if ($values['upazilla'] !== ''): ?>
                        <option value="<?= h($values['upazilla']) ?>" selected><?= h($upazilaLabel !== '' ? $upazilaLabel : $values['upazilla']) ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3"><label class="form-label">Post office (Bangla)</label><input class="form-control" name="post_office" value="<?= h($values['post_office']) ?>"></div>
                  <div class="col-md-3"><label class="form-label">Post office (English)</label><input class="form-control" name="post_office_en" value="<?= h($values['post_office_en']) ?>"></div>
                  <div class="col-md-3"><label class="form-label">Village (Bangla)</label><input class="form-control" name="village" value="<?= h($values['village']) ?>"></div>
                  <div class="col-md-3"><label class="form-label">Village (English)</label><input class="form-control" name="village_en" value="<?= h($values['village_en']) ?>"></div>
                  <div class="col-md-4"><label class="form-label">House name</label><input class="form-control" name="house_name" value="<?= h($values['house_name']) ?>"></div>

                  <div class="col-12"><h2 class="h6 text-primary mt-2">Previous school</h2></div>
                  <div class="col-md-3"><label class="form-label">Last class</label><input class="form-control" name="last_class" value="<?= h($values['last_class']) ?>"></div>
                  <div class="col-md-3">
                    <label class="form-label">Passing year</label>
                    <select class="form-select" name="passing_year">
                      <option value="">Select</option>
                      <?php echoPreviousSchoolYearOptions($values['passing_year']); ?>
                    </select>
                  </div>
                  <div class="col-12"><label class="form-label">Last school name</label><input class="form-control" name="last_school_name" value="<?= h($values['last_school_name']) ?>"></div>
                </div>
              </div>
              <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="students.php">Cancel</a>
                <button class="btn btn-success"><?= $id > 0 ? 'Update student' : 'Complete admission' ?></button>
              </div>
            </form>
            <?php if ($id > 0): ?>
            <form method="post" class="mt-3" onsubmit="return confirm('Delete this student and their photos? This cannot be undone.')">
              <?= csrfField() ?>
              <?= signedRecordField($id, 'student') ?>
              <input type="hidden" name="action" value="delete">
              <button class="btn btn-outline-danger">Delete student and files</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
