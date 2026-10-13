<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'President & HT Messages | School Admin';
$roles = schoolLeaderMessageRoles();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $role = (string) ($_POST['role'] ?? '');
            if (!isset($roles[$role])) {
                throw new RuntimeException('Unknown message role.');
            }
            $heading = trim((string) ($_POST['heading'] ?? ''));
            $personName = trim((string) ($_POST['person_name'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $existing = schoolLeaderMessageByRole($db, $role);
            $previousPhoto = (string) ($existing['photo_path'] ?? '');
            $photoPath = $previousPhoto;
            if (isset($_POST['clear_photo'])) {
                $photoPath = '';
            }
            $pictureData = (string) ($_POST['photo_data'] ?? '');
            if ($pictureData !== '') {
                $photoPath = saveCroppedJpeg($pictureData, 'messages_pic', '', 100 * 1024, 108, 137);
            }
            replacePublicMediaFile('messages_pic', $previousPhoto, $photoPath);
            if ($heading === '') {
                $heading = $roles[$role]['default_heading'];
            }
            $payload = [
                'role' => $role,
                'heading' => $heading,
                'person_name' => $personName,
                'photo_path' => $photoPath !== '' ? $photoPath : null,
                'message' => $message,
                'is_active' => $isActive,
                'sort_order' => (int) $roles[$role]['sort_order'],
            ];
            if ((int) ($existing['id'] ?? 0) > 0) {
                $payload['id'] = (int) $existing['id'];
                $db->prepare(
                    'UPDATE school_messages
                     SET heading = :heading, person_name = :person_name, photo_path = :photo_path,
                         message = :message, is_active = :is_active, sort_order = :sort_order
                     WHERE id = :id AND role = :role'
                )->execute($payload);
            } else {
                $db->prepare(
                    'INSERT INTO school_messages (role, heading, person_name, photo_path, message, is_active, sort_order)
                     VALUES (:role, :heading, :person_name, :photo_path, :message, :is_active, :sort_order)'
                )->execute($payload);
            }
            redirectWithMessage('messages.php', 'success', 'Message saved. It will appear on the public homepage.');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('messages.php', 'danger', userFacingError($exception));
    }
}

$items = [];
foreach (array_keys($roles) as $role) {
    $items[$role] = schoolLeaderMessageByRole($db, $role);
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  ['president', 'headTeacher'].forEach((prefix) => {
    AdminImageCropper.bindForm('#' + prefix + 'MessageForm', [
      {
        input: '#' + prefix + 'Photo',
        hidden: '#' + prefix + 'PhotoData',
        image: '#' + prefix + 'PhotoImage',
        preview: '#' + prefix + 'PhotoPreview',
        aspectRatio: 108/137,
        width: 108,
        height: 137,
        maxLength: 136500
      }
    ]);
  });
});
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <h3 class="mb-0">President &amp; Head Teacher messages</h3>
            <p class="text-secondary mb-0">These speeches appear on the homepage, just above the school history. Leave a message blank to hide that card. Photos and names fall back to the committee president and head teacher records unless you override them here.</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <div class="row g-3">
              <?php foreach ($items as $role => $item):
                  $prefix = $role === 'president' ? 'president' : 'headTeacher';
                  $previewPhoto = trim((string) ($item['display_photo'] ?? ''));
                  $previewName = trim((string) ($item['display_name'] ?? ''));
              ?>
                <div class="col-lg-6">
                  <div class="card stat-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                      <h3 class="card-title mb-0"><?= h($roles[$role]['default_heading']) ?></h3>
                      <?php if ($item['is_active'] && trim((string) $item['message']) !== ''): ?>
                        <span class="badge text-bg-success">Live</span>
                      <?php else: ?>
                        <span class="badge text-bg-secondary">Hidden</span>
                      <?php endif; ?>
                    </div>
                    <div class="card-body">
                      <?php if ($previewPhoto !== '' || $previewName !== ''): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                          <?php if ($previewPhoto !== ''): ?>
                            <img src="<?= h(publicSiteUrl($previewPhoto)) ?>" alt="" width="54" height="68" class="rounded border" style="object-fit:cover">
                          <?php endif; ?>
                          <div>
                            <div class="fw-semibold"><?= h($previewName !== '' ? $previewName : 'Name not set') ?></div>
                            <small class="text-secondary">Auto-linked from <?= $role === 'president' ? 'Managing Committee' : 'Teachers &amp; Staff' ?></small>
                          </div>
                        </div>
                      <?php endif; ?>
                      <form method="post" id="<?= h($prefix) ?>MessageForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="role" value="<?= h($role) ?>">
                        <div class="mb-3">
                          <label class="form-label">Heading</label>
                          <input class="form-control" name="heading" value="<?= h((string) $item['heading']) ?>">
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Display name <span class="text-secondary">(optional override)</span></label>
                          <input class="form-control" name="person_name" value="<?= h((string) $item['person_name']) ?>" placeholder="<?= h((string) $item['auto_name']) ?>">
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Message</label>
                          <textarea class="form-control" name="message" rows="8"><?= h((string) $item['message']) ?></textarea>
                        </div>
                        <?php renderCropperField($prefix . 'Photo', 'photo_data', 'Custom photo', 'Optional. 108×137px JPEG, maximum 100KB. Leave empty to keep the linked portrait.'); ?>
                        <?php if ($item['photo_path'] !== ''): ?>
                          <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="clear_photo" id="<?= h($prefix) ?>ClearPhoto" value="1">
                            <label class="form-check-label" for="<?= h($prefix) ?>ClearPhoto">Remove custom photo and use the linked portrait</label>
                          </div>
                        <?php endif; ?>
                        <div class="form-check mb-3">
                          <input class="form-check-input" type="checkbox" name="is_active" id="<?= h($prefix) ?>Active" value="1" <?= $item['is_active'] ? 'checked' : '' ?>>
                          <label class="form-check-label" for="<?= h($prefix) ?>Active">Show on the public homepage</label>
                        </div>
                        <button class="btn btn-primary">Save message</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
