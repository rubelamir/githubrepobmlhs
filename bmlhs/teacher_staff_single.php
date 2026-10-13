<?php
$pageAssets = [];
include 'header.php';
include 'navbar&marqueetag.php';

$previewId = publicRecordId('teacher');

$row = null;
if ($previewId > 0) {
    $statement = $conn->prepare(
        'SELECT teacher_info.id, teacher_info.teacher_name, teacher_info.teacher_father_name, teacher_info.teacher_mother_name,
                teacher_info.teacher_index_number, teacher_info.teacher_pds_id, teacher_info.teacher_nid_no, teacher_info.teacher_religion,
                teacher_info.teacher_mobile, teacher_info.teacher_email, teacher_info.teacher_subject,
                teacher_info.teacher_designation, teacher_info.first_working_school, teacher_info.teacher_birth_date,
                teacher_info.first_joining_date, teacher_info.teacher_joining_date, teacher_info.teacher_mpo_date,
                teacher_info.teacher_post_office, teacher_info.teacher_village, teacher_info.educational_details,
                teacher_info.training_details, teacher_info.teacher_pic, teacher_info.teacher_status,
                divisions.name AS divisions_name, districts.name AS districts_name, upazilas.name AS upazilas_name
         FROM teacher_info
         LEFT JOIN divisions ON teacher_info.teacher_division = divisions.id
         LEFT JOIN districts ON teacher_info.teacher_district = districts.id
         LEFT JOIN upazilas ON teacher_info.teacher_upazilla = upazilas.id
         WHERE teacher_info.id = ?
         LIMIT 1'
    );
    $statement->bind_param('i', $previewId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: null;
    $statement->close();
}

if ($row === null) {
    http_response_code(404);
}
?>
<section class="reg_section sweet-mini-section">
  <div class="bg-mini-overlay d-flex align-items-center">
    <div class="container">
      <h2><?= $row ? 'PROFILE' : 'প্রোফাইল পাওয়া যায়নি' ?></h2>
    </div>
  </div>
</section>

<div class="school-staff-profile">
  <div class="container py-4 py-lg-5">
    <div class="school-profile-actions no-print">
      <a class="school-staff-back" href="teacher_staff">← শিক্ষক ও কর্মচারী তালিকা</a>
      <?php if ($row !== null): ?>
        <button class="school-profile-print" type="button" onclick="window.print()">
          <i class="fa-solid fa-print" aria-hidden="true"></i>
          <span>প্রোফাইল প্রিন্ট</span>
        </button>
      <?php endif; ?>
    </div>
    <?php if ($row === null): ?>
      <div class="school-result-empty">এই প্রোফাইলটি খুঁজে পাওয়া যায়নি।</div>
    <?php else: ?>
      <?php
        $printSchool = currentSchool();
        $printLogo = schoolLogoUrl($printSchool);
        $designation = publicStaffDesignationLabel((string) ($row['teacher_designation'] ?? ''));
        $subject = publicStaffSubjectLabel((string) ($row['teacher_subject'] ?? ''));
        $photo = schoolMediaUrl((string) ($row['teacher_pic'] ?? ''), 'teacher_pic');
        $addressParts = array_filter([
            trim((string) ($row['teacher_village'] ?? '')) !== '' ? 'গ্রাম: ' . $row['teacher_village'] : '',
            trim((string) ($row['teacher_post_office'] ?? '')) !== '' ? 'ডাকঘর: ' . $row['teacher_post_office'] : '',
            trim((string) ($row['upazilas_name'] ?? '')) !== '' ? 'উপজেলা: ' . $row['upazilas_name'] : '',
            trim((string) ($row['districts_name'] ?? '')) !== '' ? 'জেলা: ' . $row['districts_name'] : '',
        ]);
        $religion = publicReligionLabel((string) ($row['teacher_religion'] ?? ''));
        $indexNumber = trim((string) ($row['teacher_index_number'] ?? ''));
        $pdsId = trim((string) ($row['teacher_pds_id'] ?? ''));
        $nidNo = trim((string) ($row['teacher_nid_no'] ?? ''));
        $birth = publicStaffDate($row['teacher_birth_date'] ?? null);
        $firstJoin = publicStaffDate($row['first_joining_date'] ?? null);
        $currentJoin = publicStaffDate($row['teacher_joining_date'] ?? null);
        $mpo = publicStaffDate($row['teacher_mpo_date'] ?? null);
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
      <p class="school-profile-print-banner">শিক্ষক ও কর্মচারী প্রোফাইল</p>
      <div class="school-profile-print-body">
      <div class="school-profile-hero">
        <div class="school-profile-photo">
          <span class="school-profile-fallback" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
          <img src="<?= h($photo) ?>" alt="<?= h((string) $row['teacher_name']) ?>" width="160" height="196" onerror="this.classList.add('is-broken')">
        </div>
        <div>
          <?php if ($designation !== ''): ?>
            <p class="school-profile-kicker"><?= h($designation) ?></p>
          <?php endif; ?>
          <h1><?= h((string) $row['teacher_name']) ?></h1>
          <?php if ($subject !== ''): ?>
            <p class="school-profile-subject"><?= h($subject) ?></p>
          <?php endif; ?>
          <div class="school-profile-contacts">
            <?php if (publicHasDisplayValue($row['teacher_mobile'] ?? '')): ?>
              <?php $mobileDial = preg_replace('/\D+/', '', (string) $row['teacher_mobile']) ?? ''; ?>
              <a href="tel:<?= h($mobileDial) ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> <?= h(publicStaffMobileDisplay((string) $row['teacher_mobile'])) ?></a>
            <?php endif; ?>
            <?php if (publicHasDisplayValue($row['teacher_email'] ?? '')): ?>
              <a href="mailto:<?= h((string) $row['teacher_email']) ?>"><i class="fa-solid fa-envelope" aria-hidden="true"></i> <?= h((string) $row['teacher_email']) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <?php
          ob_start();
          $personalCount = 0;
          $personalCount += (int) echoPublicProfileRow('পিতা', $row['teacher_father_name'] ?? '');
          $personalCount += (int) echoPublicProfileRow('মাতা', $row['teacher_mother_name'] ?? '');
          $personalCount += (int) echoPublicProfileRow('ধর্ম', $religion);
          $personalCount += (int) echoPublicProfileRow('জন্ম তারিখ', $birth);
          $personalCount += (int) echoPublicProfileRow('জাতীয় পরিচয়পত্র নং', $nidNo);
          $personalCount += (int) echoPublicProfileRow('ঠিকানা', $addressParts !== [] ? implode(', ', $addressParts) : '');
          $personalCount += (int) echoPublicProfileRow('শিক্ষাগত যোগ্যতা', $row['educational_details'] ?? '');
          $personalHtml = ob_get_clean();

          ob_start();
          $professionalCount = 0;
          $professionalCount += (int) echoPublicProfileRow('পদবি', $designation);
          $professionalCount += (int) echoPublicProfileRow('বিষয়', $subject);
          $professionalCount += (int) echoPublicProfileRow('ইনডেক্স নং', $indexNumber);
          $professionalCount += (int) echoPublicProfileRow('PDS ID', $pdsId);
          $professionalCount += (int) echoPublicProfileRow('প্রথম কর্মস্থল', $row['first_working_school'] ?? '');
          $professionalCount += (int) echoPublicProfileRow('প্রথম যোগদান', $firstJoin);
          $professionalCount += (int) echoPublicProfileRow('এই প্রতিষ্ঠানে যোগদান', $currentJoin);
          $professionalCount += (int) echoPublicProfileRow('এমপিও তারিখ', $mpo);
          $professionalCount += (int) echoPublicProfileRow('প্রশিক্ষণ', $row['training_details'] ?? '');
          $professionalHtml = ob_get_clean();
        ?>
        <?php if ($personalCount > 0): ?>
        <div class="col-lg-6">
          <section class="school-profile-panel">
            <h2>ব্যক্তিগত তথ্য</h2>
            <dl class="school-profile-list"><?= $personalHtml ?></dl>
          </section>
        </div>
        <?php endif; ?>
        <?php if ($professionalCount > 0): ?>
        <div class="col-lg-6">
          <section class="school-profile-panel">
            <h2>পেশাগত তথ্য</h2>
            <dl class="school-profile-list"><?= $professionalHtml ?></dl>
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
