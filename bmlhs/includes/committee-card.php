<?php
declare(strict_types=1);

$committeeName = trim((string) ($committeeCard['name'] ?? ''));
$committeeRole = trim((string) ($committeeCard['role_label'] ?? ''));
$committeeMobileRaw = (string) ($committeeCard['mobile'] ?? '');
$committeeMobile = publicStaffMobileDisplay($committeeMobileRaw);
$committeeMobileDial = preg_replace('/\D+/', '', $committeeMobileRaw) ?? '';
$committeePhoto = trim((string) ($committeeCard['photo'] ?? ''));
$committeeTeacherId = (int) ($committeeCard['teacher_id'] ?? 0);
$committeeTeacherRole = $committeeTeacherId > 0
    ? publicStaffDesignationLabel((string) ($committeeCard['teacher_designation'] ?? ''))
    : '';
$committeeProfile = $committeeTeacherId > 0 ? publicStaffProfileUrl($committeeTeacherId) : '';
$committeeInitial = $committeeName !== '' ? mb_strtoupper(mb_substr($committeeName, 0, 1, 'UTF-8'), 'UTF-8') : '';
$committeeFeatured = in_array((string) ($committeeCard['role_slug'] ?? ''), ['president', 'member_secretary'], true);
?>
<article class="school-committee-card<?= $committeeFeatured ? ' is-featured' : '' ?>">
  <?php if ($committeeRole !== ''): ?>
    <p class="school-committee-ribbon"><?= h($committeeRole) ?></p>
  <?php endif; ?>
  <div class="school-committee-photo">
    <span class="school-committee-fallback" aria-hidden="true">
      <?php if ($committeeInitial !== ''): ?><?= h($committeeInitial) ?><?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
    </span>
    <?php if ($committeePhoto !== ''): ?>
      <img src="<?= h($committeePhoto) ?>" alt="<?= h($committeeName) ?>" width="140" height="178" loading="lazy" decoding="async" onerror="this.classList.add('is-broken')">
    <?php endif; ?>
  </div>
  <div class="school-committee-body">
    <?php if ($committeeName !== ''): ?>
      <h3 class="school-committee-name"><?= h($committeeName) ?></h3>
    <?php endif; ?>
    <?php if ($committeeTeacherRole !== ''): ?>
      <p class="school-committee-job"><?= h($committeeTeacherRole) ?></p>
    <?php endif; ?>
    <?php if ($committeeMobile !== ''): ?>
      <p class="school-committee-mobile">
        <i class="fa-solid fa-phone" aria-hidden="true"></i>
        <?php if ($committeeMobileDial !== ''): ?>
          <a href="tel:<?= h($committeeMobileDial) ?>"><?= h($committeeMobile) ?></a>
        <?php else: ?>
          <span><?= h($committeeMobile) ?></span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
  <?php if ($committeeProfile !== ''): ?>
    <a class="school-committee-link" href="<?= h($committeeProfile) ?>">প্রোফাইল দেখুন <i class="fa-solid fa-arrow-right-long" aria-hidden="true"></i></a>
  <?php endif; ?>
</article>
