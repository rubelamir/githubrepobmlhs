<?php
declare(strict_types=1);

$pageAssets = [];
include 'header.php';
include 'navbar&marqueetag.php';

$committeeDb = publicDatabase();
$committeeMembers = publicCommitteeMembers($committeeDb, true);
$featuredSlugs = ['president', 'member_secretary'];
$featured = [];
$rest = [];
foreach ($committeeMembers as $committeeCard) {
    if (in_array((string) ($committeeCard['role_slug'] ?? ''), $featuredSlugs, true) && count($featured) < 2) {
        $featured[] = $committeeCard;
    } else {
        $rest[] = $committeeCard;
    }
}
$yearLabel = function_exists('english_to_bengali') ? english_to_bengali((string) date('Y')) : date('Y');
?>
<section class="reg_section sweet-mini-section">
  <div class="bg-mini-overlay d-flex align-items-center">
    <div class="container">
      <h2>বিদ্যালয় পরিচালনা পর্ষদ (ম্যানেজিং কমিটি)</h2>
    </div>
  </div>
</section>

<div class="school-committee-page">
  <div class="container py-4 py-lg-5">
    <p class="school-staff-intro mb-4">
      <?= h((string) currentSchool()['school_name_bn']) ?> পরিচালনা পর্ষদের সম্মানিত সদস্যবৃন্দ, <?= h($yearLabel) ?> শিক্ষাবর্ষ।
    </p>
    <?php if ($committeeMembers === []): ?>
      <div class="school-result-empty">এই মুহূর্তে কমিটির তথ্য প্রকাশিত নেই।</div>
    <?php else: ?>
      <?php if ($featured !== []): ?>
        <div class="school-committee-featured<?= count($featured) === 1 ? ' is-single' : '' ?> mb-4">
          <?php foreach ($featured as $committeeCard): ?>
            <div><?php include __DIR__ . '/includes/committee-card.php'; ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($rest !== []): ?>
        <div class="school-committee-grid">
          <?php foreach ($rest as $committeeCard): ?>
            <div><?php include __DIR__ . '/includes/committee-card.php'; ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php include 'footer.php'; ?>
