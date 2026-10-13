<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$subjectOptions = [
    'bangla' => 'Bangla', 'english' => 'English', 'mathematics' => 'Mathematics',
    'religion_islam' => 'Religion (Islam)', 'religion_hindu' => 'Religion (Hindu)',
    'ict' => 'ICT', 'biology' => 'Biology', 'chemistry' => 'Chemistry', 'physics' => 'Physics',
    'business_studies' => 'Business Studies', 'accounting' => 'Accounting',
    'finance&banking' => 'Finance & Banking', 'social_science' => 'Social Science',
    'physical_education' => 'Physical Education', 'agricultural_science' => 'Agricultural Science',
    'home_science' => 'Home Science', 'library_science' => 'Library Science',
    'not_applicable' => 'Not Applicable',
];
$designationOptions = getDesignationOptions();
$religionOptions = ['Islam' => 'Islam', 'Hindu' => 'Hindu', 'Buddhist' => 'Buddhist', 'Christian' => 'Christian'];
$genderOptions = ['male' => 'Male', 'female' => 'Female'];
$locationTables = [
    'teacher_division' => ['table' => 'divisions', 'column' => 'teacher_division'],
    'teacher_district' => ['table' => 'districts', 'column' => 'teacher_district'],
    'teacher_upazilla' => ['table' => 'upazilas', 'column' => 'teacher_upazilla'],
];
$addressFields = ['teacher_post_office' => 'Post Office', 'teacher_village' => 'Village', 'teacher_house' => 'House'];
$fields = [
    'teacher_name', 'teacher_mobile', 'teacher_father_name', 'teacher_mother_name', 'teacher_religion',
    'teacher_gender', 'teacher_nid_no', 'teacher_email', 'teacher_birth_date', 'teacher_tin_no',
    'teacher_account_no', 'teacher_designation', 'teacher_subject', 'teacher_salary_code',
    'teacher_index_number', 'teacher_pds_id', 'first_joining_date', 'teacher_joining_date', 'teacher_mpo_date',
    'educational_details', 'training_details', 'teacher_division', 'teacher_district',
    'teacher_upazilla', 'teacher_post_office', 'teacher_village', 'teacher_house',
];
$values = array_fill_keys($fields, '');
$errors = [];
$existingColumns = array_flip($db->query('DESCRIBE teacher_info')->fetchAll(PDO::FETCH_COLUMN));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $field) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    foreach (['teacher_name', 'teacher_mobile', 'teacher_gender', 'teacher_designation', 'teacher_subject', 'teacher_birth_date'] as $field) {
        if ($values[$field] === '') {
            $errors[$field] = 'This field is required.';
        }
    }
    if ($values['teacher_mobile'] !== '') {
        $check = $db->prepare('SELECT COUNT(*) FROM teacher_info WHERE teacher_mobile = :mobile');
        $check->execute(['mobile' => $values['teacher_mobile']]);
        if ((int) $check->fetchColumn() > 0) {
            $errors['teacher_mobile'] = 'This mobile number already exists.';
        }
    }
    if ($values['teacher_email'] !== '' && filter_var($values['teacher_email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['teacher_email'] = 'Enter a valid email address.';
    }
    if (!array_key_exists($values['teacher_gender'], $genderOptions)) {
        $errors['teacher_gender'] = 'Please select a valid gender.';
    }
    if (!array_key_exists($values['teacher_designation'], $designationOptions)) {
        $errors['teacher_designation'] = 'Please select a valid designation.';
    }
    if (!array_key_exists($values['teacher_subject'], $subjectOptions)) {
        $errors['teacher_subject'] = 'Please select a valid subject.';
    }

    $pictureData = (string) ($_POST['teacher_pic_data'] ?? '');
    $nidPictureData = (string) ($_POST['teacher_nid_pic_data'] ?? '');
    foreach (['teacher_pic_data' => $pictureData, 'teacher_nid_pic_data' => $nidPictureData] as $field => $data) {
        if ($data !== '' && !preg_match('#^data:image/jpeg;base64,#', $data)) {
            $errors[$field] = 'Please upload a cropped JPEG image.';
        }
    }
    if ($errors === []) {
        $insert = [];
        foreach ($fields as $field) {
            if (isset($existingColumns[$field]) && $values[$field] !== '') {
                $insert[$field] = $values[$field];
            }
        }
        if (isset($existingColumns['teacher_password'])) {
            $password = trim((string) ($_POST['teacher_password'] ?? ''));
            if ($password === '') {
                $password = $values['teacher_mobile'];
            }
            $insert['teacher_password'] = hashPassword($password);
        }
        if (isset($existingColumns['teacher_status'])) {
            $insert['teacher_status'] = 1;
        }
        if (isset($existingColumns['panel_role'])) {
            $insert['panel_role'] = 'teacher';
        }
        if (isset($existingColumns['sort_order']) && !isset($insert['sort_order'])) {
            $insert['sort_order'] = nextTeacherSortOrder($db);
        }
        $columns = array_keys($insert);
        $parameters = [];
        foreach ($columns as $column) {
            $parameters[] = ':' . $column;
        }
        $savedImageFiles = [];
        $saveTeacherImage = static function (
            string $data,
            string $directory,
            int $maxBytes,
            int $expectWidth = 0,
            int $expectHeight = 0
        ) use (&$savedImageFiles): string {
            $private = $directory === 'teacher_nid_pic';
            $filename = $private
                ? savePrivateCroppedJpeg($data, $directory, '', $maxBytes, $expectWidth, $expectHeight)
                : saveCroppedJpeg($data, $directory, '', $maxBytes, $expectWidth, $expectHeight);
            $savedImageFiles[] = [$directory, $filename, $private];
            $webpFilename = $private ? '' : webpCompanionName($filename);
            if ($webpFilename !== '' && is_file(publicSitePath($directory) . DIRECTORY_SEPARATOR . $webpFilename)) {
                $savedImageFiles[] = [$directory, $webpFilename, false];
            }

            return $directory . '/' . $filename;
        };
        $cleanupSavedImages = static function () use (&$savedImageFiles): void {
            foreach ($savedImageFiles as [$directory, $filename, $private]) {
                $deleted = $private
                    ? deletePrivateMediaFile($directory, $filename)
                    : safeUnlinkUnderDirectory(publicSitePath($directory), $filename);
                if (!$deleted) {
                    error_log('Unable to clean up a teacher image after a failed save.');
                }
            }
        };
        try {
            $db->beginTransaction();
            $statement = $db->prepare(
                'INSERT INTO teacher_info (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $parameters) . ')'
            );
            $statement->execute($insert);
            $teacherId = (int) $db->lastInsertId();
            $changes = [];
            if ($pictureData !== '' && isset($existingColumns['teacher_pic'])) {
                $changes['teacher_pic'] = $saveTeacherImage(
                    $pictureData,
                    'teacher_pic',
                    100 * 1024,
                    108,
                    137
                );
            }
            if ($nidPictureData !== '' && isset($existingColumns['teacher_nid_pic'])) {
                $changes['teacher_nid_pic'] = $saveTeacherImage(
                    $nidPictureData,
                    'teacher_nid_pic',
                    300 * 1024,
                );
            }
            if ($changes !== []) {
                $sets = [];
                foreach ($changes as $column => $value) {
                    $sets[] = "`{$column}` = :{$column}";
                }
                $update = $db->prepare('UPDATE teacher_info SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1');
                $changes['id'] = $teacherId;
                $update->execute($changes);
            }
            $db->commit();
        } catch (RuntimeException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $cleanupSavedImages();
            $errors['image'] = userFacingError($exception);
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $cleanupSavedImages();
            error_log($exception->getMessage());
            $errors['form'] = 'Could not save this teacher. Check the required fields and try again.';
        }
        if ($errors === []) {
            header('Location: profile.php?teacher_mobile=' . rawurlencode($values['teacher_mobile']) . '&saved=1', true, 303);
            exit;
        }
    }
}

function addTeacherEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function addTeacherValue(array $values, string $field): string
{
    return addTeacherEscape((string) ($values[$field] ?? ''));
}
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Add Teacher | School Admin';
$pageStyles = '.nid-crop-preview { max-width: 60vw; max-height: 60vh; margin: 0 auto; overflow: hidden; } .nid-crop-preview img { max-width: 60vw; max-height: 60vh; width: auto; height: auto; }';
$pageScripts = [<<<'JS'
(() => {
  const form = document.getElementById('addTeacherForm');
  const pictureInput = document.getElementById('teacherPicture');
  const pictureData = document.getElementById('teacherPicData');
  const cropImage = document.getElementById('cropImage');
  const cropPreview = document.getElementById('cropPreview');
  const nidInput = document.getElementById('teacherNidPicture');
  const nidData = document.getElementById('teacherNidPicData');
  const nidImage = document.getElementById('nidCropImage');
  const nidPreview = document.getElementById('nidCropPreview');
  let cropper = null;
  let nidCropper = null;
  const imageReader = (input, image, preview, callback) => {
    const file = input.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) { input.setCustomValidity('Please choose an image file.'); return; }
    input.setCustomValidity('');
    const reader = new FileReader();
    reader.onload = () => { image.src = reader.result; preview.style.display = 'block'; callback(); };
    reader.readAsDataURL(file);
  };
  pictureInput.addEventListener('change', () => imageReader(pictureInput, cropImage, cropPreview, () => {
    if (cropper) cropper.destroy();
    cropper = new Cropper(cropImage, { aspectRatio: 108 / 137, viewMode: 1, autoCropArea: 1 });
  }));
  nidInput.addEventListener('change', () => imageReader(nidInput, nidImage, nidPreview, () => {
    if (nidCropper) nidCropper.destroy();
    nidCropper = new Cropper(nidImage, { viewMode: 1, autoCropArea: 1 });
  }));
  const compressedCrop = (sourceCanvas, maxWidth, maxHeight, maxLength) => {
    let width = sourceCanvas.width;
    let height = sourceCanvas.height;
    const scale = Math.min(1, maxWidth / width, maxHeight / height);
    width = Math.max(1, Math.round(width * scale));
    height = Math.max(1, Math.round(height * scale));
    const canvas = document.createElement('canvas');
    let dataUrl = '';
    for (let attempt = 0; attempt < 8; attempt += 1) {
      canvas.width = width;
      canvas.height = height;
      canvas.getContext('2d').drawImage(sourceCanvas, 0, 0, width, height);
      let quality = 0.9;
      dataUrl = canvas.toDataURL('image/jpeg', quality);
      while (dataUrl.length > maxLength && quality > 0.45) {
        quality -= 0.05;
        dataUrl = canvas.toDataURL('image/jpeg', quality);
      }
      if (dataUrl.length <= maxLength) return dataUrl;
      width = Math.max(1, Math.round(width * 0.8));
      height = Math.max(1, Math.round(height * 0.8));
    }
    return dataUrl;
  };
  form.addEventListener('submit', (event) => {
    if (cropper) {
      const data = compressedCrop(cropper.getCroppedCanvas({ width: 108, height: 137, imageSmoothingQuality: 'high' }), 108, 137, 136500);
      if (data.length > 136500) { event.preventDefault(); pictureInput.setCustomValidity('Cropped picture must be under 100KB.'); return; }
      pictureData.value = data;
    }
    if (nidCropper) {
      const data = compressedCrop(nidCropper.getCroppedCanvas({ imageSmoothingQuality: 'high' }), 1200, 1200, 408000);
      if (data.length > 408000) { event.preventDefault(); nidInput.setCustomValidity('Cropped NID picture must be under 300KB.'); return; }
      nidData.value = data;
    }
  });
  const selectors = {
    division: document.getElementById('teacher_division'),
    district: document.getElementById('teacher_district'),
    upazila: document.getElementById('teacher_upazilla')
  };
  const loadChildren = async (parent, child, tableChild) => {
    const parentId = selectors[parent].value;
    selectors[child].innerHTML = '<option value="">Select</option>';
    selectors[child].disabled = !parentId;
    if (!parentId) return;
    const response = await fetch(`profile.php?location=${tableChild}&parent_id=${encodeURIComponent(parentId)}`);
    if (!response.ok) throw new Error('Unable to load address options.');
    (await response.json()).forEach((option) => selectors[child].add(new Option(option.name, option.id)));
  };
  selectors.division.addEventListener('change', () => {
    selectors.district.value = ''; selectors.upazila.innerHTML = '<option value="">Select</option>'; selectors.upazila.disabled = true;
    loadChildren('division', 'district', 'district').catch(console.error);
  });
  selectors.district.addEventListener('change', () => {
    selectors.upazila.value = ''; loadChildren('district', 'upazila', 'upazila').catch(console.error);
  });
})();
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div><h1 class="mb-1 fs-3">Add New Teacher / Employee</h1><p class="text-secondary mb-0">Personal and professional information</p></div>
          <a href="view_all_teacherlist.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div></div>
        <div class="app-content"><div class="container-fluid">
          <?php if ($errors !== []): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= isset($errors['form']) ? addTeacherEscape($errors['form']) : 'Please correct the highlighted fields.' ?></div><?php endif; ?>
          <form method="post" id="addTeacherForm" class="card card-outline card-primary">
            <?= csrfField() ?>
            <div class="card-body"><div class="row g-3">
          <div class="col-12"><div class="border-top pt-3"><h2 class="h6 text-primary"><i class="bi bi-person-vcard me-2"></i>Personal Information</h2></div></div>
          <div class="col-12"><label class="form-label">Teacher Picture</label><input class="form-control" type="file" id="teacherPicture" accept="image/jpeg,image/png,image/webp"><input type="hidden" name="teacher_pic_data" id="teacherPicData"><small class="text-secondary">Output: 108×137px JPEG, maximum 100KB.</small><div id="cropPreview" class="mt-2" style="max-width:320px;display:none"><img id="cropImage" alt="Picture crop preview" style="max-width:100%"></div></div>
          <div class="col-12"><label class="form-label">Teacher NID Picture</label><input class="form-control" type="file" id="teacherNidPicture" accept="image/jpeg,image/png,image/webp"><input type="hidden" name="teacher_nid_pic_data" id="teacherNidPicData"><small class="text-secondary">Output is proportionally limited to 1200×1200px, JPEG, maximum 300KB.</small><div id="nidCropPreview" class="nid-crop-preview mt-2" style="display:none"><img id="nidCropImage" alt="NID picture crop preview"></div></div>
          <?php
          $personal = [
              'teacher_name' => 'Name', 'teacher_father_name' => 'Father', 'teacher_mother_name' => 'Mother',
              'teacher_nid_no' => 'NID Number', 'teacher_mobile' => 'Mobile', 'teacher_email' => 'Email',
              'teacher_birth_date' => 'Birth Date', 'teacher_tin_no' => 'TIN', 'teacher_account_no' => 'Account No.',
          ];
          foreach ($personal as $field => $label):
          ?><div class="col-md-6"><label class="form-label"><?= $label ?><?php if (in_array($field, ['teacher_name', 'teacher_mobile', 'teacher_birth_date'], true)): ?> <span class="text-danger">*</span><?php endif; ?></label><input class="form-control <?= isset($errors[$field]) ? 'is-invalid' : '' ?>" name="<?= $field ?>" value="<?= addTeacherValue($values, $field) ?>" <?= $field === 'teacher_email' ? 'type="email"' : ($field === 'teacher_birth_date' ? 'type="date"' : 'type="text"') ?> <?= in_array($field, ['teacher_name', 'teacher_mobile', 'teacher_birth_date'], true) ? 'required' : '' ?>><?php if (isset($errors[$field])): ?><div class="invalid-feedback"><?= addTeacherEscape($errors[$field]) ?></div><?php endif; ?></div>
          <?php endforeach; ?>
          <div class="col-md-6"><label class="form-label">Login password</label><input class="form-control" type="password" name="teacher_password" autocomplete="new-password"><small class="text-secondary">Leave empty to use the mobile number as the first password.</small></div>
          <div class="col-md-6"><label class="form-label">Religion</label><select class="form-select" name="teacher_religion"><option value="">Select Religion</option><?php foreach ($religionOptions as $key => $label): ?><option value="<?= addTeacherEscape($key) ?>" <?= $values['teacher_religion'] === $key ? 'selected' : '' ?>><?= addTeacherEscape($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Gender <span class="text-danger">*</span></label><select class="form-select <?= isset($errors['teacher_gender']) ? 'is-invalid' : '' ?>" name="teacher_gender" required><option value="">Choose...</option><?php foreach ($genderOptions as $key => $label): ?><option value="<?= addTeacherEscape($key) ?>" <?= $values['teacher_gender'] === $key ? 'selected' : '' ?>><?= addTeacherEscape($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><div class="border-top pt-3 mt-3"><h2 class="h6 text-primary"><i class="bi bi-briefcase me-2"></i>Professional Information</h2></div></div>
          <div class="col-md-6"><label class="form-label">Designation <span class="text-danger">*</span></label><select class="form-select <?= isset($errors['teacher_designation']) ? 'is-invalid' : '' ?>" name="teacher_designation" required><option value="">Choose...</option><?php foreach ($designationOptions as $key => $label): ?><option value="<?= addTeacherEscape($key) ?>" <?= $values['teacher_designation'] === $key ? 'selected' : '' ?>><?= addTeacherEscape($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label">Subject <span class="text-danger">*</span></label><select class="form-select <?= isset($errors['teacher_subject']) ? 'is-invalid' : '' ?>" name="teacher_subject" required><option value="">Choose...</option><?php foreach ($subjectOptions as $key => $label): ?><option value="<?= addTeacherEscape($key) ?>" <?= $values['teacher_subject'] === $key ? 'selected' : '' ?>><?= addTeacherEscape($label) ?></option><?php endforeach; ?></select></div>
          <?php foreach (['teacher_salary_code' => 'Salary Code', 'teacher_index_number' => 'Index No.', 'teacher_pds_id' => 'PDS ID', 'first_joining_date' => 'Joining Date', 'teacher_joining_date' => 'Curr. Ins. Join Date', 'teacher_mpo_date' => 'MPO Date'] as $field => $label): ?><div class="col-md-6"><label class="form-label"><?= $label ?></label><input class="form-control" name="<?= $field ?>" value="<?= addTeacherValue($values, $field) ?>" <?= str_contains($field, 'date') ? 'type="date"' : 'type="text"' ?>></div><?php endforeach; ?>
          <div class="col-md-6"><label class="form-label">Education Details</label><textarea class="form-control" name="educational_details" rows="3"><?= addTeacherValue($values, 'educational_details') ?></textarea></div>
          <div class="col-md-6"><label class="form-label">Training Details</label><textarea class="form-control" name="training_details" rows="3"><?= addTeacherValue($values, 'training_details') ?></textarea></div>
          <div class="col-12"><div class="border-top pt-3 mt-3"><h2 class="h6 text-primary"><i class="bi bi-geo-alt me-2"></i>Address Information</h2></div></div>
          <div class="col-12 col-md-4"><label class="form-label" for="teacher_division">Division</label><select class="form-select" id="teacher_division" name="teacher_division"><option value="">Select</option><?php foreach ($db->query('SELECT id, name FROM divisions ORDER BY name')->fetchAll() as $row): ?><option value="<?= (int) $row['id'] ?>" <?= $values['teacher_division'] === (string) $row['id'] ? 'selected' : '' ?>><?= addTeacherEscape((string) $row['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12 col-md-4"><label class="form-label" for="teacher_district">District</label><select class="form-select" id="teacher_district" name="teacher_district" disabled><option value="">Select</option></select></div>
          <div class="col-12 col-md-4"><label class="form-label" for="teacher_upazilla">Upazilla</label><select class="form-select" id="teacher_upazilla" name="teacher_upazilla" disabled><option value="">Select</option></select></div>
          <?php foreach ($addressFields as $field => $label): ?><div class="col-12 col-md-4"><label class="form-label" for="<?= $field ?>"><?= $label ?></label><input class="form-control" id="<?= $field ?>" name="<?= $field ?>" value="<?= addTeacherValue($values, $field) ?>"></div><?php endforeach; ?>
        </div></div>
          <div class="card-footer text-end"><button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Submit</button></div>
          </form>
        </div></div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
