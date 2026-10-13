<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$requestedMobile = trim((string) ($_GET['teacher_mobile'] ?? ''));
if ($requestedMobile !== '') {
    $teacherStatement = getDatabaseConnection()->prepare(
        'SELECT * FROM teacher_info WHERE teacher_mobile = :mobile LIMIT 1'
    );
    $teacherStatement->execute(['mobile' => $requestedMobile]);
    $teacher = $teacherStatement->fetch() ?: null;
} else {
    $teacher = getAuthenticatedTeacher();
}
if ($teacher === null) {
    http_response_code(404);
    exit('Teacher profile not found.');
}

$viewer = getAuthenticatedTeacher();
if ($viewer === null) {
    header('Location: ../examples/login-v2.php', true, 302);
    exit;
}
$isOwnProfile = (int) ($viewer['id'] ?? 0) === (int) ($teacher['id'] ?? 0);
if (!$isOwnProfile && !canAccessModule('teachers')) {
    denyPanelAccess();
}
$canEditStaffFields = canAccessModule('teachers');
$canAssignRoles = isHeadAdmin();

$db = getDatabaseConnection();
$locationTables = [
    'division' => ['table' => 'divisions', 'column' => 'teacher_division'],
    'district' => ['table' => 'districts', 'column' => 'teacher_district'],
    'upazila' => ['table' => 'upazilas', 'column' => 'teacher_upazilla'],
];

if (isset($_GET['location'])) {
    $location = (string) $_GET['location'];
    $parentId = filter_input(INPUT_GET, 'parent_id', FILTER_VALIDATE_INT);
    $parents = [
        'district' => ['table' => 'districts', 'foreign_key' => 'division_id'],
        'upazila' => ['table' => 'upazilas', 'foreign_key' => 'district_id'],
    ];

    header('Content-Type: application/json; charset=utf-8');
    if (!isset($parents[$location]) || $parentId === false || $parentId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid location request']);
        exit;
    }

    $query = $db->prepare(
        'SELECT id, name FROM ' . $parents[$location]['table'] .
        ' WHERE ' . $parents[$location]['foreign_key'] . ' = :parent_id ORDER BY name'
    );
    $query->execute(['parent_id' => $parentId]);
    echo json_encode($query->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

$locationValues = [];
foreach ($locationTables as $key => $location) {
    $locationValues[$key] = (int) ($teacher[$location['column']] ?? 0);
}

$locationNames = [];
foreach ($locationTables as $key => $location) {
    if ($locationValues[$key] > 0) {
        $query = $db->prepare('SELECT name FROM ' . $location['table'] . ' WHERE id = :id LIMIT 1');
        $query->execute(['id' => $locationValues[$key]]);
        $locationNames[$key] = (string) ($query->fetchColumn() ?: '');
    } else {
        $locationNames[$key] = '';
    }
}
$addressFields = [
    'teacher_post_office' => 'Post Office',
    'teacher_village' => 'Village',
    'teacher_house' => 'House',
];
$addressValues = [];
foreach (array_keys($addressFields) as $field) {
    $addressValues[$field] = (string) ($teacher[$field] ?? '');
}
$addressParts = [];
if ($addressValues['teacher_house'] !== '') {
    $addressParts[] = $addressValues['teacher_house'];
}
if ($addressValues['teacher_village'] !== '') {
    $addressParts[] = 'Village: ' . $addressValues['teacher_village'];
}
if ($addressValues['teacher_post_office'] !== '') {
    $addressParts[] = 'Post Office: ' . $addressValues['teacher_post_office'];
}
if ($locationNames['upazila'] !== '') {
    $addressParts[] = 'Upazilla: ' . $locationNames['upazila'];
}
if ($locationNames['district'] !== '') {
    $addressParts[] = 'District: ' . $locationNames['district'];
}
$profileAddress = implode(', ', $addressParts);

$profileFields = [
    'teacher_name', 'father', 'mother', 'religion', 'gender', 'nid_number',
    'teacher_mobile', 'email', 'birth_date', 'tin', 'account_no',
    'teacher_designation', 'subject', 'salary_code', 'teacher_index', 'pds_id',
    'first_joining_date', 'first_mpo_date', 'current_joining_date',
    'education_details', 'training_details',
];
$pictureColumn = 'teacher_pic';
$picturePath = (string) ($teacher[$pictureColumn] ?? '');
$nidPictureColumn = 'teacher_nid_pic';
$nidPicturePath = (string) ($teacher[$nidPictureColumn] ?? '');

$subjectOptions = [
    'bangla' => 'Bangla',
    'english' => 'English',
    'mathematics' => 'Mathematics',
    'religion_islam' => 'Religion (Islam)',
    'religion_hindu' => 'Religion (Hindu)',
    'ict' => 'ICT',
    'biology' => 'Biology',
    'chemistry' => 'Chemistry',
    'physics' => 'Physics',
    'business_studies' => 'Business Studies',
    'accounting' => 'Accounting',
    'finance&banking' => 'Finance & Banking',
    'social_science' => 'Social Science',
    'physical_education' => 'Physical Education',
    'agricultural_science' => 'Agricultural Science',
    'home_science' => 'Home Science',
    'library_science' => 'Library Science',
    'not_applicable' => 'Not Applicable',
];
$designationOptions = getDesignationOptions();
$religionOptions = [
    'Islam' => 'Islam',
    'Hindu' => 'Hindu',
    'Buddhist' => 'Buddhist',
    'Christian' => 'Christian',
];
$genderOptions = [
    'male' => 'Male',
    'female' => 'Female',
];

$columnMap = [
    'father' => 'teacher_father_name',
    'mother' => 'teacher_mother_name',
    'religion' => 'teacher_religion',
    'gender' => 'teacher_gender',
    'birth_date' => 'teacher_birth_date',
    'nid_number' => 'teacher_nid_no',
    'tin' => 'teacher_tin_no',
    'email' => 'teacher_email',
    'education_details' => 'educational_details',
    'teacher_index' => 'teacher_index_number',
    'pds_id' => 'teacher_pds_id',
    'subject' => 'teacher_subject',
    'salary_code' => 'teacher_salary_code',
    'account_no' => 'teacher_account_no',
    'first_joining_date' => 'first_joining_date',
    'current_joining_date' => 'teacher_joining_date',
];
$profile = array_fill_keys($profileFields, '');
foreach ($profileFields as $field) {
    $column = $columnMap[$field] ?? $field;
    if (array_key_exists($column, $teacher) && $teacher[$column] !== null) {
        $profile[$field] = (string) $teacher[$column];
    }
}

$requiredFields = [
    'teacher_name', 'father', 'mother', 'teacher_designation',
    'subject', 'teacher_mobile', 'birth_date', 'first_joining_date', 'gender',
];
if (!$canEditStaffFields) {
    $requiredFields = array_values(array_diff($requiredFields, ['teacher_designation', 'subject']));
}
$errors = [];
$roleSaved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        if (!$canEditStaffFields) {
            denyPanelAccess();
        }
        $teacherId = (int) ($teacher['id'] ?? 0);
        try {
            if ($isOwnProfile) {
                throw new RuntimeException('You cannot delete your own staff record.');
            }
            if (currentPanelRole($teacher) === 'head' && headAdminCount($db) <= 1) {
                throw new RuntimeException('The last Head Teacher account cannot be deleted.');
            }
            deleteTeacherRecord($db, $teacherId);
            redirectWithMessage('view_all_teacherlist.php', 'success', 'Staff record and files deleted.');
        } catch (Throwable $exception) {
            $profileQuery = ((string) ($teacher['teacher_mobile'] ?? '')) !== ''
                ? '?teacher_mobile=' . rawurlencode((string) $teacher['teacher_mobile'])
                : '';
            redirectWithMessage('profile.php' . $profileQuery, 'danger', userFacingError($exception));
        }
    }
    $oldMobile = (string) ($teacher['teacher_mobile'] ?? '');
    $changes = [];
    $parameters = ['current_mobile' => $oldMobile];
    foreach (array_keys($profile) as $field) {
        if ($field === 'teacher_mobile') {
            continue;
        }
        if (array_key_exists($field, $_POST)) {
            if (!$canEditStaffFields && in_array($field, ['teacher_designation', 'subject', 'salary_code'], true)) {
                continue;
            }
            $value = trim((string) $_POST[$field]);
            $column = $columnMap[$field] ?? $field;
            if (array_key_exists($column, $teacher)) {
                $changes[$column] = $value;
            }
        }
    }
    if (!empty($_POST['teacher_pic_data']) && array_key_exists($pictureColumn, $teacher)) {
        try {
            $fileName = 'teacher_' . preg_replace('/[^A-Za-z0-9_-]/', '', $oldMobile) . '.jpg';
            $changes[$pictureColumn] = 'teacher_pic/' . saveCroppedJpeg(
                (string) $_POST['teacher_pic_data'],
                'teacher_pic',
                $fileName,
                100 * 1024,
                108,
                137
            );
            replacePublicMediaFile('teacher_pic', $picturePath, $changes[$pictureColumn]);
            $picturePath = $changes[$pictureColumn];
        } catch (RuntimeException $exception) {
            $errors['teacher_pic'] = userFacingError($exception);
        }
    }
    if (!empty($_POST['teacher_nid_pic_data']) && array_key_exists($nidPictureColumn, $teacher)) {
        try {
            $fileName = 'nid_' . preg_replace('/[^A-Za-z0-9_-]/', '', $oldMobile) . '.jpg';
            $changes[$nidPictureColumn] = 'teacher_nid_pic/' . savePrivateCroppedJpeg(
                (string) $_POST['teacher_nid_pic_data'],
                'teacher_nid_pic',
                $fileName,
                300 * 1024
            );
            replacePrivateMediaFile('teacher_nid_pic', $nidPicturePath, $changes[$nidPictureColumn]);
            $nidPicturePath = $changes[$nidPictureColumn];
        } catch (RuntimeException $exception) {
            $errors['teacher_nid_pic'] = userFacingError($exception);
        }
    }
    foreach ($locationTables as $location) {
        $column = $location['column'];
        if (array_key_exists($column, $_POST) && array_key_exists($column, $teacher)) {
            $value = filter_var($_POST[$column], FILTER_VALIDATE_INT);
            $changes[$column] = $value === false ? 0 : $value;
        }
    }
    foreach (array_keys($addressFields) as $field) {
        if (array_key_exists($field, $_POST) && array_key_exists($field, $teacher)) {
            $changes[$field] = trim((string) $_POST[$field]);
        }
    }
    if (isset($changes['teacher_religion']) && !array_key_exists((string) $changes['teacher_religion'], $religionOptions)) {
        unset($changes['teacher_religion']);
        $errors['religion'] = 'Please select a valid religion.';
    }
    if (isset($changes['teacher_gender']) && !array_key_exists((string) $changes['teacher_gender'], $genderOptions)) {
        unset($changes['teacher_gender']);
        $errors['gender'] = 'Please select a valid gender.';
    }
    foreach ($requiredFields as $field) {
        if (trim((string) ($_POST[$field] ?? '')) === '') {
            $errors[$field] = 'This field is required.';
        }
    }

    if ($canAssignRoles && isset($_POST['panel_role'])) {
        try {
            $modules = isset($_POST['full_admin']) ? ['*'] : (array) ($_POST['modules'] ?? []);
            saveTeacherPanelRole(
                $db,
                (int) $teacher['id'],
                (string) $_POST['panel_role'],
                $modules,
                (int) $viewer['id']
            );
            $roleSaved = true;
        } catch (Throwable $exception) {
            $errors['panel_role'] = userFacingError($exception);
        }
    }

    if ($errors === [] && ($changes !== [] || $roleSaved)) {
        if ($changes !== []) {
            $assignments = [];
            foreach ($changes as $column => $value) {
                $assignments[] = "`{$column}` = :{$column}";
                $parameters[$column] = $value;
            }
            $statement = getDatabaseConnection()->prepare(
                'UPDATE teacher_info SET ' . implode(', ', $assignments) . '
                 WHERE teacher_mobile = :current_mobile
                 LIMIT 1'
            );
            $statement->execute($parameters);
        }
        $redirectQuery = $oldMobile !== '' ? '?teacher_mobile=' . rawurlencode($oldMobile) . '&saved=1' : '?saved=1';
        header('Location: profile.php' . $redirectQuery, true, 303);
        exit;
    }
}

function profileValue(array $profile, string $field): string
{
    return htmlspecialchars((string) ($profile[$field] ?? ''), ENT_QUOTES, 'UTF-8');
}

function dateInputValue(string $value): string
{
    $date = DateTime::createFromFormat('d-m-Y', $value);

    return $date ? $date->format('Y-m-d') : $value;
}

function displayDate(string $value): string
{
    foreach (['d-m-Y', 'Y-m-d'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date !== false) {
            return $date->format('d-m-Y');
        }
    }

    return $value;
}

function subjectLabel(array $options, string $value): string
{
    return $options[$value] ?? $value;
}

function subjectKey(array $options, string $value): string
{
    $key = array_search($value, $options, true);

    return $key === false ? $value : (string) $key;
}

$designationValue = getDesignationLabel((string) $profile['teacher_designation']);
$teacherInitials = strtoupper(substr((string) $profile['teacher_name'], 0, 2));
$saved = isset($_GET['saved']);
$profileQuery = $profile['teacher_mobile'] !== ''
    ? '?teacher_mobile=' . rawurlencode((string) $profile['teacher_mobile'])
    : '';
$fieldLabels = [
    'teacher_name' => 'Name',
    'teacher_designation' => 'Designation',
    'teacher_mobile' => 'Mobile',
    'teacher_index' => 'Index No.',
    'first_joining_date' => 'Joining Date',
    'first_mpo_date' => 'Mpo Date',
    'current_joining_date' => 'Curr. Ins. Join Date',
    'account_no' => 'Account Info.',
];
$pictureDisplayUrl = schoolFileUrl($picturePath, 'teacher_pic');
$panelFavicon = schoolFileUrl((string) (schoolSettings(getDatabaseConnection())['logo'] ?? ''), '', publicSiteUrl('image/school_logo.png'));
$nidPictureDisplayUrl = $nidPicturePath !== ''
    ? signedRecordUrl('private-media.php', (int) ($teacher['id'] ?? 0), 'teacher', ['kind' => 'teacher-nid'])
    : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
  <title>Teacher Profile | AdminLTE</title>
  <link rel="icon" href="<?= h($panelFavicon) ?>">
  <link rel="shortcut icon" href="<?= h($panelFavicon) ?>">
  <link rel="stylesheet" href="../css/adminlte.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
  <style>
    .nid-crop-preview {
      width: auto;
      height: auto;
      max-width: 60vw;
      max-height: 60vh;
      margin-left: auto;
      margin-right: auto;
      overflow: hidden;
    }
    .nid-crop-preview img {
      max-width: 60vw;
      max-height: 60vh;
      width: auto;
      height: auto;
      margin-left: auto;
      margin-right: auto;
    }
    .nid-picture-display {
      display: block;
      width: auto;
      height: auto;
      max-width: 100%;
      max-height: 520px;
      margin: 0 auto;
    }
    .app-content-header .container-fluid {
      flex-wrap: wrap;
      gap: .5rem;
    }
    .profile-header-title {
      flex: 1 0 100%;
      min-width: 0;
    }
    .profile-actions {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: .5rem;
      width: 100%;
    }
    .profile-actions .btn {
      min-width: 0;
      margin: 0 !important;
      white-space: normal;
    }
    .app-content .row > [class*="col-"] {
      min-width: 0;
    }
    .app-content .card-body strong {
      overflow-wrap: anywhere;
    }
    @media (max-width: 991.98px) {
      .profile-actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .app-content-header .container-fluid {
        align-items: stretch !important;
      }
    }
    @media (max-width: 576px) {
      .nid-picture-display {
        max-height: 320px;
      }
    }
    @media (min-width: 577px) and (max-width: 991px) {
      .nid-picture-display {
        max-height: 420px;
      }
    }
    @media print {
      @page {
        size: A4 portrait;
        margin: 12mm;
      }
      html,
      body {
        background: #fff !important;
      }
      .app-header,
      .app-sidebar,
      .app-footer,
      .app-content-header,
      .no-print,
      #editProfile {
        display: none !important;
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
      }
      .app-content > .container-fluid > .row {
        display: flex !important;
        flex-wrap: nowrap !important;
        gap: 8mm !important;
        margin: 0 !important;
      }
      .app-content .col-lg-4 {
        flex: 0 0 34% !important;
        max-width: 34% !important;
      }
      .app-content .col-lg-8 {
        flex: 1 1 auto !important;
        max-width: none !important;
      }
      .app-content .card-body {
        padding: 3mm !important;
      }
      .app-content .card-header {
        padding: 2mm 3mm !important;
      }
      .app-content .row.g-3 {
        --bs-gutter-x: 2mm !important;
        --bs-gutter-y: 2mm !important;
      }
      .app-content .col-md-6 {
        flex: 0 0 50% !important;
        max-width: 50% !important;
      }
      .app-content p {
        margin-bottom: 1.5mm !important;
        font-size: 9pt !important;
      }
      .app-content small,
      .app-content strong {
        font-size: 8.5pt !important;
      }
      .app-content h2 {
        font-size: 14pt !important;
      }
      .app-content h3 {
        font-size: 10pt !important;
      }
      .card {
        border: 1px solid #adb5bd !important;
        box-shadow: none !important;
        break-inside: avoid;
      }
      .card-header {
        background: #e9ecef !important;
        color: #212529 !important;
      }
      .nid-picture-display {
        max-width: 100%;
        max-height: 105mm;
        object-fit: contain;
      }
      .text-secondary {
        color: #495057 !important;
      }
    }
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
  <div class="app-wrapper">
    <?php $basePath = '../'; include __DIR__ . '/../includes/navbar.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="app-main">
      <div class="app-content-header">
        <div class="container-fluid d-flex justify-content-between align-items-center">
          <div class="profile-header-title">
            <h1 class="mb-1 fs-3">Teacher Profile</h1>
            <p class="text-secondary mb-0">Personal and professional information</p>
          </div>
          <div class="profile-actions no-print">
            <a class="btn btn-outline-secondary" href="change-password.php">
              <i class="bi bi-key me-1"></i> Change Password
            </a>
            <a class="btn btn-outline-success" href="<?= h(signedRecordUrl('teacher-id-card.php', (int) ($teacher['id'] ?? 0), 'teacher')) ?>" target="_blank" rel="noopener">
              <i class="bi bi-credit-card-2-front me-1"></i> Print ID Card
            </a>
            <button class="btn btn-outline-secondary" type="button" onclick="window.print()">
              <i class="bi bi-printer me-1"></i> Print Profile
            </button>
            <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#editProfile">
              <i class="bi bi-pencil-square me-1"></i> Edit Profile
            </button>
          </div>
        </div>
      </div>
      <div class="app-content">
        <div class="container-fluid">
          <?php include __DIR__ . '/../includes/flash.php'; ?>
          <?php if ($saved): ?><div id="profileUpdateAlert" class="alert alert-success fade show"><i class="bi bi-check-circle me-1"></i>Profile updated successfully.</div><?php endif; ?>
          <?php if ($errors !== []): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>Please fill in all mandatory fields.</div><?php endif; ?>
          <div class="row g-3">
            <div class="col-lg-4">
              <div class="card card-primary card-outline h-100">
                <div class="card-body text-center">
                  <?php if ($pictureDisplayUrl !== ''): ?>
                    <img src="<?= h($pictureDisplayUrl) ?>" alt="<?= profileValue($profile, 'teacher_name') ?>" class="rounded-circle mb-3" style="width:108px;height:137px;object-fit:cover">
                  <?php else: ?>
                    <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center mb-3" style="width:110px;height:110px;font-size:2.4rem"><?= profileValue(['x' => $teacherInitials], 'x') ?></div>
                  <?php endif; ?>
                  <h2 class="h4 mb-1"><?= profileValue($profile, 'teacher_name') ?></h2>
                  <?php if (trim((string) $designationValue) !== ''): ?>
                  <p class="text-secondary mb-3"><?= profileValue(['value' => $designationValue], 'value') ?></p>
                  <?php endif; ?>
                  <div class="text-start border-top pt-3">
                    <?php if (trim((string) ($profile['teacher_mobile'] ?? '')) !== ''): ?>
                    <p><i class="bi bi-telephone text-primary me-2"></i><?= profileValue($profile, 'teacher_mobile') ?></p>
                    <?php endif; ?>
                    <?php if (trim((string) ($profile['email'] ?? '')) !== ''): ?>
                    <p><i class="bi bi-envelope text-primary me-2"></i><?= profileValue($profile, 'email') ?></p>
                    <?php endif; ?>
                    <?php if (trim((string) $profileAddress) !== ''): ?>
                    <p class="mb-0"><i class="bi bi-geo-alt text-primary me-2"></i><?= profileValue(['value' => $profileAddress], 'value') ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="border-top mt-3 pt-3 text-start d-flex flex-column align-items-start no-print">
                     <h3 class="h6 text-primary mb-1"><i class="bi bi-card-image me-2"></i>Teacher NID Picture</h3>
                     <?php if ($nidPictureDisplayUrl !== ''): ?>
                       <img src="<?= h($nidPictureDisplayUrl) ?>" alt="Teacher NID" class="nid-picture-display img-fluid rounded shadow-sm">
                     <?php else: ?>
                       <p class="text-secondary small mb-0">NID picture has not been uploaded yet.</p>
                     <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-lg-8">
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title"><i class="bi bi-person-vcard me-2"></i>Personal Information</h3></div>
                <div class="card-body"><div class="row g-3">
                  <?php foreach (['father' => 'Father', 'mother' => 'Mother', 'religion' => 'Religion', 'gender' => 'Gender', 'birth_date' => 'Birth Date', 'nid_number' => 'NID Number', 'tin' => 'TIN', 'email' => 'Email', 'teacher_mobile' => 'Mobile'] as $field => $label): ?>
                    <?php
                      $profileFieldValue = $field === 'birth_date'
                        ? displayDate((string) ($profile[$field] ?? ''))
                        : ($field === 'religion'
                          ? (string) ($religionOptions[$profile[$field] ?? ''] ?? ($profile[$field] ?? ''))
                          : ($field === 'gender'
                            ? (string) ($genderOptions[$profile[$field] ?? ''] ?? ($profile[$field] ?? ''))
                            : (string) ($profile[$field] ?? '')));
                      if (trim($profileFieldValue) === '' || str_starts_with($profileFieldValue, '0000-00-00')) {
                          continue;
                      }
                    ?>
                    <div class="col-md-6"><small class="text-secondary d-block"><?= $label ?></small><strong><?= htmlspecialchars($profileFieldValue, ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <?php endforeach; ?>
                  <?php if (trim((string) ($profile['education_details'] ?? '')) !== ''): ?>
                  <div class="col-12"><small class="text-secondary d-block">Education Details</small><strong><?= profileValue($profile, 'education_details') ?></strong></div>
                  <?php endif; ?>
                </div></div>
              </div>
              <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="bi bi-briefcase me-2"></i>Professional Information</h3></div>
                <div class="card-body"><div class="row g-3">
                  <?php foreach (['teacher_index' => 'Index No.', 'pds_id' => 'PDS ID', 'subject' => 'Subject', 'salary_code' => 'Salary Code', 'first_joining_date' => 'Joining Date', 'first_mpo_date' => 'Mpo Date', 'current_joining_date' => 'Curr. Ins. Join Date'] as $field => $label): ?>
                    <?php
                      $profileFieldValue = $field === 'subject'
                        ? subjectLabel($subjectOptions, (string) ($profile[$field] ?? ''))
                        : (in_array($field, ['first_joining_date', 'first_mpo_date', 'current_joining_date'], true)
                          ? displayDate((string) ($profile[$field] ?? ''))
                          : (string) ($profile[$field] ?? ''));
                      if (trim($profileFieldValue) === '' || str_starts_with($profileFieldValue, '0000-00-00')) {
                          continue;
                      }
                    ?>
                    <div class="col-md-6"><small class="text-secondary d-block"><?= $label ?></small><strong><?= htmlspecialchars($profileFieldValue, ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <?php endforeach; ?>
                  <?php if (trim((string) ($profile['account_no'] ?? '')) !== ''): ?>
                  <div class="col-12"><small class="text-secondary d-block">Accounts Info</small><strong><?= profileValue($profile, 'account_no') ?></strong></div>
                  <?php endif; ?>
                  <?php if (trim((string) ($profile['training_details'] ?? '')) !== ''): ?>
                  <div class="col-12"><small class="text-secondary d-block">Training Details</small><strong><?= profileValue($profile, 'training_details') ?></strong></div>
                  <?php endif; ?>
                </div></div>
              </div>
            </div>
          </div>
          <div class="collapse mt-3" id="editProfile">
            <form method="post" action="profile.php<?= htmlspecialchars($profileQuery, ENT_QUOTES, 'UTF-8') ?>" id="profileForm" class="card card-outline card-warning">
              <?= csrfField() ?>
              <div class="card-header"><h3 class="card-title">Edit Profile</h3></div>
              <div class="card-body"><div class="row g-3">
                <div class="col-12">
                  <label class="form-label" for="teacherPicture">Teacher Picture</label>
                  <input class="form-control" type="file" id="teacherPicture" accept="image/jpeg,image/png,image/webp">
                  <input type="hidden" name="teacher_pic_data" id="teacherPicData">
                  <small class="text-secondary">Output: 108×137px JPEG, maximum 100KB.</small>
                  <div id="cropPreview" class="mt-2" style="max-width:320px;display:none"><img id="cropImage" alt="Picture crop preview" style="max-width:100%"></div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="teacherNidPicture">Teacher NID Picture</label>
                  <input class="form-control" type="file" id="teacherNidPicture" accept="image/jpeg,image/png,image/webp">
                  <input type="hidden" name="teacher_nid_pic_data" id="teacherNidPicData">
                  <small class="text-secondary">Output is proportionally limited to 1200×1200px, JPEG, maximum 300KB.</small>
                  <div id="nidCropPreview" class="nid-crop-preview mt-2" style="display:none"><img id="nidCropImage" alt="NID picture crop preview"></div>
                </div>
                <div class="col-12 mt-3">
                  <div class="border-top pt-3">
                    <h3 class="h6 text-primary mb-3"><i class="bi bi-person-vcard me-2"></i>Personal Information</h3>
                  </div>
                </div>
                <?php foreach ($profile as $field => $value): ?>
                  <?php if ($field === 'teacher_designation'): ?>
                    <div class="col-12 mt-3">
                      <div class="border-top pt-3">
                        <h3 class="h6 text-primary mb-3"><i class="bi bi-briefcase me-2"></i>Professional Information</h3>
                      </div>
                    </div>
                  <?php endif; ?>
                  <div class="col-md-6">
                    <label class="form-label"><?= $fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field)) ?><?php if (in_array($field, $requiredFields, true)): ?> <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">required</span><?php endif; ?></label>
                    <?php if ($field === 'gender'): ?>
                      <select class="form-select <?= isset($errors[$field]) ? 'is-invalid' : '' ?>" name="gender" required>
                        <option value="">Choose...</option>
                        <?php foreach ($genderOptions as $optionKey => $optionLabel): ?>
                          <option value="<?= profileValue(['value' => $optionKey], 'value') ?>" <?= (string) $value === $optionKey ? 'selected' : '' ?>><?= profileValue(['value' => $optionLabel], 'value') ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($field === 'religion'): ?>
                      <select class="form-select <?= isset($errors[$field]) ? 'is-invalid' : '' ?>" name="religion">
                        <option value="">Select Religion</option>
                        <?php foreach ($religionOptions as $optionKey => $optionLabel): ?>
                          <option value="<?= profileValue(['value' => $optionKey], 'value') ?>" <?= (string) $value === $optionKey ? 'selected' : '' ?>><?= profileValue(['value' => $optionLabel], 'value') ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($field === 'teacher_designation'): ?>
                      <select class="form-select <?= isset($errors[$field]) ? 'is-invalid' : '' ?>" <?= $canEditStaffFields ? 'name="teacher_designation" required' : 'disabled' ?>>
                        <?php foreach ($designationOptions as $optionKey => $optionLabel): ?>
                          <option value="<?= profileValue(['value' => $optionKey], 'value') ?>" <?= ((string) $value === $optionKey || getDesignationLabel((string) $value) === $optionLabel) ? 'selected' : '' ?>><?= profileValue(['value' => $optionLabel], 'value') ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($field === 'subject'): ?>
                      <select class="form-select <?= isset($errors[$field]) ? 'is-invalid' : '' ?>" <?= $canEditStaffFields ? 'name="subject" required' : 'disabled' ?>>
                        <?php foreach ($subjectOptions as $optionKey => $optionLabel): ?>
                          <option value="<?= profileValue(['value' => $optionKey], 'value') ?>" <?= subjectKey($subjectOptions, (string) $value) === $optionKey ? 'selected' : '' ?>><?= profileValue(['value' => $optionLabel], 'value') ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                    <input
                      class="form-control"
                      type="<?= in_array($field, ['birth_date', 'first_joining_date', 'first_mpo_date', 'current_joining_date'], true) ? 'date' : 'text' ?>"
                      <?= (!$canEditStaffFields && $field === 'salary_code') ? 'disabled' : 'name="' . profileValue(['x' => $field], 'x') . '"' ?>
                      <?= in_array($field, $requiredFields, true) ? 'required' : '' ?>
                      <?= $field === 'teacher_mobile' ? 'readonly' : '' ?>
                      value="<?= profileValue(['x' => in_array($field, ['birth_date', 'first_joining_date', 'first_mpo_date', 'current_joining_date'], true) ? dateInputValue((string) $value) : $value], 'x') ?>"
                    >
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <div class="col-12 mt-3">
                  <div class="border-top pt-3">
                    <h3 class="h6 text-primary mb-3"><i class="bi bi-geo-alt me-2"></i>Address Information</h3>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="teacher_division">Division</label>
                  <select class="form-select" id="teacher_division" name="teacher_division">
                    <option value="">Select</option>
                    <?php foreach ($db->query('SELECT id, name FROM divisions ORDER BY name')->fetchAll() as $row): ?>
                      <option value="<?= (int) $row['id'] ?>" <?= $locationValues['division'] === (int) $row['id'] ? 'selected' : '' ?>><?= profileValue($row, 'name') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php foreach (['district' => 'District', 'upazila' => 'Upazilla'] as $key => $label): ?>
                  <?php $parentKey = $key === 'district' ? 'division' : ($key === 'upazila' ? 'district' : 'upazila'); ?>
                  <div class="col-12 col-md-4">
                    <label class="form-label" for="teacher_<?= $key ?>"><?= $label ?></label>
                    <select class="form-select" id="teacher_<?= $key ?>" name="<?= $locationTables[$key]['column'] ?>" data-selected="<?= $locationValues[$key] ?>" <?= $locationValues[$parentKey] > 0 ? '' : 'disabled' ?>>
                      <option value="">Select</option>
                      <?php if ($locationValues[$key] > 0): ?>
                        <option value="<?= $locationValues[$key] ?>" selected><?= profileValue(['value' => $locationNames[$key]], 'value') ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                <?php endforeach; ?>
                <?php foreach ($addressFields as $field => $label): ?>
                  <div class="col-12 col-md-4">
                    <label class="form-label" for="<?= $field ?>"><?= $label ?></label>
                    <input class="form-control" type="text" id="<?= $field ?>" name="<?= $field ?>" value="<?= profileValue($addressValues, $field) ?>">
                  </div>
                <?php endforeach; ?>
                <?php if ($canAssignRoles): ?>
                  <?php
                    $profileRole = currentPanelRole($teacher);
                    $profileMods = decodedAdminModules($teacher);
                    $profileFull = in_array('*', $profileMods, true);
                  ?>
                  <div class="col-12 mt-3">
                    <div class="border-top pt-3">
                      <h3 class="h6 text-primary mb-3"><i class="bi bi-shield-lock me-2"></i>Admin panel access</h3>
                    </div>
                    <?php if (!empty($errors['panel_role'])): ?>
                      <div class="alert alert-danger"><?= htmlspecialchars((string) $errors['panel_role'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="row g-3">
                      <div class="col-md-4">
                        <label class="form-label">Panel role</label>
                        <select class="form-select" name="panel_role" id="profilePanelRole">
                          <?php foreach (panelRoleOptions() as $optionKey => $optionLabel): ?>
                            <option value="<?= htmlspecialchars($optionKey, ENT_QUOTES, 'UTF-8') ?>" <?= $profileRole === $optionKey ? 'selected' : '' ?>><?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-8" id="profileAdminModules" <?= $profileRole === 'admin' ? '' : 'style="display:none"' ?>>
                        <div class="form-check mb-2">
                          <input class="form-check-input" type="checkbox" name="full_admin" value="1" id="profileFullAdmin" <?= $profileFull ? 'checked' : '' ?>>
                          <label class="form-check-label" for="profileFullAdmin">Full admin panel</label>
                        </div>
                        <div class="row g-2">
                          <?php foreach (allAssignablePanelModules() as $key => $label): ?>
                            <div class="col-md-6 col-xl-4">
                              <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="modules[]" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($key, $profileMods, true) ? 'checked' : '' ?>>
                                <label class="form-check-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              </div></div>
              <div class="card-footer text-end"><button class="btn btn-success"><i class="bi bi-save me-1"></i>Save Changes</button></div>
            </form>
            <?php if ($canEditStaffFields && !$isOwnProfile): ?>
            <form method="post" class="mt-3 text-end" onsubmit="return confirm('Delete this staff record and their photos? This cannot be undone.')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete staff and files</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script src="../js/adminlte.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
  <script>
    (() => {
      const form = document.getElementById('profileForm');
      if (!form) return;
      const pictureInput = document.getElementById('teacherPicture');
      const pictureData = document.getElementById('teacherPicData');
      const cropImage = document.getElementById('cropImage');
      const cropPreview = document.getElementById('cropPreview');
      let cropper = null;
      const nidPictureInput = document.getElementById('teacherNidPicture');
      const nidPictureData = document.getElementById('teacherNidPicData');
      const nidCropImage = document.getElementById('nidCropImage');
      const nidCropPreview = document.getElementById('nidCropPreview');
      let nidCropper = null;

      pictureInput.addEventListener('change', () => {
        const file = pictureInput.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
          pictureInput.setCustomValidity('Please choose an image file.');
          return;
        }
        pictureInput.setCustomValidity('');
        const reader = new FileReader();
        reader.onload = () => {
          cropImage.src = reader.result;
          cropPreview.style.display = 'block';
          if (cropper) cropper.destroy();
          cropper = new Cropper(cropImage, { aspectRatio: 108 / 137, viewMode: 1, autoCropArea: 1 });
        };
        reader.readAsDataURL(file);
      });
      nidPictureInput.addEventListener('change', () => {
        const file = nidPictureInput.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
          nidPictureInput.setCustomValidity('Please choose an image file.');
          return;
        }
        nidPictureInput.setCustomValidity('');
        const reader = new FileReader();
        reader.onload = () => {
          nidCropImage.src = reader.result;
          nidCropPreview.style.display = 'block';
          if (nidCropper) nidCropper.destroy();
          nidCropper = new Cropper(nidCropImage, { viewMode: 1, autoCropArea: 1 });
        };
        reader.readAsDataURL(file);
      });

      const requiredFields = <?= json_encode($requiredFields, JSON_UNESCAPED_UNICODE) ?>;
      const textFields = form.querySelectorAll('input[type="text"], textarea');
      const cleanText = (value) => value
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
        .replace(/<[^>]*>/g, '')
        .trim();
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
          const canvas = cropper.getCroppedCanvas({ width: 108, height: 137, imageSmoothingQuality: 'high' });
          const dataUrl = compressedCrop(canvas, 108, 137, 136500);
          if (dataUrl.length > 136500) {
            event.preventDefault();
            pictureInput.setCustomValidity('Cropped picture must be under 100KB.');
            form.classList.add('was-validated');
            return;
          }
          pictureData.value = dataUrl;
        }
        if (nidCropper) {
          const canvas = nidCropper.getCroppedCanvas({ imageSmoothingQuality: 'high' });
          const dataUrl = compressedCrop(canvas, 1200, 1200, 408000);
          if (dataUrl.length > 408000) {
            event.preventDefault();
            nidPictureInput.setCustomValidity('Cropped NID picture must be under 300KB.');
            form.classList.add('was-validated');
            return;
          }
          nidPictureData.value = dataUrl;
        }
        textFields.forEach((field) => {
          field.value = cleanText(field.value);
        });

        form.querySelectorAll('select').forEach((field) => {
          const allowed = Array.from(field.options).map((option) => option.value);
          if (!allowed.includes(field.value)) field.value = '';
        });

        let invalid = false;
        requiredFields.forEach((name) => {
          const field = form.elements.namedItem(name);
          if (!field || field.value.trim() === '') {
            if (field) field.setCustomValidity('This field is required.');
            invalid = true;
          } else if (field) {
            field.setCustomValidity('');
          }
        });

        const email = form.elements.namedItem('email');
        if (email && email.value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
          email.setCustomValidity('Enter a valid email address.');
          invalid = true;
        }

        if (invalid || !form.checkValidity()) {
          event.preventDefault();
          form.classList.add('was-validated');
        }
      });
    })();
  </script>
  <script>
    (() => {
      const updateAlert = document.getElementById('profileUpdateAlert');
      if (updateAlert) {
        window.setTimeout(() => {
          updateAlert.classList.remove('show');
          window.setTimeout(() => updateAlert.remove(), 200);
        }, 10000);
      }
    })();
  </script>
  <script>
    (() => {
      const selectors = {
        division: document.getElementById('teacher_division'),
        district: document.getElementById('teacher_district'),
        upazila: document.getElementById('teacher_upazila')
      };
      const children = { division: 'district', district: 'upazila' };
      const descendants = {
        division: ['district', 'upazila'],
        district: ['upazila'],
        upazila: []
      };
      if (Object.values(selectors).some((select) => !select)) return;

      const resetDescendants = (parent) => {
        descendants[parent].forEach((child) => {
          selectors[child].innerHTML = '<option value="">Select</option>';
          selectors[child].disabled = true;
          selectors[child].dataset.selected = '';
        });
      };

      const loadChildren = async (parent, child, selectedId = '') => {
        const parentId = selectors[parent].value;
        selectors[child].innerHTML = '<option value="">Select</option>';
        selectors[child].disabled = !parentId;
        if (!parentId) return;
        const response = await fetch(`profile.php?location=${child}&parent_id=${encodeURIComponent(parentId)}`);
        if (!response.ok) throw new Error('Unable to load address options.');
        const options = await response.json();
        options.forEach((option) => {
          const element = new Option(option.name, option.id);
          if (String(option.id) === selectedId) element.selected = true;
          selectors[child].add(element);
        });
      };

      Object.entries(children).forEach(([parent, child]) => {
        selectors[parent].addEventListener('change', async (event) => {
          resetDescendants(parent);
          const selectedId = event.target.value;
          if (selectedId) await loadChildren(parent, child);
        });
      });

      const initialize = async () => {
        const savedDistrict = selectors.district.dataset.selected;
        const savedUpazila = selectors.upazila.dataset.selected;
        if (!selectors.division.value) return;
        await loadChildren('division', 'district', savedDistrict);
        if (!selectors.district.value) return;
        await loadChildren('district', 'upazila', savedUpazila);
      };
      initialize().catch((error) => console.error(error));
    })();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      if (token) {
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach((form) => {
          if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = token;
            form.appendChild(input);
          }
        });
      }
      const sidebarWrapper = document.querySelector('.sidebar-wrapper');
      const isMobile = window.innerWidth <= 992;
      if (sidebarWrapper && OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined && !isMobile) {
        OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
          scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
        });
      }
      const role = document.getElementById('profilePanelRole');
      const modules = document.getElementById('profileAdminModules');
      const syncRole = () => {
        if (modules) modules.style.display = role?.value === 'admin' ? '' : 'none';
      };
      role?.addEventListener('change', syncRole);
      syncRole();
    });
  </script>
</body>
</html>
