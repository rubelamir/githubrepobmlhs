<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$includeCropper = true;
$pageTitle = 'School Settings | School Admin';
$settings = schoolSettings($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fields = [
            'school_name_bn', 'school_name_en', 'short_name_en', 'address', 'address_en', 'established', 'eiin', 'mpo_code', 'mobile', 'email',
            'about_short', 'facebook', 'map_embed', 'nav_cta_label', 'nav_cta_url', 'footer_credit',
            'title_messages', 'title_history', 'title_info', 'title_events', 'title_ssc', 'title_teachers',
            'title_president', 'title_head_teacher', 'title_important_links',
            'notify_from_email', 'notify_parent_emails', 'notify_sms_url',
            'sms_provider', 'sms_sid',
        ];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        if (!in_array($values['sms_provider'], ['', 'webhook', 'sslwireless', 'bulksmsbd'], true)) {
            $values['sms_provider'] = '';
        }
        $postedToken = trim((string) ($_POST['sms_api_token'] ?? ''));
        $values['sms_api_token'] = $postedToken !== '' ? $postedToken : (string) ($settings['sms_api_token'] ?? '');
        $logoData = (string) ($_POST['logo_data'] ?? '');
        $previousLogo = (string) ($settings['logo'] ?? 'image/school_logo.png');
        if ($logoData !== '') {
            $values['logo'] = 'image/' . saveCroppedJpeg($logoData, 'image', '', 150 * 1024, 400, 400);
            replacePublicMediaFile('image', $previousLogo, $values['logo']);
        } else {
            $values['logo'] = $previousLogo;
        }
        $settingsId = (int) ($db->query('SELECT id FROM school_settings ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
        if ($settingsId > 0) {
            $values['id'] = $settingsId;
            $db->prepare(
                'UPDATE school_settings SET school_name_bn=:school_name_bn, school_name_en=:school_name_en, short_name_en=:short_name_en, address=:address, address_en=:address_en,
                 established=:established, eiin=:eiin, mpo_code=:mpo_code, mobile=:mobile, email=:email,
                 about_short=:about_short, facebook=:facebook, map_embed=:map_embed, logo=:logo,
                 nav_cta_label=:nav_cta_label, nav_cta_url=:nav_cta_url, footer_credit=:footer_credit,
                 title_messages=:title_messages, title_history=:title_history, title_info=:title_info, title_events=:title_events,
                 title_ssc=:title_ssc, title_teachers=:title_teachers, title_president=:title_president,
                 title_head_teacher=:title_head_teacher, title_important_links=:title_important_links,
                 notify_from_email=:notify_from_email, notify_parent_emails=:notify_parent_emails, notify_sms_url=:notify_sms_url,
                 sms_provider=:sms_provider, sms_api_token=:sms_api_token, sms_sid=:sms_sid
                 WHERE id = :id'
            )->execute($values);
        } else {
            $db->prepare(
                'INSERT INTO school_settings (school_name_bn, school_name_en, short_name_en, address, address_en, established, eiin, mpo_code, mobile, email, about_short, facebook, map_embed, logo,
                 nav_cta_label, nav_cta_url, footer_credit, title_messages, title_history, title_info, title_events, title_ssc, title_teachers,
                 title_president, title_head_teacher, title_important_links, notify_from_email, notify_parent_emails, notify_sms_url,
                 sms_provider, sms_api_token, sms_sid)
                 VALUES (:school_name_bn, :school_name_en, :short_name_en, :address, :address_en, :established, :eiin, :mpo_code, :mobile, :email, :about_short, :facebook, :map_embed, :logo,
                 :nav_cta_label, :nav_cta_url, :footer_credit, :title_messages, :title_history, :title_info, :title_events, :title_ssc, :title_teachers,
                 :title_president, :title_head_teacher, :title_important_links, :notify_from_email, :notify_parent_emails, :notify_sms_url,
                 :sms_provider, :sms_api_token, :sms_sid)'
            )->execute($values);
        }
        redirectWithMessage('school-settings.php', 'success', 'School profile updated. The public website will use these details.');
    } catch (Throwable $exception) {
        redirectWithMessage('school-settings.php', 'danger', userFacingError($exception));
    }
}

$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  AdminImageCropper.bindForm('#settingsForm', [
    { input: '#logoFile', hidden: '#logoFileData', image: '#logoFileImage', preview: '#logoFilePreview', aspectRatio: 1, width: 400, height: 400, maxLength: 204800, errorMessage: 'Logo must be under 150KB.' }
  ]);
});
JS];
include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header"><div class="container-fluid"><h3 class="mb-0">School settings</h3><p class="text-secondary mb-0">These fields control the public website header, footer and contact details.</p></div></div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="post" id="settingsForm" class="card stat-card">
              <?= csrfField() ?>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6"><label class="form-label">School name (Bangla)</label><input class="form-control" name="school_name_bn" value="<?= h($settings['school_name_bn']) ?>" required></div>
                  <div class="col-md-6"><label class="form-label">School name (English)</label><input class="form-control" name="school_name_en" value="<?= h($settings['school_name_en']) ?>" required></div>
                  <div class="col-md-4"><label class="form-label">Short name (English)</label><input class="form-control" name="short_name_en" maxlength="40" value="<?= h((string) ($settings['short_name_en'] ?? '')) ?>" placeholder="BMLHS"><div class="form-text">Shown in the admin sidebar, e.g. BMLHS.</div></div>
                  <div class="col-md-12"><label class="form-label">Address (Bangla)</label><input class="form-control" name="address" value="<?= h($settings['address']) ?>"></div>
                  <div class="col-md-12"><label class="form-label">Address (English)</label><input class="form-control" name="address_en" value="<?= h((string) ($settings['address_en'] ?? '')) ?>" placeholder="Upazila: Lakshmipur Sadar, District: Lakshmipur."><div class="form-text">Used on English certificates and English letterheads.</div></div>
                  <div class="col-md-3"><label class="form-label">Established</label><input class="form-control" name="established" value="<?= h($settings['established']) ?>"></div>
                  <div class="col-md-3"><label class="form-label">EIIN</label><input class="form-control" name="eiin" value="<?= h($settings['eiin']) ?>"></div>
                  <div class="col-md-3"><label class="form-label">MPO code</label><input class="form-control" name="mpo_code" value="<?= h($settings['mpo_code']) ?>"></div>
                  <div class="col-md-3"><label class="form-label">Mobile</label><input class="form-control" name="mobile" value="<?= h($settings['mobile']) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h($settings['email']) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Facebook page URL</label><input class="form-control" name="facebook" value="<?= h((string) ($settings['facebook'] ?? '')) ?>" placeholder="https://www.facebook.com/YourPage"></div>
                  <div class="col-md-4"><label class="form-label">Navbar CTA label</label><input class="form-control" name="nav_cta_label" value="<?= h((string) ($settings['nav_cta_label'] ?? '')) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Navbar CTA URL</label><input class="form-control" name="nav_cta_url" value="<?= h((string) ($settings['nav_cta_url'] ?? '')) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Footer credit</label><input class="form-control" name="footer_credit" value="<?= h((string) ($settings['footer_credit'] ?? '')) ?>"></div>
                  <div class="col-md-12"><label class="form-label">Short about</label><textarea class="form-control" name="about_short" rows="4"><?= h((string) ($settings['about_short'] ?? '')) ?></textarea></div>
                  <div class="col-12"><hr class="my-1"><div class="fw-semibold">Homepage section titles</div></div>
                  <div class="col-md-6"><label class="form-label">Messages</label><input class="form-control" name="title_messages" value="<?= h((string) ($settings['title_messages'] ?? '')) ?>"></div>
                  <div class="col-md-6"><label class="form-label">History</label><input class="form-control" name="title_history" value="<?= h((string) ($settings['title_history'] ?? '')) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Info cards</label><input class="form-control" name="title_info" value="<?= h((string) ($settings['title_info'] ?? '')) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Events</label><input class="form-control" name="title_events" value="<?= h((string) ($settings['title_events'] ?? '')) ?>"></div>
                  <div class="col-md-6"><label class="form-label">SSC chart</label><input class="form-control" name="title_ssc" value="<?= h((string) ($settings['title_ssc'] ?? '')) ?>"></div>
                  <div class="col-md-6"><label class="form-label">Teachers</label><input class="form-control" name="title_teachers" value="<?= h((string) ($settings['title_teachers'] ?? '')) ?>"></div>
                  <div class="col-md-4"><label class="form-label">President</label><input class="form-control" name="title_president" value="<?= h((string) ($settings['title_president'] ?? '')) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Head teacher</label><input class="form-control" name="title_head_teacher" value="<?= h((string) ($settings['title_head_teacher'] ?? '')) ?>"></div>
                  <div class="col-md-4"><label class="form-label">Important links</label><input class="form-control" name="title_important_links" value="<?= h((string) ($settings['title_important_links'] ?? '')) ?>"></div>
                  <div class="col-md-12"><label class="form-label">Google Map embed HTML</label><textarea class="form-control" name="map_embed" rows="3"><?= h((string) ($settings['map_embed'] ?? '')) ?></textarea></div>
                  <div class="col-12"><hr class="my-1"><div class="fw-semibold">Guardian notice alerts</div><div class="form-text">Used when publishing a notice with “Notify guardians”.</div></div>
                  <div class="col-md-6"><label class="form-label">From email</label><input class="form-control" type="email" name="notify_from_email" value="<?= h((string) ($settings['notify_from_email'] ?? '')) ?>" placeholder="school@example.com"></div>
                  <div class="col-md-6"><label class="form-label">Parent / guardian emails</label><textarea class="form-control" name="notify_parent_emails" rows="2" placeholder="comma or new-line separated"><?= h((string) ($settings['notify_parent_emails'] ?? '')) ?></textarea></div>
                  <?php $smsProvider = (string) ($settings['sms_provider'] ?? ''); ?>
                  <div class="col-md-4">
                    <label class="form-label">SMS provider</label>
                    <select class="form-select" name="sms_provider">
                      <option value="" <?= $smsProvider === '' ? 'selected' : '' ?>>Off</option>
                      <option value="sslwireless" <?= $smsProvider === 'sslwireless' ? 'selected' : '' ?>>SSL Wireless</option>
                      <option value="bulksmsbd" <?= $smsProvider === 'bulksmsbd' ? 'selected' : '' ?>>BulkSMS BD</option>
                      <option value="webhook" <?= $smsProvider === 'webhook' ? 'selected' : '' ?>>Webhook URL</option>
                    </select>
                  </div>
                  <div class="col-md-4"><label class="form-label">API token / API key</label><input class="form-control" type="password" name="sms_api_token" value="" autocomplete="new-password" placeholder="<?= ($settings['sms_api_token'] ?? '') !== '' ? 'Saved. Enter a new token to replace it.' : 'API token' ?>"></div>
                  <div class="col-md-4"><label class="form-label">Sender ID (SID)</label><input class="form-control" name="sms_sid" value="<?= h((string) ($settings['sms_sid'] ?? '')) ?>" placeholder="Approved sender name"></div>
                  <div class="col-md-12"><label class="form-label">SMS webhook URL</label><input class="form-control" name="notify_sms_url" value="<?= h((string) ($settings['notify_sms_url'] ?? '')) ?>" placeholder="https://sms.example.com/send?to={mobile}&amp;msg={text}"><div class="form-text">SSL Wireless or BulkSMS uses the token and sender ID. Webhook is the older option: include <code>{mobile}</code> and <code>{text}</code>. Admission tokens, password reset and published results use this gateway.</div></div>
                  <div class="col-md-6">
                    <?php renderCropperField('logoFile', 'logo_data', 'Logo', 'Crop to 400×400px JPEG, maximum 150KB.'); ?>
                    <?php if (!empty($settings['logo'])): ?>
                      <img src="<?= h(publicSiteUrl($settings['logo'])) ?>" alt="" class="mt-2" style="height:72px">
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="card-footer"><button class="btn btn-primary">Save settings</button></div>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
