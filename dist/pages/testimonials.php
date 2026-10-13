<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/certificate-border.php';
require_once dirname(__DIR__, 2) . '/shared/certificate-sheet.php';
requireAuthentication('../examples/login-v2.php');

$db = getDatabaseConnection();
ensureSscCandidateSchema($db);

$basePath = '../';
$pageTitle = 'Testimonial | School Admin';
$settings = schoolSettings($db);
$type = 'testimonial';
$lang = certificateLanguage((string) ($_GET['lang'] ?? 'bn'));
$typeTitle = certificateTypeTitle($type, $lang);
$sheetCopy = certificateSheetCopy($lang);
$schoolHeading = certificateSchoolHeading($settings, $lang);
$issueDate = certificateDisplayDate($lang);
$batchYear = trim((string) ($_GET['batch'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$loadList = (string) ($_GET['load'] ?? '') === '1';
$batchYears = sscCandidateBatchYears($db);

if ($batchYear === '' && $batchYears !== [] && $loadList) {
    $batchYear = (string) $batchYears[0];
}

$geoSelect = '';
$geoJoin = '';
if (tableExists($db, 'districts')) {
    $geoJoin .= ' LEFT JOIN districts ON s.district = districts.id';
    if (columnExists($db, 'districts', 'name')) {
        $geoSelect .= ', districts.name AS district_en';
    }
    if (columnExists($db, 'districts', 'bn_name')) {
        $geoSelect .= ', districts.bn_name AS district_bn';
    }
}
if (tableExists($db, 'upazilas')) {
    $geoJoin .= ' LEFT JOIN upazilas ON s.upazilla = upazilas.id';
    if (columnExists($db, 'upazilas', 'name')) {
        $geoSelect .= ', upazilas.name AS upazilla_en';
    }
    if (columnExists($db, 'upazilas', 'bn_name')) {
        $geoSelect .= ', upazilas.bn_name AS upazilla_bn';
    }
}

$students = [];
if ($loadList && $batchYear !== '') {
    $sql = "SELECT s.*, c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name{$geoSelect}
            FROM students_info s"
        . studentsListJoinSql() . $geoJoin . '
            WHERE s.status = :status AND s.ssc_batch_year = :batch';
    $params = [
        'status' => studentStatusSscCandidate(),
        'batch' => $batchYear,
    ];
    if ($search !== '') {
        $sql .= ' AND (s.name_ban LIKE :q_name_ban OR s.name_eng LIKE :q_name_eng OR s.mobile LIKE :q_mobile
                  OR s.cls_roll LIKE :q_roll OR s.ssc_roll_no LIKE :q_ssc_roll OR s.ssc_reg_no LIKE :q_ssc_reg)';
        $like = '%' . $search . '%';
        $params['q_name_ban'] = $like;
        $params['q_name_eng'] = $like;
        $params['q_mobile'] = $like;
        $params['q_roll'] = $like;
        $params['q_ssc_roll'] = $like;
        $params['q_ssc_reg'] = $like;
    }
    $sql .= ' ORDER BY CAST(NULLIF(s.ssc_roll_no, \'\') AS UNSIGNED) ASC, ' . studentsListOrderSql() . ' LIMIT 200';
    $students = fetchRows($db, $sql, $params);
}

$pdfQuery = [
    'download' => 'pdf',
    'load' => '1',
    'lang' => $lang,
];
if ($batchYear !== '') {
    $pdfQuery['batch'] = $batchYear;
}
if ($search !== '') {
    $pdfQuery['q'] = $search;
}

$pdfFile = 'testimonials-' . preg_replace('/[^A-Za-z0-9]+/', '', $batchYear !== '' ? $batchYear : 'batch') . '-' . $lang . '.pdf';

if (($_GET['download'] ?? '') === 'pdf') {
    if ($students === []) {
        header('Location: testimonials.php', true, 302);
        exit;
    }
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        require_once dirname(__DIR__, 2) . '/shared/certificates-pdf.php';
        outputCertificatesPdf($students, $settings, $type, $typeTitle, $issueDate, $pdfFile, $lang);
    } catch (Throwable $exception) {
        error_log('Testimonial PDF failed: ' . $exception->getMessage());
        redirectWithMessage('testimonials.php?batch=' . rawurlencode($batchYear), 'danger', 'PDF তৈরি করা যায়নি।');
    }
    exit;
}

$pageStyles = <<<'CSS'
@font-face {
  font-family: "Hind Siliguri";
  src: url("../../bmlhs/fonts/ttf/HindSiliguri-Regular.ttf") format("truetype");
  font-weight: 400;
  font-display: swap;
}
@font-face {
  font-family: "Hind Siliguri";
  src: url("../../bmlhs/fonts/ttf/HindSiliguri-Bold.ttf") format("truetype");
  font-weight: 700;
  font-display: swap;
}
@page { size: A4 portrait; margin: 0; }
CSS
    . certificateTestimonialSheetCss('html') . <<<'CSS'
.testimonial-toolbar .btn { white-space: nowrap; }
@media print {
  a[href]::after,
  a[href]::before {
    content: none !important;
  }
  html, body {
    background: #fff !important;
    margin: 0 !important;
    padding: 0 !important;
    height: auto !important;
    min-height: 0 !important;
    overflow: visible !important;
  }
  body.layout-fixed {
    min-height: 0 !important;
    height: auto !important;
  }
  .no-print, .app-sidebar, .app-header, .app-footer { display: none !important; }
  .app-wrapper,
  .app-main,
  .app-content,
  .app-content > .container-fluid {
    display: block !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    max-width: none !important;
    min-height: 0 !important;
    height: auto !important;
    overflow: visible !important;
    background: #fff !important;
  }
  .certificate-print-root {
    display: block !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  .certificate-sheet {
    width: 210mm !important;
    max-width: 210mm !important;
    /* Slightly under A4 to avoid browser rounding → blank trailing page */
    min-height: 296mm !important;
    height: 296mm !important;
    max-height: 296mm !important;
    margin: 0 !important;
    box-shadow: none !important;
    overflow: hidden !important;
    box-sizing: border-box !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
    page-break-after: auto !important;
    break-after: auto !important;
  }
  .certificate-print-root .certificate-sheet:not(:last-child) {
    page-break-after: always !important;
    break-after: page !important;
  }
  .certificate-print-root .certificate-sheet:last-child {
    page-break-after: avoid !important;
    break-after: avoid !important;
  }
}
CSS;

$pageStyles .= $lang === 'en'
    ? "\n.certificate-sheet.is-english .certificate-brand .h3 { font-size: 18pt; }\n"
    : '';

include __DIR__ . '/../includes/layout-head.php';
?>
        <div class="app-content-header no-print">
          <div class="container-fluid d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
              <h3 class="mb-0">প্রশংসাপত্র / Testimonial</h3>
              <p class="text-secondary mb-0">পরীক্ষার্থী ব্যাচ ফিল্টার করে Testimonial দেখুন ও প্রিন্ট করুন। SSC তথ্য আপডেট করতে নিচের লিংক ব্যবহার করুন।</p>
            </div>
            <div class="d-flex flex-wrap gap-2 testimonial-toolbar">
              <?php if ($batchYear !== ''): ?>
                <a class="btn btn-outline-primary" href="<?= h('ssc-batch-data.php?batch=' . rawurlencode($batchYear)) ?>">
                  <i class="bi bi-pencil-square me-1"></i>SSC তথ্য আপডেট
                </a>
              <?php else: ?>
                <a class="btn btn-outline-primary" href="ssc-batch-data.php">
                  <i class="bi bi-pencil-square me-1"></i>SSC তথ্য আপডেট
                </a>
              <?php endif; ?>
              <?php if ($students !== []): ?>
                <button class="btn btn-primary" type="button" onclick="window.print()">
                  <?= count($students) > 1 ? 'সকল Testimonial প্রিন্ট (' . count($students) . ')' : 'প্রিন্ট' ?>
                </button>
                <a class="btn btn-outline-success" href="<?= h('testimonials.php?' . http_build_query($pdfQuery)) ?>">
                  <i class="bi bi-file-earmark-pdf me-1"></i>PDF ডাউনলোড
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../includes/flash.php'; ?>
            <form method="get" class="card stat-card mb-3 no-print">
              <div class="card-body">
                <input type="hidden" name="load" value="1">
                <div class="row g-2 align-items-end">
                  <div class="col-md-6">
                    <label class="form-label" for="filterBatch">এসএসসি ব্যাচ (সাল)</label>
                    <?php if ($batchYears !== []): ?>
                      <select class="form-select" name="batch" id="filterBatch" required>
                        <option value="">ব্যাচ বাছাই করুন</option>
                        <?php foreach ($batchYears as $year): ?>
                          <option value="<?= h($year) ?>" <?= $batchYear === $year ? 'selected' : '' ?>><?= h(bnDigits($year)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input class="form-control" name="batch" id="filterBatch" value="<?= h($batchYear) ?>" placeholder="যেমন: ২০২৬" required>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="filterLang">ভাষা</label>
                    <select class="form-select" name="lang" id="filterLang">
                      <option value="bn" <?= $lang === 'bn' ? 'selected' : '' ?>>বাংলা</option>
                      <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                  </div>
                </div>
                <div class="mt-3">
                  <div class="d-flex gap-2 align-items-end flex-wrap">
                    <div class="flex-grow-1" style="min-width: 200px;">
                      <label class="form-label" for="filterSearch">Search <span class="text-secondary fw-normal">(Optional)</span></label>
                      <input class="form-control" type="text" name="q" id="filterSearch" value="<?= h($search) ?>" placeholder="Name, mobile, SSC roll/reg">
                    </div>
                    <div class="flex-shrink-0">
                      <label class="form-label d-none d-sm-block">&nbsp;</label>
                      <button class="btn btn-outline-primary px-4" type="submit">ফিল্টার করুন</button>
                    </div>
                  </div>
                  <div class="form-text mt-2">
                    এসএসসি ব্যাচ = Class Promotion → পরীক্ষার্থী ব্যাচ (সাল)। SSC Session আলাদা ফিল্ড—আপডেট:
                    <a href="<?= h($batchYear !== '' ? ('ssc-batch-data.php?batch=' . rawurlencode($batchYear)) : 'ssc-batch-data.php') ?>">SSC তথ্য আপডেট পেজ</a>
                  </div>
                </div>
              </div>
            </form>

            <?php if (!$loadList): ?>
              <div class="card stat-card">
                <div class="card-body text-secondary">এসএসসি ব্যাচ নির্বাচন করে ফিল্টার করুন—Testimonial এখানে দেখাবে।</div>
              </div>
            <?php elseif ($batchYears === [] && $students === []): ?>
              <div class="card stat-card">
                <div class="card-body text-secondary">
                  এখনো কোনো পরীক্ষার্থী ব্যাচ নেই। Class Promotion থেকে ১০ম শ্রেণীর শিক্ষার্থীদের
                  <strong>পরীক্ষার্থী ব্যাচ (SSC)</strong>-এ পাঠান।
                </div>
              </div>
            <?php elseif ($students === []): ?>
              <div class="card stat-card"><div class="card-body text-secondary">এই ফিল্টারে কোনো শিক্ষার্থী নেই।</div></div>
            <?php else: ?>
              <div class="alert alert-light border no-print mb-3">
                <strong><?= count($students) ?></strong> জন · ব্যাচ <?= h(bnDigits($batchYear)) ?>
              </div>
              <div class="certificate-print-root">
              <?php foreach ($students as $sheetStudent): ?>
                <?php
                  $sheetId = (int) ($sheetStudent['id'] ?? 0);
                  $profileUrl = $sheetId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($sheetId)) : '';
                  echo certificateTestimonialSheetHtml($sheetStudent, [
                      'settings' => $settings,
                      'type' => $type,
                      'typeTitle' => $typeTitle,
                      'lang' => $lang,
                      'sheetCopy' => $sheetCopy,
                      'schoolHeading' => $schoolHeading,
                      'issueDate' => $issueDate,
                      'profileUrl' => $profileUrl,
                      'sheetId' => $sheetId,
                  ], 'html');
                ?>
              <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../includes/layout-end.php'; ?>
