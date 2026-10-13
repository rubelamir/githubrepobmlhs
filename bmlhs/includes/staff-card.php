<?php
declare(strict_types=1);

$staffId = (int) ($staffCard['id'] ?? $staffCard['teacher_info_id'] ?? 0);
$staffName = (string) ($staffCard['teacher_name'] ?? '');
$staffDesignation = publicStaffDesignationLabel((string) ($staffCard['teacher_designation'] ?? ''));
$staffSubject = publicStaffSubjectLabel((string) ($staffCard['teacher_subject'] ?? ''));
$staffMobileRaw = (string) ($staffCard['teacher_mobile'] ?? '');
$staffMobile = publicStaffMobileDisplay($staffMobileRaw);
$staffMobileDial = preg_replace('/\D+/', '', $staffMobileRaw) ?? '';
$staffPhoto = schoolMediaUrl((string) ($staffCard['teacher_pic'] ?? ''), 'teacher_pic');
$staffProfile = $staffId > 0 ? publicStaffProfileUrl($staffId) : 'teacher_staff';
$staffInitial = $staffName !== '' ? mb_strtoupper(mb_substr($staffName, 0, 1, 'UTF-8'), 'UTF-8') : '';
?>
<article class="school-staff-card h-100">
  <div class="school-staff-card-top">
    <span class="school-staff-card-crest" aria-hidden="true"></span>
    <div class="school-staff-card-photo">
      <span class="school-staff-card-fallback" aria-hidden="true">
        <?php if ($staffInitial !== ''): ?><?= h($staffInitial) ?><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
      </span>
      <img src="<?= h($staffPhoto) ?>" alt="<?= h($staffName) ?>" width="112" height="142" loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
    </div>
  </div>
  <div class="school-staff-card-body">
    <h3 class="school-staff-card-name"><?= h($staffName) ?></h3>
    <p class="school-staff-card-role"><?= h($staffDesignation !== '' ? $staffDesignation : 'Staff') ?></p>
    <ul class="school-staff-card-facts">
      <?php if ($staffSubject !== ''): ?>
        <li><i class="fa-solid fa-book-open-reader" aria-hidden="true"></i><span><?= h($staffSubject) ?></span></li>
      <?php endif; ?>
      <?php if ($staffMobile !== ''): ?>
        <li>
          <i class="fa-solid fa-phone" aria-hidden="true"></i>
          <?php if ($staffMobileDial !== ''): ?>
            <a href="tel:<?= h($staffMobileDial) ?>"><?= h($staffMobile) ?></a>
          <?php else: ?>
            <span><?= h($staffMobile) ?></span>
          <?php endif; ?>
        </li>
      <?php endif; ?>
    </ul>
  </div>
  <div class="school-staff-card-footer">
    <a class="school-staff-view" href="<?= h($staffProfile) ?>">
      <span>প্রোফাইল দেখুন</span>
      <i class="fa-solid fa-arrow-right-long" aria-hidden="true"></i>
    </a>
  </div>
</article>
