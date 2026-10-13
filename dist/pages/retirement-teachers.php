<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Former Teachers | School Admin';
$designationOptions = getDesignationOptions();
$minYear = 1900;
$maxYear = (int) date('Y') + 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $id = postedRecordId('retirement');
            $name = trim((string) ($_POST['teacher_name'] ?? ''));
            $designation = trim((string) ($_POST['teacher_designation'] ?? ''));
            $joiningYear = (int) ($_POST['joining_year'] ?? 0);
            $retirementYear = (int) ($_POST['retirement_year'] ?? 0);
            $pictureData = (string) ($_POST['picture_data'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($designation === '' || !isset($designationOptions[$designation])) {
                throw new RuntimeException('Choose a valid designation.');
            }
            if ($joiningYear < $minYear || $joiningYear > $maxYear) {
                throw new RuntimeException('Enter a valid joining year.');
            }
            if ($retirementYear < $minYear || $retirementYear > $maxYear) {
                throw new RuntimeException('Enter a valid retirement year.');
            }
            if ($joiningYear > $retirementYear) {
                throw new RuntimeException('Joining year cannot be after the retirement year.');
            }

            $existingPicture = '';
            if ($action === 'update') {
                $existing = retirementTeacherById($db, $id);
                if ($existing === null) {
                    throw new RuntimeException('Record not found.');
                }
                $existingPicture = (string) ($existing['picture'] ?? '');
            }

            $picture = $existingPicture;
            if ($pictureData !== '') {
                $picture = saveCroppedJpeg($pictureData, 'retirement_teacher_file', '', 100 * 1024, 108, 137);
                replacePublicMediaFile('retirement_teacher_file', $existingPicture, $picture);
            }
            if ($action === 'create' && $picture === '') {
                $picture = '';
            }

            $payload = [
                'n' => $name,
                'des' => $designation,
                'j' => $joiningYear,
                'r' => $retirementYear,
                'p' => $picture !== '' ? $picture : null,
            ];
            if ($action === 'update') {
                $payload['id'] = $id;
                $db->prepare(
                    'UPDATE retirement_teacher
                     SET teacher_name = :n, teacher_designation = :des, joining_year = :j, retirement_year = :r, picture = :p
                     WHERE id = :id'
                )->execute($payload);
                redirectWithMessage(signedRecordUrl('retirement-teachers.php', $id, 'retirement'), 'success', 'Former staff member updated.');
            }
            $db->prepare(
                'INSERT INTO retirement_teacher (teacher_name, teacher_designation, joining_year, retirement_year, picture, retirement_teacher_status)
                 VALUES (:n, :des, :j, :r, :p, 1)'
            )->execute($payload);
            redirectWithMessage('retirement-teachers.php', 'success', 'Former staff member published on the website.');
        }
        if ($action === 'toggle') {
            $db->prepare(
                'UPDATE retirement_teacher SET retirement_teacher_status = IF(retirement_teacher_status = 1, 0, 1) WHERE id = :id'
            )->execute(['id' => postedRecordId('retirement')]);
            redirectWithMessage('retirement-teachers.php', 'success', 'Visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('retirement');
            if (!deleteDatabaseRowWithMedia($db, 'retirement_teacher', $id, ['picture' => 'retirement_teacher_file'])) {
                throw new RuntimeException('Record not found.');
            }
            redirectWithMessage('retirement-teachers.php', 'success', 'Record and photo deleted.');
        }
    } catch (Throwable $exception) {
        $editId = postedRecordId('retirement');
        $target = $editId > 0 ? signedRecordUrl('retirement-teachers.php', $editId, 'retirement') : 'retirement-teachers.php';
        redirectWithMessage($target, 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('retirement');
$editRow = $editId > 0 ? retirementTeacherById($db, $editId) : null;
$isEdit = is_array($editRow);
$rows = retirementTeachers($db, false);
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#retiredForm', [
    { input: '#retiredPhoto', hidden: '#retiredPhotoData', image: '#retiredPhotoImage', preview: '#retiredPhotoPreview', aspectRatio: 108/137, width: 108, height: 137, maxLength: 136500 }
  ]);
});
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Former teachers &amp; staff</h3>
            <p class="text-secondary mb-0">These cards appear on the public page: photo, name, designation and service years.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3">
              <div class="col-lg-4">
                <div class="card stat-card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><?= $isEdit ? 'Edit person' : 'Add person' ?></h3>
                    <?php if ($isEdit): ?>
                      <a class="btn btn-sm btn-outline-secondary" href="retirement-teachers.php">New</a>
                    <?php endif; ?>
                  </div>
                  <div class="card-body">
                    <form method="post" id="retiredForm">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                      <?php if ($isEdit): ?>
                        <?= signedRecordField((int) $editRow['id'], 'retirement') ?>
                      <?php endif; ?>
                      <div class="mb-3">
                        <label class="form-label" for="teacher_name">Name</label>
                        <input class="form-control" id="teacher_name" name="teacher_name" required value="<?= h($isEdit ? (string) $editRow['teacher_name'] : '') ?>">
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="teacher_designation">Designation</label>
                        <select class="form-select" id="teacher_designation" name="teacher_designation" required>
                          <option value="">Choose...</option>
                          <?php foreach ($designationOptions as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= ($isEdit && (string) ($editRow['teacher_designation'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="row g-2">
                        <div class="col-6 mb-3">
                          <label class="form-label" for="joining_year">Joining year</label>
                          <input class="form-control" type="number" id="joining_year" name="joining_year" min="<?= (int) $minYear ?>" max="<?= (int) $maxYear ?>" required value="<?= $isEdit ? (int) $editRow['joining_year'] : '' ?>">
                        </div>
                        <div class="col-6 mb-3">
                          <label class="form-label" for="retirement_year">Retirement year</label>
                          <input class="form-control" type="number" id="retirement_year" name="retirement_year" min="<?= (int) $minYear ?>" max="<?= (int) $maxYear ?>" required value="<?= $isEdit ? (int) $editRow['retirement_year'] : '' ?>">
                        </div>
                      </div>
                      <?php if ($isEdit): ?>
                        <div class="mb-2">
                          <img src="<?= h(publicSiteUrl(retirementTeacherPhotoUrl($editRow['picture'] ?? null))) ?>" alt="" class="rounded border" width="54" height="68" style="object-fit:cover">
                        </div>
                      <?php endif; ?>
                      <?php renderCropperField('retiredPhoto', 'picture_data', $isEdit ? 'Replace photo' : 'Photo', 'Optional. 108×137px JPEG, maximum 100KB. If no photo is uploaded, a default dummy portrait is shown on the website.'); ?>
                      <button class="btn btn-primary w-100"><?= $isEdit ? 'Save changes' : 'Publish' ?></button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="col-lg-8">
                <div class="row g-3">
                  <?php if ($rows === []): ?>
                    <div class="col-12"><div class="text-secondary">No former teachers have been added yet.</div></div>
                  <?php endif; ?>
                  <?php foreach ($rows as $row): ?>
                    <div class="col-md-6">
                      <div class="card stat-card h-100">
                        <div class="card-body d-flex gap-3">
                          <img src="<?= h(publicSiteUrl(retirementTeacherPhotoUrl($row['picture'] ?? null))) ?>" alt="" width="54" height="68" class="rounded border" style="object-fit:cover">
                          <div class="flex-grow-1">
                            <h5 class="mb-1"><?= h((string) $row['teacher_name']) ?></h5>
                            <div class="small text-secondary"><?= h(getDesignationLabel((string) ($row['teacher_designation'] ?? ''))) ?></div>
                            <div class="small text-secondary mb-2"><?= h(retirementYearRange((int) $row['joining_year'], (int) $row['retirement_year'])) ?></div>
                            <?= (int) $row['retirement_teacher_status'] === 1
                                ? '<span class="badge text-bg-success">Live</span>'
                                : '<span class="badge text-bg-secondary">Hidden</span>' ?>
                            <div class="mt-2">
                              <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('retirement-teachers.php', (int) $row['id'], 'retirement')) ?>">Edit</a>
                              <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'retirement') ?><button class="btn btn-sm btn-outline-secondary"><?= (int) $row['retirement_teacher_status'] === 1 ? 'Hide' : 'Show' ?></button></form>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this person and photo?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'retirement') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
