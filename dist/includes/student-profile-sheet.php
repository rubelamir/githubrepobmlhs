<?php
declare(strict_types=1);

/** Builds every value the student profile sheet needs; null when the student does not exist. */
function studentProfileSheetData(PDO $db, int $id): ?array
{
    $districtNameSql = (tableExists($db, 'districts') && columnExists($db, 'districts', 'bn_name'))
        ? 'COALESCE(NULLIF(districts.bn_name, \'\'), districts.name)'
        : (tableExists($db, 'districts') ? 'districts.name' : 'NULL');
    $upazilaNameSql = (tableExists($db, 'upazilas') && columnExists($db, 'upazilas', 'bn_name'))
        ? 'COALESCE(NULLIF(upazilas.bn_name, \'\'), upazilas.name)'
        : (tableExists($db, 'upazilas') ? 'upazilas.name' : 'NULL');
    
    $joinDistricts = tableExists($db, 'districts') ? 'LEFT JOIN districts ON s.district = districts.id' : '';
    $joinUpazilas = tableExists($db, 'upazilas') ? 'LEFT JOIN upazilas ON s.upazilla = upazilas.id' : '';
    
    $rows = fetchRows(
        $db,
        "SELECT s.*, c.class_name_ban AS cls, sec.section_name_ban AS sections_name,
                {$districtNameSql} AS district, {$upazilaNameSql} AS upazilla
         FROM students_info s
         LEFT JOIN cls c ON s.cls = c.id
         LEFT JOIN sections sec ON CAST(s.sections AS CHAR) = CAST(sec.id AS CHAR)
         {$joinDistricts}
         {$joinUpazilas}
         WHERE s.id = :id
         LIMIT 1",
        ['id' => $id]
    );
    $row = $rows[0] ?? [];
    if ($row === []) {
        return null;
    }
    
    $school = schoolSettings($db);
    $logo = schoolFileUrl((string) ($school['logo'] ?? ''), '', publicSiteUrl('image/school_logo.png'));
    $photoFallback = publicSiteUrl('image/student.png');
    $photo = studentPhotoUrl((string) ($row['student_pic'] ?? ''));
    if (trim((string) ($row['student_pic'] ?? '')) === '') {
        $photo = $photoFallback;
    }
    $studentName = trim((string) ($row['name_ban'] ?? ''));
    if ($studentName === '') {
        $studentName = trim((string) ($row['name_eng'] ?? 'শিক্ষার্থী'));
    }
    $profileUrl = publicAbsoluteUrl(publicStudentProfileUrl($id));
    $profileQr = qrCodeSvg($profileUrl, 'adm-a4-qr-svg', $studentName . ' — প্রোফাইল');
    
    $isAdmitted = trim((string) ($row['cls_roll'] ?? '')) !== '';
    $sheetTitle = $isAdmitted ? 'শিক্ষার্থীর তথ্য বিবরণী' : 'ভর্তি ফরম';
    $pageTitle = $sheetTitle . ' | School Admin';
    $genderLabel = ((string) ($row['gender'] ?? '') === 'male')
        ? 'ছাত্র'
        : (((string) ($row['gender'] ?? '') === 'female') ? 'ছাত্রী' : '');
    $religionLabels = [
        'Islam' => 'ইসলাম',
        'Hindu' => 'হিন্দু',
        'Buddhist' => 'বৌদ্ধ',
        'Christian' => 'খ্রিস্টান',
    ];
    $religionLabel = $religionLabels[(string) ($row['religion'] ?? '')] ?? (string) ($row['religion'] ?? '');
    $birthRaw = trim((string) ($row['birth_date'] ?? ''));
    $birthDate = ($birthRaw !== '' && !str_starts_with($birthRaw, '0000-00-00'))
        ? date_create($birthRaw)
        : false;
    $birthDisplay = $birthDate instanceof DateTimeInterface ? $birthDate->format('d-m-Y') : $birthRaw;
    $scholarshipLabel = match ((string) ($row['scholarship_stipend_info'] ?? '')) {
        'scholarship' => 'বৃত্তি প্রাপ্ত',
        'stipend' => 'উপবৃত্তি প্রাপ্ত',
        'not_applicable' => 'প্রযোজ্য নহে',
        default => trim((string) ($row['scholarship_stipend_info'] ?? '')),
    };
    
    $formatMobile = static function (string $mobile): string {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if (strlen($digits) >= 11) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5);
        }
    
        return $mobile;
    };
    $mobileDisplay = $formatMobile((string) ($row['mobile'] ?? ''));
    $schoolMobileDisplay = $formatMobile((string) ($school['mobile'] ?? ''));
    $address = preg_replace('/(গ্রাম|ডাকঘর|পোস্ট|থানা|উপজেলা|জেলা|বিভাগ)\s*:\s*/u', '', (string) ($school['address'] ?? ''));
    $address = trim((string) ($address ?? $school['address'] ?? ''));
    
    $display = static function ($value, bool $bn = false): string {
        $text = trim((string) $value);
        if ($text === '' || str_starts_with($text, '0000-00-00')) {
            return '—';
        }
    
        return $bn ? bnDigits($text) : $text;
    };
    
    $classLabel = $display($row['cls'] ?? '');
    $sectionLabel = $display($row['sections_name'] ?? '');
    $rollLabel = $display($row['cls_roll'] ?? '', true);
    $sessionLabel = $display($row['session_year'] ?? '', true);
    $mobileLabel = $display($mobileDisplay, true);
    $pdfFile = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($row['admission_token'] ?? '')) ?: (string) $id;
    $pdfFile = ($isAdmitted ? 'student-profile-' : 'admission-form-') . $pdfFile . '.pdf';

    return get_defined_vars();
}

function studentProfileSheetHtml(array $vars, string $domId = ''): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
?>
              <article class="adm-a4-sheet"<?= $domId !== '' ? ' id="' . h($domId) . '"' : '' ?>>
                <header class="adm-a4-head">
                  <img class="adm-a4-logo" src="<?= h($logo) ?>" alt="" width="72" height="72" onerror="this.remove()">
                  <div class="adm-a4-brand">
                    <h1><?= h((string) ($school['school_name_bn'] ?? '')) ?></h1>
                    <p class="adm-a4-en"><?= h((string) ($school['school_name_en'] ?? '')) ?></p>
                    <p><?= h($address) ?></p>
                    <p>
                      স্থাপিত: <?= h((string) ($school['established'] ?? '')) ?>
                      · ইআইআইএন: <?= h(bnDigits((string) ($school['eiin'] ?? ''))) ?>
                      <?php if (trim((string) ($school['mobile'] ?? '')) !== ''): ?>
                        · মোবাইল: <?= h(bnDigits($schoolMobileDisplay)) ?>
                      <?php endif; ?>
                      <?php if (trim((string) ($school['email'] ?? '')) !== ''): ?>
                        · <?= h((string) $school['email']) ?>
                      <?php endif; ?>
                    </p>
                  </div>
                  <div class="adm-a4-id">
                    <?php if ($profileQr !== ''): ?>
                      <a class="adm-a4-qr" href="<?= h($profileUrl) ?>" target="_blank" rel="noopener" title="স্ক্যান করে শিক্ষার্থীর তথ্য দেখুন">
                        <?= $profileQr ?>
                      </a>
                    <?php endif; ?>
                    <figure class="adm-a4-photo">
                      <img src="<?= h($photo) ?>" alt="<?= h((string) ($row['name_ban'] ?? 'শিক্ষার্থী')) ?>" width="96" height="122" onerror="this.src='<?= h($photoFallback) ?>'">
                    </figure>
                  </div>
                </header>

                <div class="adm-a4-banner">
                  <h2><?= h($sheetTitle) ?></h2>
                  <p>
                    শিক্ষাবর্ষ <?= h($sessionLabel) ?>
                    · <?= h($classLabel) ?> শ্রেণী
                    <?php if ($sectionLabel !== '—'): ?> · শাখা <?= h($sectionLabel) ?><?php endif; ?>
                    <?php if ($rollLabel !== '—'): ?> · রোল <?= h($rollLabel) ?><?php endif; ?>
                  </p>
                </div>

                <div class="adm-a4-grid">
                  <section class="adm-a4-card">
                    <h3>একাডেমিক তথ্য</h3>
                    <table class="adm-a4-fields">
                      <tr><th>শ্রেণী</th><td><?= h($classLabel) ?></td></tr>
                      <tr><th>শাখা</th><td><?= h($sectionLabel) ?></td></tr>
                      <tr><th>রোল</th><td><?= h($rollLabel) ?></td></tr>
                      <tr><th>শিক্ষাবর্ষ</th><td><?= h($sessionLabel) ?></td></tr>
                      <tr><th>রেজি: নং</th><td><?= h($display($row['reg_no'] ?? '', true)) ?></td></tr>
                      <?php if (in_array((string) ($row['scholarship_stipend_info'] ?? ''), ['scholarship', 'stipend'], true)): ?><tr><th>বৃত্তি / উপবৃত্তি</th><td><?= h($scholarshipLabel) ?></td></tr><?php endif; ?>
                      <tr><th>ভর্তি টোকেন</th><td><?= h($display($row['admission_token'] ?? '', true)) ?></td></tr>
                    </table>
                  </section>
                  <section class="adm-a4-card">
                    <h3>ব্যক্তিগত তথ্য</h3>
                    <table class="adm-a4-fields">
                      <tr><th>নাম (বাংলা)</th><td><?= h($display($row['name_ban'] ?? '')) ?></td></tr>
                      <tr><th>নাম (ইংরেজি)</th><td><?= h($display($row['name_eng'] ?? '')) ?></td></tr>
                      <tr><th>লিঙ্গ</th><td><?= h($display($genderLabel)) ?></td></tr>
                      <tr><th>ধর্ম</th><td><?= h($display($religionLabel)) ?></td></tr>
                      <tr><th>জন্ম তারিখ</th><td><?= h($display($birthDisplay, true)) ?></td></tr>
                      <tr><th>জন্ম সনদ নং</th><td><?= h($display($row['birth_reg_no'] ?? '', true)) ?></td></tr>
                      <tr><th>মোবাইল</th><td><?= h($mobileLabel) ?></td></tr>
                    </table>
                  </section>
                  <section class="adm-a4-card">
                    <h3>পিতা-মাতার তথ্য</h3>
                    <table class="adm-a4-fields">
                      <tr><th>পিতার নাম (বাংলা)</th><td><?= h($display($row['fname_ban'] ?? '')) ?></td></tr>
                      <tr><th>পিতার নাম (ইংরেজি)</th><td><?= h($display($row['fname_eng'] ?? '')) ?></td></tr>
                      <tr><th>পিতার এনআইডি</th><td><?= h($display($row['father_nid_no'] ?? '', true)) ?></td></tr>
                      <tr><th>পিতার পেশা</th><td><?= h($display($row['fprofession'] ?? '')) ?></td></tr>
                      <tr><th>মাতার নাম (বাংলা)</th><td><?= h($display($row['mname_ban'] ?? '')) ?></td></tr>
                      <tr><th>মাতার নাম (ইংরেজি)</th><td><?= h($display($row['mname_eng'] ?? '')) ?></td></tr>
                      <tr><th>মাতার এনআইডি</th><td><?= h($display($row['mother_nid_no'] ?? '', true)) ?></td></tr>
                      <tr><th>মাতার পেশা</th><td><?= h($display($row['mprofession'] ?? '')) ?></td></tr>
                    </table>
                  </section>
                  <section class="adm-a4-card">
                    <h3>পূর্বের বিদ্যালয়</h3>
                    <table class="adm-a4-fields">
                      <tr><th>অধ্যয়নকৃত শ্রেণী</th><td><?= h($display($row['last_class'] ?? '')) ?></td></tr>
                      <tr><th>শিক্ষাবর্ষ</th><td><?= h($display($row['passing_year'] ?? '', true)) ?></td></tr>
                      <tr><th>বিদ্যালয়ের নাম</th><td><?= h($display($row['last_school_name'] ?? '')) ?></td></tr>
                    </table>
                  </section>
                  <section class="adm-a4-card adm-a4-span">
                    <h3>স্থায়ী ঠিকানা</h3>
                    <table class="adm-a4-fields adm-a4-address">
                      <tr>
                        <th>বাড়ি / বাসা</th>
                        <td colspan="3"><?= h($display($row['house_name'] ?? '', true)) ?></td>
                      </tr>
                      <tr>
                        <th>গ্রাম</th>
                        <td><?= h($display(trim((string) ($row['village'] ?? '')) !== '' ? $row['village'] : ($row['village_en'] ?? ''))) ?></td>
                        <th>ডাকঘর</th>
                        <td><?= h($display(trim((string) ($row['post_office'] ?? '')) !== '' ? $row['post_office'] : ($row['post_office_en'] ?? ''))) ?></td>
                      </tr>
                      <tr>
                        <th>উপজেলা</th>
                        <td><?= h($display($row['upazilla'] ?? '')) ?></td>
                        <th>জেলা</th>
                        <td><?= h($display($row['district'] ?? '')) ?></td>
                      </tr>
                    </table>
                  </section>
                </div>

                <footer class="adm-a4-foot">
                  <?php if (!$isAdmitted): ?>
                  <div class="adm-a4-seal" aria-hidden="true"></div>
                  <?php endif; ?>
                  <div class="adm-a4-signs">
                    <div>
                      <span class="adm-a4-line"></span>
                      আবেদনকারীর স্বাক্ষর
                    </div>
                    <div class="adm-a4-date">মুদ্রণ: <?= h(bnDigits(date('d-m-Y'))) ?></div>
                    <div>
                      <span class="adm-a4-line"></span>
                      প্রধান শিক্ষক
                    </div>
                  </div>
                  <?php if (!$isAdmitted): ?>
                  <div class="adm-a4-note">
                    <strong>বিশেষ দ্রষ্টব্য:</strong>
                    এই ফরম প্রিন্ট করে নিচের কাগজপত্রসহ শ্রেণী শিক্ষকের নিকট জমা দিন —
                    ১. ভর্তির ফরম · ২. জন্ম সনদের ফটোকপি · ৩. পিতা-মাতার পরিচয়পত্রের ফটোকপি · ৪. সেশন ফি, ভর্তি ফি ও চলতি মাস পর্যন্ত বেতন।
                  </div>
                  <?php endif; ?>
                </footer>
              </article>
<?php

    return (string) ob_get_clean();
}
