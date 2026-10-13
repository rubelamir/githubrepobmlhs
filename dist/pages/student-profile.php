<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/student-profile-sheet.php';
require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
$basePath = '../';
$id = requestRecordId('student');
if ($id < 1) {
    redirectWithMessage('students.php', 'danger', 'Select a student first.');
}
$studentOwner = fetchRows($db, 'SELECT cls, sections, session_year FROM students_info WHERE id = :id LIMIT 1', ['id' => $id])[0] ?? null;
if (!panelMayViewStudentRecord($db, $studentOwner)) {
    redirectWithMessage('student-profiles.php', 'danger', 'You can view only students of the class assigned to you.');
}

$sheetVars = studentProfileSheetData($db, $id);
if ($sheetVars === null) {
    redirectWithMessage('students.php', 'danger', 'Student not found.');
}
extract($sheetVars, EXTR_SKIP);

$pageCssFiles = [
    'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&family=Hind+Siliguri:wght@400;600;700&display=swap',
    $basePath . 'css/student-profile-a4.css?v=' . (int) @filemtime(__DIR__ . '/../css/student-profile-a4.css'),
];
$pageScripts = [<<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('admA4PdfBtn');
  const sheet = document.getElementById('printDiv');
  if (!btn || !sheet) return;
  const libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'
  ];
  let busy = false;
  const loadScript = (src) => new Promise((resolve, reject) => {
    const found = document.querySelector('script[src="' + src + '"]');
    if (found && found.getAttribute('data-loaded') === '1') {
      resolve();
      return;
    }
    const script = found || document.createElement('script');
    script.onload = () => {
      script.setAttribute('data-loaded', '1');
      resolve();
    };
    script.onerror = reject;
    if (!found) {
      script.src = src;
      document.head.appendChild(script);
    }
  });
  const loadLibs = () => {
    if (window.html2canvas && window.jspdf && window.jspdf.jsPDF) {
      return Promise.resolve();
    }
    return loadScript(libs[0]).then(() => loadScript(libs[1]));
  };
  const waitImages = (root) => Promise.all(Array.from(root.querySelectorAll('img')).map((img) => {
    if (img.complete) return Promise.resolve();
    return new Promise((resolve) => {
      img.onload = resolve;
      img.onerror = resolve;
    });
  }));
  const fitPdfImage = (pdf, canvas) => {
    const pageW = 210;
    const pageH = 297;
    let imgW = pageW;
    let imgH = canvas.height * pageW / canvas.width;
    if (imgH > pageH) {
      imgW = pageW * (pageH / imgH);
      imgH = pageH;
    }
    pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', (pageW - imgW) / 2, 0, imgW, imgH, undefined, 'FAST');
  };
  btn.addEventListener('click', () => {
    if (busy) return;
    const label = btn.querySelector('span');
    const previous = label ? label.textContent : '';
    busy = true;
    btn.disabled = true;
    if (label) label.textContent = 'PDF তৈরি হচ্ছে…';
    const host = document.createElement('div');
    host.className = 'adm-a4-pdf-host';
    const clone = sheet.cloneNode(true);
    clone.removeAttribute('id');
    clone.classList.add('adm-a4-pdf-sheet');
    host.appendChild(clone);
    document.body.appendChild(host);
    const fontsReady = (document.fonts && document.fonts.ready) ? document.fonts.ready : Promise.resolve();
    loadLibs().then(() => fontsReady).then(() => waitImages(clone)).then(() => window.html2canvas(clone, {
      scale: 2,
      width: 794,
      windowWidth: 794,
      scrollX: 0,
      scrollY: 0,
      useCORS: true,
      allowTaint: true,
      backgroundColor: '#ffffff',
      logging: false
    })).then((canvas) => {
      const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      fitPdfImage(pdf, canvas);
      pdf.save(btn.getAttribute('data-pdf-name') || 'student-profile.pdf');
    }).catch(() => {
      window.alert('PDF তৈরি করা যায়নি। ইন্টারনেট সংযোগ পরীক্ষা করে আবার চেষ্টা করুন।');
    }).finally(() => {
      if (host.parentNode) host.parentNode.removeChild(host);
      busy = false;
      btn.disabled = false;
      if (label) label.textContent = previous;
    });
  });
});
JS];

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h3 class="mb-0"><?= h($sheetTitle) ?></h3>
              <p class="text-secondary mb-0">Public admission form layout. Print or download as PDF.</p>
            </div>
            <a class="btn btn-outline-secondary" href="students.php">Back to list</a>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="adm-a4-wrap">
              <div class="adm-a4-toolbar no-print">
                <p class="adm-a4-toolbar-note">A4 / Letter · এক পৃষ্ঠা · স্ট্যান্ডার্ড মার্জিন</p>
                <div class="adm-a4-toolbar-actions">
                  <?php $canEditStudent = canManageModule('admissions') || classTeacherScopeAllows(classTeacherScope($db, getAuthenticatedTeacher(), (string) ($row['session_year'] ?? '')), (int) ($row['cls'] ?? 0), (int) ($row['sections'] ?? 0)); ?>
                  <?php if ($canEditStudent): ?>
                  <a class="school-profile-print" href="<?= h(signedRecordUrl('student-form.php', $id, 'student')) ?>">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    <span>Edit</span>
                  </a>
                  <?php endif; ?>
                  <button class="school-profile-print" type="button" onclick="window.print()">
                    <i class="bi bi-printer" aria-hidden="true"></i>
                    <span>প্রিন্ট করুন</span>
                  </button>
                  <button class="school-profile-print" type="button" id="admA4PdfBtn" data-pdf-name="<?= h($pdfFile) ?>">
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                    <span>PDF ডাউনলোড</span>
                  </button>
                </div>
              </div>

              <?= studentProfileSheetHtml($sheetVars, 'printDiv') ?>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
