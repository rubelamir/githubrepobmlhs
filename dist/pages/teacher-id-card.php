<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
requireAuthentication('../examples/login-v2.php');

$cardId = requestRecordId('teacher');
$row = null;
if ($cardId > 0) {
    $statement = getDatabaseConnection()->prepare(
        'SELECT id, teacher_name, teacher_father_name, teacher_mother_name, teacher_index_number, teacher_mobile,
                teacher_subject, teacher_designation, teacher_birth_date, teacher_pic, teacher_status
         FROM teacher_info
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $cardId]);
    $row = $statement->fetch() ?: null;
}

$viewer = getAuthenticatedTeacher();
$isOwnCard = $viewer !== null && $row !== null && (int) ($viewer['id'] ?? 0) === (int) ($row['id'] ?? 0);
if ($row !== null && !$isOwnCard && !canAccessModule('teachers')) {
    denyPanelAccess();
}

$school = currentSchool();
$logo = schoolFileUrl((string) ($school['logo'] ?? ''), '', publicSiteUrl('image/school_logo.png'));
$name = (string) ($row['teacher_name'] ?? '');
$designation = publicStaffDesignationLabel((string) ($row['teacher_designation'] ?? ''));
$subject = publicStaffSubjectLabel((string) ($row['teacher_subject'] ?? ''));
$fatherName = trim((string) ($row['teacher_father_name'] ?? ''));
$motherName = trim((string) ($row['teacher_mother_name'] ?? ''));
$mobile = publicStaffMobileDisplay((string) ($row['teacher_mobile'] ?? ''));
$indexNumber = trim((string) ($row['teacher_index_number'] ?? ''));
$birth = publicStaffDate($row['teacher_birth_date'] ?? null);
$photo = schoolFileUrl((string) ($row['teacher_pic'] ?? ''), 'teacher_pic');
$initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : '';
$cardNumber = $row !== null ? 'BMLHS-' . str_pad((string) (int) $row['id'], 4, '0', STR_PAD_LEFT) : '';
$schoolAddress = schoolPlainAddress((string) $school['address']);
$backUrl = $row !== null && trim((string) ($row['teacher_mobile'] ?? '')) !== ''
    ? 'profile.php?teacher_mobile=' . rawurlencode((string) $row['teacher_mobile'])
    : 'view_all_teacherlist.php';

$profileQrLines = array_filter([
    $name,
    $designation,
    $indexNumber !== '' ? 'Index: ' . $indexNumber : '',
    $mobile !== '' ? 'Mobile: ' . $mobile : '',
]);
$profileQr = $row !== null ? qrCodeSvg(implode("\n", $profileQrLines), 'id-card-qr', $name . ' — পরিচিতি') : '';
$addressQr = qrCodeSvg($schoolAddress, 'id-card-qr', 'বিদ্যালয়ের ঠিকানা');

$idCardCss = dirname(__DIR__, 2) . '/bmlhs/css/id-card.css';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $row ? h($name) . ' — আইডি কার্ড' : 'আইডি কার্ড পাওয়া যায়নি' ?></title>
  <link rel="icon" href="<?= h($logo) ?>">
  <link rel="shortcut icon" href="<?= h($logo) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../bmlhs/css/id-card.css?v=<?= (int) @filemtime($idCardCss) ?>">
</head>
<body>
<?php if ($row === null): ?>
  <div class="idc-toolbar">
    <p class="idc-toolbar-title">আইডি কার্ড পাওয়া যায়নি</p>
    <div class="idc-toolbar-actions">
      <a class="idc-btn" href="view_all_teacherlist.php">শিক্ষক তালিকা</a>
    </div>
  </div>
  <p class="idc-hint">এই কর্মীর তথ্য খুঁজে পাওয়া যায়নি।</p>
<?php else: ?>
  <div class="idc-toolbar">
    <p class="idc-toolbar-title"><?= h($name) ?> — পরিচয়পত্র</p>
    <div class="idc-toolbar-actions">
      <a class="idc-btn" href="<?= h($backUrl) ?>">প্রোফাইলে ফিরুন</a>
      <button class="idc-btn idc-btn-primary" type="button" onclick="window.print()">প্রিন্ট করুন</button>
    </div>
  </div>
  <p class="idc-hint">কার্ডের মাপ ৫৪ × ৮৬ মিলিমিটার (স্ট্যান্ডার্ড আইডি কার্ড)। প্রিন্টে প্রথম পাতায় সামনের দিক ও দ্বিতীয় পাতায় পেছনের দিক আসবে — উভয় পাশে ছাপাতে ডাবল সাইড প্রিন্ট নির্বাচন করুন।</p>

  <div class="idc-stage">
    <div class="idc-slot">
      <p class="idc-slot-label">সামনের দিক</p>
      <div class="id-card">
        <div class="id-card-head">
          <div class="id-card-head-row">
            <img class="id-card-logo" src="<?= h($logo) ?>" alt="" onerror="this.remove()">
            <div>
              <p class="id-card-school-bn"><?= h((string) $school['school_name_bn']) ?></p>
              <p class="id-card-school-en"><?= h((string) $school['school_name_en']) ?></p>
            </div>
          </div>
        </div>
        <p class="id-card-title">পরিচয় পত্র</p>
        <div class="id-card-body">
          <div class="id-card-photo-wrap">
            <span class="id-card-photo-fallback" aria-hidden="true"><?= h($initial) ?></span>
            <img class="id-card-photo" src="<?= h($photo) ?>" alt="<?= h($name) ?>" onerror="this.classList.add('is-broken')">
          </div>
          <p class="id-card-name<?= mb_strlen($name, 'UTF-8') > 22 ? ' is-long' : '' ?>"><?= h($name) ?></p>
          <?php if ($designation !== ''): ?>
            <p class="id-card-role"><?= h($designation) ?></p>
          <?php endif; ?>
          <div class="id-card-detail">
            <ul class="id-card-facts">
              <?php if ($subject !== ''): ?>
                <li><span class="k">বিষয়</span><span class="v"><?= h($subject) ?></span></li>
              <?php endif; ?>
              <?php if ($indexNumber !== ''): ?>
                <li><span class="k">ইনডেক্স</span><span class="v"><?= h($indexNumber) ?></span></li>
              <?php endif; ?>
              <?php if ($fatherName !== ''): ?>
                <li><span class="k">পিতা</span><span class="v"><?= h($fatherName) ?></span></li>
              <?php endif; ?>
              <?php if ($motherName !== ''): ?>
                <li><span class="k">মাতা</span><span class="v"><?= h($motherName) ?></span></li>
              <?php endif; ?>
              <?php if ($mobile !== ''): ?>
                <li class="is-nowrap"><span class="k">মোবাইল</span><span class="v"><?= h($mobile) ?></span></li>
              <?php endif; ?>
              <?php if ($birth !== ''): ?>
                <li class="is-nowrap"><span class="k">জন্ম তারিখ</span><span class="v"><?= h($birth) ?></span></li>
              <?php endif; ?>
            </ul>
            <?php if ($profileQr !== ''): ?>
              <div class="id-card-qr-box"><?= $profileQr ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="id-card-foot">
          <p class="id-card-sign">প্রধান শিক্ষক</p>
          <div class="id-card-strip">
            <span><?= h($cardNumber) ?></span>
            <span>EIIN: <?= h((string) $school['eiin']) ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="idc-slot">
      <p class="idc-slot-label">পেছনের দিক</p>
      <div class="id-card">
        <div class="id-card-head">
          <div class="id-card-head-row">
            <img class="id-card-logo" src="<?= h($logo) ?>" alt="" onerror="this.remove()">
            <div>
              <p class="id-card-school-bn"><?= h((string) $school['school_name_bn']) ?></p>
              <p class="id-card-school-en">স্থাপিত <?= h((string) $school['established']) ?></p>
            </div>
          </div>
        </div>
        <div class="id-card-back-body">
          <p class="id-card-back-title">দৃষ্টি আকর্ষণ</p>
          <p class="id-card-appeal">এই কার্ডটি প্রতিষ্ঠানের নিজস্ব পরিচিতি। ব্যবহারকারী ব্যতীত অন্য কোথাও পাওয়া গেলে অনুগ্রহ করে প্রতিষ্ঠানের ঠিকানায় পৌঁছে দেওয়ার জন্য অনুরোধ করা গেল।</p>
          <?php if ($addressQr !== ''): ?>
            <div class="id-card-qr-block">
              <div class="id-card-qr-box"><?= $addressQr ?></div>
              <p class="id-card-qr-caption">স্ক্যান করে বিদ্যালয়ের ঠিকানা দেখুন</p>
            </div>
          <?php endif; ?>
          <div class="id-card-contact">
            <p><?= h($schoolAddress) ?></p>
            <p><span class="k">মোবাইল:</span> <?= h((string) $school['mobile']) ?></p>
            <p><span class="k">ই-মেইল:</span> <?= h((string) $school['email']) ?></p>
          </div>
        </div>
        <div class="id-card-foot">
          <div class="id-card-strip">
            <span>MPO: <?= h((string) $school['mpo_code']) ?></span>
            <span><?= h($cardNumber) ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
</body>
</html>
