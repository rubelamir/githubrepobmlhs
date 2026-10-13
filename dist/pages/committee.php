<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'Managing Committee | School Admin';
$designationOptions = getDesignationOptions();
$protectedSlugs = ['president', 'member_secretary'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_role') {
            $id = postedRecordId('committee_role');
            $label = trim((string) ($_POST['label_bn'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $source = (string) ($_POST['member_source'] ?? 'manual');
            if (!in_array($source, ['manual', 'teacher'], true)) {
                $source = 'manual';
            }
            $autoDesignation = $source === 'teacher' ? trim((string) ($_POST['auto_teacher_designation'] ?? '')) : '';
            if ($autoDesignation !== '' && !isset($designationOptions[$autoDesignation])) {
                throw new RuntimeException('Choose a valid teacher designation for auto-fill.');
            }
            $allowMultiple = isset($_POST['allow_multiple']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($label === '') {
                throw new RuntimeException('Position title is required.');
            }
            if ($sortOrder === 0) {
                $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM comitee_roles')->fetchColumn();
            }
            if ($id > 0) {
                $existing = committeeRoleById($db, $id);
                if ($existing === null) {
                    throw new RuntimeException('Position not found.');
                }
                $db->prepare(
                    'UPDATE comitee_roles
                     SET label_bn = :label_bn, sort_order = :sort_order, is_active = :is_active,
                         member_source = :member_source, auto_teacher_designation = :auto_teacher_designation,
                         allow_multiple = :allow_multiple
                     WHERE id = :id'
                )->execute([
                    'label_bn' => $label,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'member_source' => $source,
                    'auto_teacher_designation' => $autoDesignation !== '' ? $autoDesignation : null,
                    'allow_multiple' => $allowMultiple,
                    'id' => $id,
                ]);
                redirectWithMessage('committee.php', 'success', 'Committee position updated.');
            }
            $db->prepare(
                'INSERT INTO comitee_roles (label_bn, slug, sort_order, is_active, member_source, auto_teacher_designation, allow_multiple)
                 VALUES (:label_bn, :slug, :sort_order, :is_active, :member_source, :auto_teacher_designation, :allow_multiple)'
            )->execute([
                'label_bn' => $label,
                'slug' => 'role_' . bin2hex(random_bytes(4)),
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'member_source' => $source,
                'auto_teacher_designation' => $autoDesignation !== '' ? $autoDesignation : null,
                'allow_multiple' => $allowMultiple,
            ]);
            $newId = (int) $db->lastInsertId();
            if ($newId > 0) {
                $db->prepare('UPDATE comitee_roles SET slug = :slug WHERE id = :id')->execute([
                    'slug' => 'role_' . $newId,
                    'id' => $newId,
                ]);
            }
            redirectWithMessage('committee.php', 'success', 'Committee position added.');
        }

        if ($action === 'toggle_role') {
            $id = postedRecordId('committee_role');
            $db->prepare('UPDATE comitee_roles SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('committee.php', 'success', 'Position visibility updated.');
        }

        if ($action === 'delete_role') {
            $id = postedRecordId('committee_role');
            $existing = committeeRoleById($db, $id);
            if ($existing === null) {
                throw new RuntimeException('Position not found.');
            }
            if (in_array((string) $existing['slug'], $protectedSlugs, true)) {
                throw new RuntimeException('The president and member-secretary positions cannot be deleted. Hide them instead.');
            }
            $countStmt = $db->prepare('SELECT COUNT(*) FROM comitee WHERE comitee_designation = :d');
            $countStmt->execute(['d' => (string) $id]);
            if ((int) $countStmt->fetchColumn() > 0) {
                throw new RuntimeException('Remove members from this position before deleting it.');
            }
            $db->prepare('DELETE FROM comitee_roles WHERE id = :id')->execute(['id' => $id]);
            redirectWithMessage('committee.php', 'success', 'Position deleted.');
        }

        if ($action === 'save_member' || $action === 'update_member') {
            $id = postedRecordId('committee_member');
            $roleId = (int) ($_POST['comitee_designation'] ?? 0);
            $role = committeeRoleById($db, $roleId);
            if ($role === null || (int) $role['is_active'] !== 1) {
                throw new RuntimeException('Choose an active committee position.');
            }
            $source = (string) ($role['member_source'] ?? 'manual');
            $name = trim((string) ($_POST['comitee_name'] ?? ''));
            $mobile = trim((string) ($_POST['comitee_mobile'] ?? ''));
            $teacherId = (int) ($_POST['teacher_id'] ?? 0);
            $pictureData = (string) ($_POST['comitee_picture_data'] ?? '');
            $existingPicture = '';
            if ($id > 0) {
                $current = fetchRows($db, 'SELECT * FROM comitee WHERE id = :id LIMIT 1', ['id' => $id]);
                $existingPicture = (string) ($current[0]['comitee_picture'] ?? '');
            }

            if ($source === 'teacher') {
                if ($teacherId <= 0) {
                    throw new RuntimeException('Select a teacher for this position.');
                }
                $teacherRows = fetchRows($db, 'SELECT * FROM teacher_info WHERE id = :id LIMIT 1', ['id' => $teacherId]);
                if ($teacherRows === []) {
                    throw new RuntimeException('Teacher not found.');
                }
                $teacher = $teacherRows[0];
                $dup = false;
                foreach (publicCommitteeMembers($db, false) as $existing) {
                    if ((int) ($existing['teacher_id'] ?? 0) === $teacherId && (int) ($existing['member_id'] ?? 0) !== $id) {
                        $dup = true;
                        break;
                    }
                }
                if ($dup) {
                    throw new RuntimeException('That teacher is already on the committee.');
                }
                $autoDesignation = trim((string) ($role['auto_teacher_designation'] ?? ''));
                if ($autoDesignation !== '' && (string) ($teacher['teacher_designation'] ?? '') === $autoDesignation) {
                    throw new RuntimeException('This teacher is already included automatically for this position.');
                }
                if ((int) ($role['allow_multiple'] ?? 1) !== 1) {
                    $others = fetchRows(
                        $db,
                        'SELECT id FROM comitee WHERE comitee_designation = :d AND id <> :id LIMIT 1',
                        ['d' => (string) $roleId, 'id' => $id]
                    );
                    if ($others !== [] || $autoDesignation !== '') {
                        throw new RuntimeException('This position allows only one member.');
                    }
                }
                $name = trim((string) $teacher['teacher_name']);
                $mobile = trim((string) ($teacher['teacher_mobile'] ?? ''));
                $picture = $existingPicture !== '' ? $existingPicture : null;
            } else {
                if ($name === '') {
                    throw new RuntimeException('Name is required.');
                }
                if ((int) ($role['allow_multiple'] ?? 1) !== 1) {
                    $others = fetchRows(
                        $db,
                        'SELECT id FROM comitee WHERE comitee_designation = :d AND id <> :id LIMIT 1',
                        ['d' => (string) $roleId, 'id' => $id]
                    );
                    if ($others !== []) {
                        throw new RuntimeException('This position allows only one member.');
                    }
                }
                $teacherId = 0;
                $picture = $pictureData !== ''
                    ? saveCroppedJpeg($pictureData, 'comitee_picture', '', 100 * 1024, 108, 137)
                    : ($existingPicture !== '' ? $existingPicture : null);
                replacePublicMediaFile('comitee_picture', $existingPicture, (string) ($picture ?? ''));
            }

            $payload = [
                'n' => $name,
                'd' => (string) $roleId,
                'm' => $mobile !== '' ? $mobile : null,
                'p' => $picture,
                't' => $teacherId > 0 ? $teacherId : null,
            ];
            if ($id > 0) {
                $payload['id'] = $id;
                $db->prepare(
                    'UPDATE comitee
                     SET comitee_name = :n, comitee_designation = :d, comitee_mobile = :m,
                         comitee_picture = :p, teacher_id = :t
                     WHERE id = :id'
                )->execute($payload);
                redirectWithMessage('committee.php', 'success', 'Committee member updated.');
            }
            $db->prepare(
                'INSERT INTO comitee (comitee_name, comitee_designation, comitee_mobile, comitee_picture, teacher_id, comitee_status)
                 VALUES (:n, :d, :m, :p, :t, 1)'
            )->execute($payload);
            redirectWithMessage('committee.php', 'success', 'Committee member added.');
        }

        if ($action === 'toggle') {
            $db->prepare('UPDATE comitee SET comitee_status = IF(comitee_status = 1, 0, 1) WHERE id = :id')
                ->execute(['id' => postedRecordId('committee_member')]);
            redirectWithMessage('committee.php', 'success', 'Member visibility updated.');
        }

        if ($action === 'delete') {
            $id = postedRecordId('committee_member');
            if (!deleteDatabaseRowWithMedia($db, 'comitee', $id, ['comitee_picture' => 'comitee_picture'])) {
                throw new RuntimeException('Committee member not found.');
            }
            redirectWithMessage('committee.php', 'success', 'Member and photo removed.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('committee.php', 'danger', userFacingError($exception));
    }
}

$roles = committeeRoles($db, false);
$editRoleId = requestRecordId('committee_role');
$editRole = $editRoleId > 0 ? committeeRoleById($db, $editRoleId) : null;
$editMemberId = requestRecordId('committee_member');
$editMember = $editMemberId > 0
    ? (fetchRows($db, 'SELECT * FROM comitee WHERE id = :id LIMIT 1', ['id' => $editMemberId])[0] ?? null)
    : null;
$teachers = committeeTeachers($db);
$displayMembers = publicCommitteeMembers($db, false);

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#committeeForm', [
    { input: '#committeePhoto', hidden: '#committeePhotoData', image: '#committeePhotoImage', preview: '#committeePhotoPreview', aspectRatio: 108/137, width: 108, height: 137, maxLength: 136500 }
  ]);
  const roleSelect = document.getElementById('memberRole');
  const sourceMap = JSON.parse(document.getElementById('committeeRoleSources')?.textContent || '{}');
  const teacherWrap = document.getElementById('teacherFields');
  const manualWrap = document.getElementById('manualFields');
  const teacherSelect = document.getElementById('teacherSelect');
  const nameInput = document.querySelector('#committeeForm [name="comitee_name"]');
  function syncFields() {
    const role = sourceMap[roleSelect?.value] || { member_source: 'manual' };
    const fromTeacher = role.member_source === 'teacher';
    if (teacherWrap) teacherWrap.hidden = !fromTeacher;
    if (manualWrap) manualWrap.hidden = fromTeacher;
    if (teacherSelect) teacherSelect.required = fromTeacher;
    if (nameInput) nameInput.required = !fromTeacher;
  }
  roleSelect?.addEventListener('change', syncFields);
  syncFields();
});
JS];
include __DIR__ . '/../includes/layout-head.php';
$roleSources = [];
foreach ($roles as $role) {
    $roleSources[(string) $role['id']] = [
        'member_source' => (string) $role['member_source'],
        'allow_multiple' => (int) $role['allow_multiple'],
        'auto' => (string) ($role['auto_teacher_designation'] ?? ''),
    ];
}
?>
        <script type="application/json" id="committeeRoleSources"><?= json_encode($roleSources, JSON_UNESCAPED_UNICODE) ?></script>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">Managing committee</h3>
            <p class="text-secondary mb-0">Define committee positions here. Teacher-linked posts (member secretary, teacher representatives) take name, photo and mobile from the Teachers &amp; Staff records. The head teacher is included automatically as member secretary.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>

            <div class="card stat-card mb-3">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><?= $editRole ? 'Edit position' : 'Committee positions' ?></h3>
                <?php if ($editRole): ?><a class="btn btn-sm btn-outline-secondary" href="committee.php">Cancel</a><?php endif; ?>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3 align-items-end mb-3">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="save_role">
                  <?php if ($editRole): ?><?= signedRecordField((int) $editRole['id'], 'committee_role') ?><?php endif; ?>
                  <div class="col-md-3">
                    <label class="form-label">Title (Bangla)</label>
                    <input class="form-control" name="label_bn" required value="<?= h((string) ($editRole['label_bn'] ?? '')) ?>" placeholder="যেমন: সভাপতি">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Member type</label>
                    <select class="form-select" name="member_source" id="roleSource">
                      <option value="manual" <?= (($editRole['member_source'] ?? 'manual') === 'manual') ? 'selected' : '' ?>>Manual entry</option>
                      <option value="teacher" <?= (($editRole['member_source'] ?? '') === 'teacher') ? 'selected' : '' ?>>From teachers</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Auto-fill from designation</label>
                    <select class="form-select" name="auto_teacher_designation">
                      <option value="">None — pick teachers one by one</option>
                      <?php foreach ($designationOptions as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= ((string) ($editRole['auto_teacher_designation'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text">Use Head Teacher for member secretary.</div>
                  </div>
                  <div class="col-md-1">
                    <label class="form-label">Order</label>
                    <input class="form-control" type="number" name="sort_order" value="<?= h((string) ($editRole['sort_order'] ?? '0')) ?>">
                  </div>
                  <div class="col-md-3 d-flex flex-wrap gap-3 align-items-center pb-2">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="allow_multiple" id="allowMultiple" <?= !$editRole || (int) ($editRole['allow_multiple'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="allowMultiple">Allow multiple</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_active" id="roleActive" <?= !$editRole || (int) ($editRole['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                      <label class="form-check-label" for="roleActive">Show on website</label>
                    </div>
                    <button class="btn btn-primary"><?= $editRole ? 'Update position' : 'Add position' ?></button>
                  </div>
                </form>
                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead><tr><th>Order</th><th>Position</th><th>Type</th><th>Auto-fill</th><th>Multiple</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                      <?php foreach ($roles as $role): ?>
                        <tr>
                          <td><?= (int) $role['sort_order'] ?></td>
                          <td><?= h((string) $role['label_bn']) ?></td>
                          <td><?= ((string) $role['member_source'] === 'teacher') ? 'From teachers' : 'Manual' ?></td>
                          <td><?= h($designationOptions[(string) ($role['auto_teacher_designation'] ?? '')] ?? '—') ?></td>
                          <td><?= (int) $role['allow_multiple'] === 1 ? 'Yes' : 'No' ?></td>
                          <td><?= (int) $role['is_active'] === 1 ? 'Live' : 'Hidden' ?></td>
                          <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('committee.php', (int) $role['id'], 'committee_role')) ?>">Edit</a>
                            <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_role"><?= signedRecordField((int) $role['id'], 'committee_role') ?><button class="btn btn-sm btn-outline-secondary">Toggle</button></form>
                            <?php if (!in_array((string) $role['slug'], $protectedSlugs, true)): ?>
                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this position?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_role"><?= signedRecordField((int) $role['id'], 'committee_role') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-lg-4">
                <div class="card stat-card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0"><?= $editMember ? 'Edit member' : 'Add member' ?></h3>
                    <?php if ($editMember): ?><a class="btn btn-sm btn-outline-secondary" href="committee.php">Cancel</a><?php endif; ?>
                  </div>
                  <div class="card-body">
                    <form method="post" id="committeeForm">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="<?= $editMember ? 'update_member' : 'save_member' ?>">
                      <?php if ($editMember): ?><?= signedRecordField((int) $editMember['id'], 'committee_member') ?><?php endif; ?>
                      <div class="mb-3">
                        <label class="form-label">Position</label>
                        <select class="form-select" name="comitee_designation" id="memberRole" required>
                          <option value="">Choose...</option>
                          <?php foreach ($roles as $role): if ((int) $role['is_active'] !== 1) { continue; } ?>
                            <option value="<?= (int) $role['id'] ?>" <?= (string) ($editMember['comitee_designation'] ?? '') === (string) $role['id'] ? 'selected' : '' ?>>
                              <?= h((string) $role['label_bn']) ?><?= ((string) $role['member_source'] === 'teacher') ? ' (from teachers)' : '' ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div id="teacherFields" class="mb-3">
                        <label class="form-label">Teacher</label>
                        <select class="form-select" name="teacher_id" id="teacherSelect">
                          <option value="">Choose a teacher...</option>
                          <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= (int) $teacher['id'] ?>" <?= (int) ($editMember['teacher_id'] ?? 0) === (int) $teacher['id'] ? 'selected' : '' ?>>
                              <?= h((string) $teacher['teacher_name']) ?> — <?= h(getDesignationLabel((string) $teacher['teacher_designation'])) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <div class="form-text">Name, photo and mobile come from the teacher record.</div>
                      </div>
                      <div id="manualFields">
                        <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="comitee_name" value="<?= h((string) ($editMember['comitee_name'] ?? '')) ?>"></div>
                        <div class="mb-3"><label class="form-label">Mobile</label><input class="form-control" name="comitee_mobile" value="<?= h((string) ($editMember['comitee_mobile'] ?? '')) ?>"></div>
                        <?php renderCropperField('committeePhoto', 'comitee_picture_data', 'Photo', '108×137px JPEG, maximum 100KB.'); ?>
                        <?php if (!empty($editMember['comitee_picture'])): ?>
                          <img src="<?= h(publicSiteUrl('comitee_picture/' . $editMember['comitee_picture'])) ?>" alt="" class="mb-3 rounded" width="54" height="68" style="object-fit:cover">
                        <?php endif; ?>
                      </div>
                      <button class="btn btn-primary w-100"><?= $editMember ? 'Update member' : 'Save member' ?></button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="col-lg-8">
                <div class="card stat-card">
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead><tr><th>Photo</th><th>Name</th><th>Position</th><th>Source</th><th>Mobile</th><th></th></tr></thead>
                      <tbody>
                        <?php if ($displayMembers === []): ?>
                          <tr><td colspan="6" class="text-secondary">No committee members yet. Add a president, then pick teacher representatives.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($displayMembers as $member):
                            $photo = (string) ($member['photo'] ?? '');
                            $photoUrl = $photo !== '' ? publicSiteUrl($photo) : '';
                        ?>
                          <tr>
                            <td>
                              <?php if ($photoUrl !== ''): ?>
                                <img src="<?= h($photoUrl) ?>" alt="" width="48" height="48" class="rounded" style="object-fit:cover">
                              <?php endif; ?>
                            </td>
                            <td>
                              <?= h((string) $member['name']) ?>
                              <?php if ((string) ($member['teacher_designation'] ?? '') !== ''): ?>
                                <div class="small text-secondary"><?= h(getDesignationLabel((string) $member['teacher_designation'])) ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?= h((string) $member['role_label']) ?></td>
                            <td>
                              <?php if (!empty($member['is_auto'])): ?>
                                <span class="badge text-bg-success">Auto from teacher</span>
                              <?php elseif ((string) ($member['member_source'] ?? '') === 'teacher'): ?>
                                <span class="badge text-bg-info">Teacher</span>
                              <?php else: ?>
                                <span class="badge text-bg-secondary">Manual</span>
                              <?php endif; ?>
                              <?php if (empty($member['is_auto']) && empty($member['is_active'])): ?>
                                <span class="badge text-bg-warning">Hidden</span>
                              <?php endif; ?>
                            </td>
                            <td><?= h((string) $member['mobile']) ?></td>
                            <td class="text-nowrap">
                              <?php if (!empty($member['is_auto'])): ?>
                                <span class="small text-secondary">Linked automatically</span>
                              <?php else: ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('committee.php', (int) $member['member_id'], 'committee_member')) ?>">Edit</a>
                                <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><?= signedRecordField((int) $member['member_id'], 'committee_member') ?><button class="btn btn-sm btn-outline-secondary"><?= !empty($member['is_active']) ? 'Hide' : 'Show' ?></button></form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove this member?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><?= signedRecordField((int) $member['member_id'], 'committee_member') ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
