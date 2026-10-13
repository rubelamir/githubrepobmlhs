<?php
$pageAssets = [];
$pageMeta = ['title' => 'শিক্ষার্থীর তথ্য বিবরণী'];
include('header.php');
include('navbar&marqueetag.php');

if (empty($_SESSION['admission_login_id']) || !ctype_digit((string) $_SESSION['admission_login_id'])) {
    header('Location: admission_login');
    exit;
}

$studentId = (int) $_SESSION['admission_login_id'];
$sql = "SELECT cls.class_name_ban as cls, s.cls_roll, sections.section_name_ban as sections_name, s.session_year, s.admission_token, s.name_ban, s.name_eng, s.gender, s.religion, s.fname_ban, s.fname_eng, s.father_nid_no, s.mname_ban, s.mname_eng, s.mother_nid_no, s.mobile, s.birth_date, s.birth_reg_no, s.last_class, s.exam_roll, s.passing_year, s.scholarship_stipend_info, s.last_school_name, s.house_name, s.village, s.post_office, upazilas.bn_name as upazilla, districts.bn_name as district, s.student_pic FROM students_info s LEFT JOIN cls ON s.cls = cls.id LEFT JOIN sections ON s.sections = sections.id LEFT JOIN divisions ON s.division = divisions.id LEFT JOIN districts ON s.district = districts.id LEFT JOIN upazilas ON s.upazilla = upazilas.id WHERE s.id = ?";
$statement = $conn->prepare($sql);
$statement->bind_param('i', $studentId);
$statement->execute();
$query = $statement->get_result();
$row = ($query instanceof mysqli_result) ? $query->fetch_assoc() : null;
$statement->close();
if (!$row) {
    unset($_SESSION['admission_login_id']);
    header('Location: admission_login');
    exit;
}

$school = currentSchool();
$logo = schoolLogoUrl($school);
$isAdmitted = publicHasDisplayValue($row['cls_roll'] ?? '');
$sheetTitle = $isAdmitted ? 'শিক্ষার্থীর তথ্য বিবরণী' : 'ভর্তি ফরম';
$photo = studentPhotoUrl((string) ($row['student_pic'] ?? ''));
$studentName = trim((string) ($row['name_ban'] ?? ''));
if ($studentName === '') {
    $studentName = trim((string) ($row['name_eng'] ?? 'শিক্ষার্থী'));
}
$profileUrl = publicAbsoluteUrl(publicStudentProfileUrl($studentId));
$profileQr = qrCodeSvg($profileUrl, 'adm-a4-qr-svg', $studentName . ' — প্রোফাইল');
$genderLabel = ($row['gender'] ?? '') === 'male' ? 'ছাত্র' : (($row['gender'] ?? '') === 'female' ? 'ছাত্রী' : '');
$religionLabel = publicReligionLabel((string) ($row['religion'] ?? ''));
$birthDisplay = publicStaffDate($row['birth_date'] ?? null);
$scholarshipLabel = match ((string) ($row['scholarship_stipend_info'] ?? '')) {
    'scholarship' => 'বৃত্তি প্রাপ্ত',
    'stipend' => 'উপবৃত্তি প্রাপ্ত',
    'not_applicable' => 'প্রযোজ্য নহে',
    default => trim((string) ($row['scholarship_stipend_info'] ?? '')),
};
$display = static function ($value, bool $bn = false): string {
    $text = trim((string) $value);
    if ($text === '' || str_starts_with($text, '0000-00-00')) {
        return '—';
    }
    return $bn ? english_to_bengali($text) : $text;
};
$classLabel = $display($row['cls'] ?? '');
$sectionLabel = $display($row['sections_name'] ?? '');
$rollLabel = $display($row['cls_roll'] ?? '', true);
$sessionLabel = $display($row['session_year'] ?? '', true);
$mobileLabel = $display(publicStaffMobileDisplay((string) ($row['mobile'] ?? '')), true);
$pdfFile = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($row['admission_token'] ?? '')) ?: 'form';
$pdfFile = ($isAdmitted ? 'student-profile-' : 'admission-form-') . $pdfFile . '.pdf';
?>
<style>
@page { size: auto; margin: 12mm; }
@media print {
  @page { size: auto; margin: 12mm; }
}
</style>
<div class="adm-a4-wrap">
    <div class="no-print"><?php include('admission_navigation.php'); ?></div>
    <div class="container adm-a4-toolbar no-print">
        <p class="adm-a4-toolbar-note">A4 / Letter · এক পৃষ্ঠা · স্ট্যান্ডার্ড মার্জিন</p>
        <div class="adm-a4-toolbar-actions">
            <button class="school-profile-print" type="button" onclick="window.print()">
                <i class="fa-solid fa-print" aria-hidden="true"></i>
                <span>প্রিন্ট করুন</span>
            </button>
            <button class="school-profile-print" type="button" id="admA4PdfBtn" data-pdf-name="<?= h($pdfFile) ?>">
                <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                <span>PDF ডাউনলোড</span>
            </button>
        </div>
    </div>

    <article class="adm-a4-sheet" id="printDiv">
        <header class="adm-a4-head">
            <img class="adm-a4-logo" src="<?= h($logo) ?>" alt="" width="72" height="72" onerror="this.remove()">
            <div class="adm-a4-brand">
                <h1><?= h((string) ($school['school_name_bn'] ?? '')) ?></h1>
                <p class="adm-a4-en"><?= h((string) ($school['school_name_en'] ?? '')) ?></p>
                <p><?= h(schoolPlainAddress((string) ($school['address'] ?? ''))) ?></p>
                <p>
                    স্থাপিত: <?= h((string) ($school['established'] ?? '')) ?>
                    · ইআইআইএন: <?= h(english_to_bengali((string) ($school['eiin'] ?? ''))) ?>
                    <?php if (trim((string) ($school['mobile'] ?? '')) !== ''): ?>
                        · মোবাইল: <?= h(english_to_bengali(publicStaffMobileDisplay((string) $school['mobile']))) ?>
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
                    <img src="<?= h($photo) ?>" alt="<?= h((string) ($row['name_ban'] ?? 'শিক্ষার্থী')) ?>" width="96" height="122" onerror="this.src='image/student.png'">
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
                    <tr><th>মাতার নাম (বাংলা)</th><td><?= h($display($row['mname_ban'] ?? '')) ?></td></tr>
                    <tr><th>মাতার নাম (ইংরেজি)</th><td><?= h($display($row['mname_eng'] ?? '')) ?></td></tr>
                    <tr><th>মাতার এনআইডি</th><td><?= h($display($row['mother_nid_no'] ?? '', true)) ?></td></tr>
                </table>
            </section>
            <section class="adm-a4-card">
                <h3>পূর্বের বিদ্যালয়</h3>
                <table class="adm-a4-fields">
                    <tr><th>অধ্যয়নকৃত শ্রেণী</th><td><?= h($display($row['last_class'] ?? '')) ?></td></tr>
                    <tr><th>শিক্ষাবর্ষ</th><td><?= h($display($row['passing_year'] ?? '', true)) ?></td></tr>
                    <tr><th>বৃত্তি / উপবৃত্তি</th><td><?= h($display($scholarshipLabel)) ?></td></tr>
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
                        <td><?= h($display($row['village'] ?? '')) ?></td>
                        <th>ডাকঘর</th>
                        <td><?= h($display($row['post_office'] ?? '')) ?></td>
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
                <div class="adm-a4-date">মুদ্রণ: <?= h(english_to_bengali(date('d-m-Y'))) ?></div>
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
</div>
<script>
(function () {
  var btn = document.getElementById('admA4PdfBtn');
  var sheet = document.getElementById('printDiv');
  if (!btn || !sheet) {
    return;
  }
  var libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'
  ];
  var busy = false;
  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var found = document.querySelector('script[src="' + src + '"]');
      if (found && found.getAttribute('data-loaded') === '1') {
        resolve();
        return;
      }
      var script = found || document.createElement('script');
      script.onload = function () {
        script.setAttribute('data-loaded', '1');
        resolve();
      };
      script.onerror = reject;
      if (!found) {
        script.src = src;
        document.head.appendChild(script);
      }
    });
  }
  function loadLibs() {
    if (window.html2canvas && window.jspdf && window.jspdf.jsPDF) {
      return Promise.resolve();
    }
    return loadScript(libs[0]).then(function () {
      return loadScript(libs[1]);
    });
  }
  function waitImages(root) {
    var images = Array.prototype.slice.call(root.querySelectorAll('img'));
    return Promise.all(images.map(function (img) {
      if (img.complete) {
        return Promise.resolve();
      }
      return new Promise(function (resolve) {
        img.onload = resolve;
        img.onerror = resolve;
      });
    }));
  }
  function fitPdfImage(pdf, canvas) {
    var pageW = 210;
    var pageH = 297;
    var imgW = pageW;
    var imgH = canvas.height * pageW / canvas.width;
    if (imgH > pageH) {
      imgW = pageW * (pageH / imgH);
      imgH = pageH;
    }
    var x = (pageW - imgW) / 2;
    var y = 0;
    pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', x, y, imgW, imgH, undefined, 'FAST');
  }
  btn.addEventListener('click', function () {
    if (busy) {
      return;
    }
    var label = btn.querySelector('span');
    var previous = label ? label.textContent : '';
    busy = true;
    btn.disabled = true;
    if (label) {
      label.textContent = 'PDF তৈরি হচ্ছে…';
    }
    var host = document.createElement('div');
    host.className = 'adm-a4-pdf-host';
    var clone = sheet.cloneNode(true);
    clone.removeAttribute('id');
    clone.classList.add('adm-a4-pdf-sheet');
    host.appendChild(clone);
    document.body.appendChild(host);
    var fontsReady = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
    loadLibs().then(function () {
      return fontsReady;
    }).then(function () {
      return waitImages(clone);
    }).then(function () {
      return window.html2canvas(clone, {
        scale: 2,
        width: 794,
        windowWidth: 794,
        scrollX: 0,
        scrollY: 0,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        logging: false
      });
    }).then(function (canvas) {
      var pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      fitPdfImage(pdf, canvas);
      pdf.save(btn.getAttribute('data-pdf-name') || 'admission-form.pdf');
    }).catch(function () {
      window.alert('PDF তৈরি করা যায়নি। ইন্টারনেট সংযোগ পরীক্ষা করে আবার চেষ্টা করুন।');
    }).finally(function () {
      if (host.parentNode) {
        host.parentNode.removeChild(host);
      }
      busy = false;
      btn.disabled = false;
      if (label) {
        label.textContent = previous;
      }
    });
  });
})();
</script>
<?php include('footer.php'); ?>
