<?php
declare(strict_types=1);

$pageAssets = [];
include 'header.php';
include 'navbar&marqueetag.php';

$previewId = publicStudentIdFromRequest();
$searchQuery = publicStudentSearchQuery((string) ($_GET['q'] ?? ''));
$row = publicStudentProfile(publicDatabase(), $previewId);
if ($row === null) {
    http_response_code(404);
}

$backHref = $searchQuery !== '' ? 'student_search?q=' . rawurlencode($searchQuery) : 'students';
$backLabel = $searchQuery !== '' ? '← সার্চ ফলাফলে ফিরুন' : '← ছাত্র-ছাত্রী তালিকা';
?>
<section class="reg_section sweet-mini-section">
  <div class="bg-mini-overlay d-flex align-items-center">
    <div class="container">
      <h2><?= $row ? 'ছাত্র-ছাত্রীর প্রোফাইল' : 'প্রোফাইল পাওয়া যায়নি' ?></h2>
    </div>
  </div>
</section>

<div class="school-staff-profile">
  <div class="container py-4 py-lg-5">
    <div class="school-profile-actions no-print">
      <a class="school-staff-back" href="<?= h($backHref) ?>"><?= h($backLabel) ?></a>
      <?php if ($row !== null): ?>
        <button class="school-profile-print" type="button" onclick="window.print()">
          <i class="fa-solid fa-print" aria-hidden="true"></i>
          <span>প্রোফাইল প্রিন্ট</span>
        </button>
      <?php endif; ?>
    </div>
    <?php if ($row === null): ?>
      <div class="school-result-empty">এই প্রোফাইলটি খুঁজে পাওয়া যায়নি।</div>
    <?php else:
        $printSchool = currentSchool();
        $printLogo = schoolLogoUrl($printSchool);
        $name = publicStudentDisplayName($row);
        $nameEng = trim((string) ($row['name_eng'] ?? ''));
        $photo = studentPhotoUrl((string) ($row['student_pic'] ?? ''));
        $classLabel = publicStudentClassLabel($row);
        $sectionLabel = publicStudentSectionLabel($row);
        $roll = publicHasDisplayValue($row['cls_roll'] ?? '') ? bnDigits((string) $row['cls_roll']) : '';
        $sessionYear = publicHasDisplayValue($row['session_year'] ?? '') ? bnDigits((string) $row['session_year']) : '';
        $mobile = publicStaffMobileDisplay((string) ($row['mobile'] ?? ''));
        $birth = publicStaffDate($row['birth_date'] ?? null);
        $gender = publicStudentGenderLabel((string) ($row['gender'] ?? ''));
        $religion = publicReligionLabel((string) ($row['religion'] ?? ''));
        $addressParts = array_filter([
            trim((string) ($row['house_name'] ?? '')) !== '' ? 'বাড়ি: ' . $row['house_name'] : '',
            trim((string) ($row['village'] ?? '')) !== '' ? 'গ্রাম: ' . $row['village'] : '',
            trim((string) ($row['post_office'] ?? '')) !== '' ? 'ডাকঘর: ' . $row['post_office'] : '',
            trim((string) ($row['upazilla_bn'] ?? $row['upazilla_en'] ?? '')) !== '' ? 'উপজেলা: ' . ($row['upazilla_bn'] ?: $row['upazilla_en']) : '',
            trim((string) ($row['district_bn'] ?? $row['district_en'] ?? '')) !== '' ? 'জেলা: ' . ($row['district_bn'] ?: $row['district_en']) : '',
        ]);
        $kickerParts = array_filter([$classLabel, $sectionLabel !== '' ? 'শাখা: ' . $sectionLabel : '', $roll !== '' ? 'রোল: ' . $roll : '']);
      ?>
      <div class="school-profile-print-sheet">
      <header class="school-profile-print-head">
        <img src="<?= h($printLogo) ?>" alt="" onerror="this.remove()">
        <div>
          <p class="school-profile-print-name"><?= h((string) $printSchool['school_name_bn']) ?></p>
          <p class="school-profile-print-en"><?= h((string) $printSchool['school_name_en']) ?></p>
          <p class="school-profile-print-meta"><?= h(schoolPlainAddress((string) $printSchool['address'])) ?> · EIIN: <?= h((string) $printSchool['eiin']) ?></p>
        </div>
      </header>
      <p class="school-profile-print-banner">ছাত্র-ছাত্রীর প্রোফাইল</p>
      <div class="school-profile-print-body">
      <div class="school-profile-hero">
        <div class="school-profile-photo">
          <span class="school-profile-fallback" aria-hidden="true"><i class="fa-solid fa-user-graduate"></i></span>
          <img src="<?= h($photo) ?>" alt="<?= h($name) ?>" width="160" height="196" onerror="this.classList.add('is-broken')">
        </div>
        <div>
          <?php if ($kickerParts !== []): ?>
            <p class="school-profile-kicker"><?= h(implode(' · ', $kickerParts)) ?></p>
          <?php endif; ?>
          <h1><?= h($name !== '' ? $name : 'ছাত্র-ছাত্রী') ?></h1>
          <?php if ($nameEng !== '' && $nameEng !== $name): ?>
            <p class="school-profile-subject"><?= h($nameEng) ?></p>
          <?php endif; ?>
          <div class="school-profile-contacts">
            <?php if ($mobile !== ''): ?>
              <?php $mobileDial = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? '')) ?? ''; ?>
              <a href="tel:<?= h($mobileDial) ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> <?= h(bnDigits($mobile)) ?></a>
            <?php endif; ?>
            <?php if ($sessionYear !== ''): ?>
              <span><i class="fa-solid fa-calendar" aria-hidden="true"></i> শিক্ষাবর্ষ <?= h($sessionYear) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <?php
          ob_start();
          $academicCount = 0;
          $academicCount += (int) echoPublicProfileRow('শ্রেণী', $classLabel);
          $academicCount += (int) echoPublicProfileRow('শাখা', $sectionLabel);
          $academicCount += (int) echoPublicProfileRow('রোল', $roll);
          $academicCount += (int) echoPublicProfileRow('শিক্ষাবর্ষ', $sessionYear);
          $academicHtml = ob_get_clean();

          ob_start();
          $personalCount = 0;
          $personalCount += (int) echoPublicProfileRow('পিতার নাম', $row['fname_ban'] ?: ($row['fname_eng'] ?? ''));
          $personalCount += (int) echoPublicProfileRow('মাতার নাম', $row['mname_ban'] ?: ($row['mname_eng'] ?? ''));
          $personalCount += (int) echoPublicProfileRow('লিঙ্গ', $gender);
          $personalCount += (int) echoPublicProfileRow('ধর্ম', $religion);
          $personalCount += (int) echoPublicProfileRow('জন্ম তারিখ', $birth !== '' ? bnDigits($birth) : '');
          $personalCount += (int) echoPublicProfileRow('মোবাইল নম্বর', $mobile !== '' ? bnDigits($mobile) : '');
          $personalCount += (int) echoPublicProfileRow('ঠিকানা', $addressParts !== [] ? implode(', ', $addressParts) : '');
          $personalHtml = ob_get_clean();
        ?>
        <?php if ($academicCount > 0): ?>
        <div class="col-lg-6">
          <section class="school-profile-panel">
            <h2>একাডেমিক তথ্য</h2>
            <dl class="school-profile-list"><?= $academicHtml ?></dl>
          </section>
        </div>
        <?php endif; ?>
        <?php if ($personalCount > 0): ?>
        <div class="col-lg-6">
          <section class="school-profile-panel">
            <h2>ব্যক্তিগত তথ্য</h2>
            <dl class="school-profile-list"><?= $personalHtml ?></dl>
          </section>
        </div>
        <?php endif; ?>
      </div>
      </div>
      <footer class="school-profile-print-foot">
        <div class="school-profile-print-signs">
          <span>মুদ্রণের তারিখ: <?= h(bnDigits(date('d-m-Y'))) ?></span>
          <span class="school-profile-print-sign">প্রধান শিক্ষক</span>
        </div>
      </footer>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include 'footer.php'; ?>
