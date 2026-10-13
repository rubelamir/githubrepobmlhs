<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
ensureSchoolSchema($db);
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Meritorious Students | School Admin';
$groups = meritoriousGroupOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create' || $action === 'update') {
            $nameBan = trim((string) ($_POST['students_name_ban'] ?? ''));
            $fatherBan = trim((string) ($_POST['fname_ban'] ?? ''));
            $motherBan = trim((string) ($_POST['mname_ban'] ?? ''));
            $batch = trim((string) ($_POST['session_year'] ?? ''));
            $group = meritoriousNormalizeGroup((string) ($_POST['section_group'] ?? ''));
            $result = trim((string) ($_POST['results'] ?? ''));
            $mobile = trim((string) ($_POST['mobile'] ?? ''));
            $fileData = (string) ($_POST['picture_data'] ?? '');
            if ($nameBan === '') {
                throw new RuntimeException('Name is required.');
            }
            if ($fatherBan === '') {
                throw new RuntimeException("Father's name is required.");
            }
            if ($motherBan === '') {
                throw new RuntimeException("Mother's name is required.");
            }
            if ($batch === '') {
                throw new RuntimeException('Batch / SSC year is required.');
            }
            if (!isset($groups[$group])) {
                throw new RuntimeException('Choose a valid group: Science, Humanities or Business.');
            }
            $existingPicture = '';
            $id = postedRecordId('meritorious');
            if ($action === 'update') {
                $found = fetchRows($db, 'SELECT * FROM meritorious_students WHERE id = :id LIMIT 1', ['id' => $id]);
                if ($found === []) {
                    throw new RuntimeException('Record not found.');
                }
                $existingPicture = (string) ($found[0]['picture'] ?? '');
            }
            $picture = $existingPicture;
            if ($fileData !== '') {
                $picture = saveCroppedJpeg($fileData, 'meritorious_students_file', '', 150 * 1024, 360, 440);
                replacePublicMediaFile('meritorious_students_file', $existingPicture, $picture);
            }
            $payload = [
                'students_name_ban' => $nameBan,
                'fname_ban' => $fatherBan,
                'mname_ban' => $motherBan,
                'session_year' => $batch,
                'section_group' => $group,
                'mobile' => $mobile,
                'results' => $result,
                'picture' => $picture,
                'roll_no' => '',
                'reg_no' => '',
                'remarks' => '',
            ];
            if ($action === 'update') {
                $payload['id'] = $id;
                $db->prepare(
                    'UPDATE meritorious_students
                     SET students_name_ban = :students_name_ban, fname_ban = :fname_ban, mname_ban = :mname_ban,
                         session_year = :session_year, section_group = :section_group, mobile = :mobile,
                         results = :results, picture = :picture, roll_no = :roll_no, reg_no = :reg_no, remarks = :remarks
                     WHERE id = :id'
                )->execute($payload);
                redirectWithMessage(signedRecordUrl('meritorious-students.php', $id, 'meritorious'), 'success', 'Meritorious student updated.');
            }
            $payload['students_name_eng'] = '';
            $payload['fname_eng'] = '';
            $payload['mname_eng'] = '';
            $db->prepare(
                'INSERT INTO meritorious_students
                 (students_name_ban, students_name_eng, fname_ban, fname_eng, mname_ban, mname_eng,
                  session_year, section_group, mobile, results, picture, roll_no, reg_no, remarks, meritorious_students_status)
                 VALUES
                 (:students_name_ban, :students_name_eng, :fname_ban, :fname_eng, :mname_ban, :mname_eng,
                  :session_year, :section_group, :mobile, :results, :picture, :roll_no, :reg_no, :remarks, 1)'
            )->execute($payload);
            redirectWithMessage('meritorious-students.php', 'success', 'Meritorious student published on the website.');
        }
        if ($action === 'toggle') {
            $db->prepare(
                'UPDATE meritorious_students
                 SET meritorious_students_status = IF(meritorious_students_status = 1, 0, 1)
                 WHERE id = :id'
            )->execute(['id' => postedRecordId('meritorious')]);
            redirectWithMessage('meritorious-students.php', 'success', 'Visibility updated.');
        }
        if ($action === 'delete') {
            $id = postedRecordId('meritorious');
            if (!deleteDatabaseRowWithMedia($db, 'meritorious_students', $id, ['picture' => 'meritorious_students_file'])) {
                throw new RuntimeException('Record not found.');
            }
            redirectWithMessage('meritorious-students.php', 'success', 'Record deleted.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('meritorious-students.php', 'danger', userFacingError($exception));
    }
}

$editId = requestRecordId('meritorious');
$editRows = $editId > 0 ? fetchRows($db, 'SELECT * FROM meritorious_students WHERE id = :id LIMIT 1', ['id' => $editId]) : [];
$editRow = $editRows[0] ?? null;
$isEdit = is_array($editRow);
$rows = tableExists($db, 'meritorious_students')
    ? fetchRows($db, 'SELECT * FROM meritorious_students ORDER BY session_year DESC, id DESC')
    : [];
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#meritForm', [
    { input: '#meritPhoto', hidden: '#meritPhotoData', image: '#meritPhotoImage', preview: '#meritPhotoPreview', aspectRatio: 360/440, width: 360, height: 440, maxLength: 204800 }
  ]);
});
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Meritorious Students</h3>
            <p class="text-secondary mb-0">Name, parents, batch and group. Result, mobile and photo are optional.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3">
              <div class="col-lg-4">
                <div class="card stat-card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><?= $isEdit ? 'Edit student' : 'Add student' ?></h3>
                    <?php if ($isEdit): ?><a class="btn btn-sm btn-outline-secondary" href="meritorious-students.php">New</a><?php endif; ?>
                  </div>
                  <div class="card-body">
                    <form method="post" id="meritForm">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
                      <?php if ($isEdit): ?><?= signedRecordField((int) $editRow['id'], 'meritorious') ?><?php endif; ?>
                      <div class="mb-3"><label class="form-label">নাম</label><input class="form-control" name="students_name_ban" value="<?= h($isEdit ? (string) ($editRow['students_name_ban'] ?? '') : '') ?>" required></div>
                      <div class="mb-3"><label class="form-label">পিতার নাম</label><input class="form-control" name="fname_ban" value="<?= h($isEdit ? (string) ($editRow['fname_ban'] ?? '') : '') ?>" required></div>
                      <div class="mb-3"><label class="form-label">মাতার নাম</label><input class="form-control" name="mname_ban" value="<?= h($isEdit ? (string) ($editRow['mname_ban'] ?? '') : '') ?>" required></div>
                      <div class="mb-3"><label class="form-label">ব্যাচ</label><input class="form-control" name="session_year" value="<?= h($isEdit ? (string) ($editRow['session_year'] ?? '') : '') ?>" placeholder="২০২২" required></div>
                      <div class="mb-3">
                        <label class="form-label">বিভাগ</label>
                        <select class="form-select" name="section_group" required>
                          <option value="">নির্বাচন করুন</option>
                          <?php
                            $selectedGroup = $isEdit ? meritoriousNormalizeGroup((string) ($editRow['section_group'] ?? '')) : '';
                            foreach ($groups as $key => $label):
                          ?>
                            <option value="<?= h($key) ?>"<?= $selectedGroup === $key ? ' selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="mb-3"><label class="form-label">ফলাফল <span class="text-secondary">(ঐচ্ছিক)</span></label><input class="form-control" name="results" value="<?= h($isEdit ? (string) ($editRow['results'] ?? '') : '') ?>" placeholder="জিপিএ ৫.০০"></div>
                      <div class="mb-3"><label class="form-label">মোবাইল <span class="text-secondary">(ঐচ্ছিক)</span></label><input class="form-control" name="mobile" value="<?= h($isEdit ? (string) ($editRow['mobile'] ?? '') : '') ?>"></div>
                      <?php if ($isEdit && trim((string) ($editRow['picture'] ?? '')) !== '' && is_file(publicSitePath('meritorious_students_file/' . publicMediaBasename((string) $editRow['picture'])))): ?>
                        <img src="<?= h(publicSiteUrl('meritorious_students_file/' . publicMediaBasename((string) $editRow['picture']))) ?>" alt="" class="rounded border mb-2" style="height:88px;object-fit:cover">
                      <?php endif; ?>
                      <?php renderCropperField('meritPhoto', 'picture_data', $isEdit ? 'Replace photo (optional)' : 'Photo (optional)', '360×440 JPEG, maximum 150KB.'); ?>
                      <button class="btn btn-primary w-100 mt-2"><?= $isEdit ? 'Update' : 'Publish' ?></button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="col-lg-8">
                <div class="card stat-card">
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead><tr><th>Name</th><th>Parents</th><th>Batch</th><th>Group</th><th>Result</th><th>Status</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($rows as $row): ?>
                          <tr>
                            <td><?= h(meritoriousDisplayName($row)) ?></td>
                            <td>
                              <small class="d-block">পিতা: <?= h(meritoriousParentName($row, 'father') ?: '—') ?></small>
                              <small class="d-block">মাতা: <?= h(meritoriousParentName($row, 'mother') ?: '—') ?></small>
                            </td>
                            <td><?= h((string) ($row['session_year'] ?? '')) ?></td>
                            <td><?= h(meritoriousGroupLabel((string) ($row['section_group'] ?? ''))) ?></td>
                            <td><?= h((string) ($row['results'] ?? '')) ?></td>
                            <td><?= (int) ($row['meritorious_students_status'] ?? 0) === 1 ? 'Live' : 'Hidden' ?></td>
                            <td class="text-nowrap">
                              <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('meritorious-students.php', (int) $row['id'], 'meritorious')) ?>">Edit</a>
                              <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $row['id'], 'meritorious') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this record?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $row['id'], 'meritorious') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-secondary py-4">No meritorious students yet.</td></tr><?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
