<?php
declare(strict_types=1);

if (!function_exists('studentIdCardBackClassLine')) {
    function studentIdCardBackClassLine(array $student): string
    {
        $className = trim((string) ($student['class_name_ban'] ?? ''));
        $section = trim((string) ($student['section_name_ban'] ?? ''));
        $group = trim($className . ($section !== '' ? ' ' . $section : ''));
        $rollRaw = trim((string) ($student['cls_roll'] ?? ''));
        $rollAscii = asciiDigits($rollRaw);
        $rollBn = $rollAscii !== '' && preg_match('/^\d+$/', $rollAscii) === 1
            ? bnDigits(str_pad($rollAscii, 2, '0', STR_PAD_LEFT))
            : ($rollRaw === '' ? '' : bnDigits($rollRaw));

        if ($group !== '' && $rollBn !== '') {
            return '(' . $group . ', রোল-' . $rollBn . ')';
        }
        if ($group !== '') {
            return '(' . $group . ')';
        }

        return $rollBn !== '' ? '(রোল-' . $rollBn . ')' : '';
    }
}

function idCardsUiCss(): string
{
    return <<<'CSS'
.id-card-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
#idCardSheets {
  align-items: center;
}
.id-card-pair {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: .75rem;
  page-break-inside: avoid;
  width: 100%;
}
.id-card-side-label {
  margin: 0 0 .25rem;
  font-size: .75rem;
  font-weight: 700;
  color: #64748b;
}
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
#idCardSheets .id-card {
  position: relative;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  width: 323px;
  height: 204px;
  border: 1px solid #0f2f26;
  border-radius: 8px;
  background: #fff;
  font-family: "Hind Siliguri", "Segoe UI", sans-serif;
  color: #0f2f26;
  box-shadow: 0 10px 22px rgba(15, 47, 38, .14);
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
#idCardSheets .id-card > *:not(.id-card-watermark) {
  position: relative;
  z-index: 1;
}
.id-card-watermark {
  position: absolute;
  left: 0;
  right: 0;
  top: 52px;
  bottom: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  z-index: 0;
}
.id-card-watermark img {
  width: 124px;
  height: 124px;
  object-fit: contain;
  opacity: .18;
}
.id-card-front .id-card-watermark { left: 80px; }
.id-card-front .id-card-head { gap: 16px; }
.id-card-back .id-card-watermark { left: 0; right: 0; }
.id-card-head {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 5px 8px 4px;
  color: #fff;
  background:
    radial-gradient(120% 140% at 8% -30%, rgba(232, 192, 120, .28), transparent 56%),
    linear-gradient(135deg, #0f2f26 0%, #1a4d3e 58%, #246b54 100%);
  border-bottom: 3px solid #c4892a;
  flex-shrink: 0;
}
.id-card-head-logo {
  width: 28px;
  height: 28px;
  object-fit: contain;
  background: transparent;
  flex-shrink: 0;
}
.id-card-school {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.2;
  color: #fff;
}
.id-card-head-meta {
  margin-top: 3px;
  font-size: 10px;
  line-height: 1.2;
  color: rgba(255, 255, 255, .9);
}
.id-card-head, .id-card-head * { color: #fff !important; }
.id-card-title {
  flex-shrink: 0;
  margin: 0;
  padding: 1px 8px;
  text-align: center;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #0f2f26 !important;
  background: linear-gradient(90deg, #f6e7c1, #e8c078 42%, #f6e7c1);
  border-bottom: 1px solid rgba(196, 137, 42, .4);
}
.id-card-body {
  flex: 1;
  min-height: 0;
  display: flex;
  gap: 8px;
  padding: 5px 8px 3px;
  background: transparent;
}
.id-card-photo {
  width: 64px;
  height: 78px;
  object-fit: cover;
  border: 2px solid #1a4d3e;
  border-radius: 4px;
  box-shadow: 0 0 0 2px #e8c078;
  background: #fff;
  position: relative;
  z-index: 2;
  flex-shrink: 0;
}
.id-card-info {
  min-width: 0;
  flex: 1;
  font-size: 12px;
  line-height: 1.28;
}
.id-card-info .name-bn {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.18;
  color: #0f2f26;
}
.id-card-info .name-en {
  margin-bottom: 1px;
  font-size: 10.5px;
  color: #3d5249;
}
.id-card-fact {
  display: flex;
  gap: 6px;
  font-size: 11.5px;
  line-height: 1.26;
}
.id-card-fact .k {
  flex: 0 0 5.1em;
  color: #5a6f66;
  font-weight: 600;
}
.id-card-fact .v {
  min-width: 0;
  color: #0f2f26;
  font-weight: 700;
}
.id-card-foot {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
  padding: 3px 8px 4px;
  background: linear-gradient(180deg, #f7f3ea, #efe4c8);
  border-top: 1px solid rgba(196, 137, 42, .38);
  font-size: 10px;
  color: #0f2f26;
  flex-shrink: 0;
}
.id-card-foot-dark {
  background: linear-gradient(135deg, #0f2f26, #1a4d3e);
  border-top: 3px solid #c4892a;
  color: #fff;
}
.id-card-foot-dark, .id-card-foot-dark * { color: #e8c078 !important; }
.id-card-foot-mobile {
  font-weight: 700;
}
.id-card-sign {
  margin-left: auto;
  min-width: 92px;
  padding-top: 2px;
  border-top: 1px solid #1a4d3e;
  text-align: right;
  font-weight: 700;
}
.id-card-back-body {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  display: flex;
  gap: .5rem;
  padding: .45rem .55rem;
  background: transparent;
}
.id-card-back-copy { flex: 1; min-width: 0; font-size: 10px; line-height: 1.35; color: #0f2f26; }
.id-card-back-title {
  margin: 0 0 .2rem;
  font-size: 11px;
  font-weight: 700;
  color: #0f2f26;
}
.id-card-back-copy p { margin: 0 0 .22rem; color: #1a2a24; }
.id-card-back-divider {
  margin: .32rem 0 .22rem;
  border: 0;
  border-top: 1px solid #c4892a;
}
.id-card-back-copy .id-card-back-class {
  margin: 0;
  font-size: 10px;
  font-weight: 700;
  line-height: 1.3;
  color: #0f2f26;
}
.id-card-qr-wrap { flex: 0 0 76px; width: 76px; max-width: 76px; }
.id-card-qr {
  width: 72px;
  height: 72px;
  min-width: 72px;
  max-width: 72px;
  padding: 2px;
  border: 2px solid #1a4d3e;
  box-shadow: 0 0 0 2px #e8c078;
  border-radius: 4px;
  background: #fff;
  box-sizing: border-box;
  overflow: hidden;
}
.id-card-qr-name {
  margin: .12rem 0 0;
  font-size: 8px;
  font-weight: 700;
  line-height: 1.15;
  text-align: center;
  color: #0f2f26;
  word-break: break-word;
}
@media print {
  @page {
    size: A4 portrait;
    margin: 8mm;
  }
  #idCardSheets {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 4mm !important;
    min-height: 0 !important;
    height: auto !important;
  }
  .id-card-side-label { display: none !important; }
  .id-card-pair {
    justify-content: center;
    align-items: flex-start;
    gap: 4mm;
    margin: 0 auto 4mm;
    width: auto;
    max-width: 100%;
  }
  body.print-id-fronts .id-card-back-wrap { display: none !important; }
  body.print-id-backs .id-card-front-wrap { display: none !important; }
}
.seat-level-page {
  width: 210mm;
  max-width: 100%;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  gap: 6mm;
  padding: 10mm 12mm;
  background: #fff;
  page-break-after: always;
  break-after: page;
}
.seat-level-page:last-child {
  page-break-after: auto;
  break-after: auto;
}
.seat-level-sheet {
  width: 100%;
  max-width: 100%;
  height: 44mm;
  min-height: 44mm;
  max-height: 44mm;
  box-sizing: border-box;
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  justify-content: center;
  align-items: stretch;
  gap: 7mm;
  padding: 0;
  background: transparent;
  page-break-inside: avoid;
  break-inside: avoid;
}
.seat-level-sheet .id-card-pair {
  width: auto;
  height: 100%;
  margin: 0;
  gap: 0;
}
.seat-level-sheet .id-card-front-wrap {
  width: auto;
  height: 100%;
}
.seat-level-sheet .id-card-side-label { display: none; }
#idCardSheets.seat-level-mode {
  gap: 0;
}
#idCardSheets.seat-level-mode .id-card {
  width: 86mm;
  height: 44mm;
  min-height: 44mm;
  max-height: 44mm;
  border-radius: 4px;
}
#idCardSheets.seat-level-mode .id-card-head {
  padding: 3px 6px 2px;
  gap: 6px;
  border-bottom-width: 2px;
}
#idCardSheets.seat-level-mode .id-card-head-logo {
  width: 22px;
  height: 22px;
}
#idCardSheets.seat-level-mode .id-card-school {
  font-size: 14px;
  line-height: 1.1;
}
#idCardSheets.seat-level-mode .id-card-head-meta {
  margin-top: 0;
  font-size: 10.5px;
}
#idCardSheets.seat-level-mode .id-card-title-wrap {
  flex-shrink: 0;
  padding: 2px 6px 3px;
  text-align: center;
  background: linear-gradient(90deg, #f6e7c1, #e8c078 42%, #f6e7c1);
  border-bottom: 1px solid rgba(196, 137, 42, .4);
}
#idCardSheets.seat-level-mode .id-card-title {
  padding: 0;
  font-size: 11px;
  letter-spacing: .04em;
  background: transparent;
  border-bottom: 0;
  text-transform: none;
  line-height: 1.2;
}
#idCardSheets.seat-level-mode .id-card-body {
  gap: 6px;
  padding: 2px 6px 2px;
  flex: 1 1 auto;
  min-height: 0;
  align-items: stretch;
}
#idCardSheets.seat-level-mode .id-card-photo-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  gap: 3px;
  flex-shrink: 0;
  width: 46px;
  align-self: stretch;
}
#idCardSheets.seat-level-mode .id-card-photo {
  width: 46px;
  height: 52px;
  align-self: center;
  margin: 0;
}
#idCardSheets.seat-level-mode .id-card-roll-badge {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 14px;
  font-weight: 800;
  line-height: 1;
  color: #0f2f26 !important;
  background: radial-gradient(circle at 35% 30%, #f6e7c1, #e8c078 55%, #c4892a);
  border: 2px solid #0f2f26;
  box-shadow: 0 0 0 1.5px #fff inset;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
#idCardSheets.seat-level-mode .id-card-info {
  display: flex;
  flex-direction: column;
  justify-content: space-evenly;
  height: 100%;
  min-height: 0;
  font-size: 13.5px;
  line-height: 1.18;
}
#idCardSheets.seat-level-mode .id-card-info .name-bn {
  font-size: 16px;
  line-height: 1.12;
  margin-bottom: 1px;
}
#idCardSheets.seat-level-mode .id-card-info .name-en {
  display: none;
}
#idCardSheets.seat-level-mode .id-card-fact {
  font-size: 13px;
  margin-top: 0;
  line-height: 1.18;
  gap: 4px;
}
#idCardSheets.seat-level-mode .id-card-fact .k {
  flex: 0 0 4.2em;
  font-size: 12.5px;
}
#idCardSheets.seat-level-mode .id-card-fact .v {
  font-size: 13px;
}
#idCardSheets.seat-level-mode .id-card-watermark {
  top: 28px;
  bottom: 0;
  left: 48px;
}
#idCardSheets.seat-level-mode .id-card-watermark img {
  width: 74px;
  height: 74px;
}
#idCardSheets.seat-level-mode .id-card-foot {
  display: none;
}
@media print {
  @page {
    size: A4 portrait;
    margin: 10mm;
  }
  .seat-level-page {
    width: auto;
    min-height: 0;
    padding: 0;
    gap: 6mm;
    box-shadow: none;
  }
  .seat-level-sheet {
    height: 44mm;
    min-height: 44mm;
    max-height: 44mm;
    gap: 7mm;
  }
  #idCardSheets.seat-level-mode .id-card {
    width: 86mm;
    height: 44mm;
    min-height: 44mm;
    max-height: 44mm;
    box-shadow: none;
  }
}
CSS;
}

function idCardsUiScripts(): string
{
    return <<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  cls?.addEventListener('change', async () => {
    sections.innerHTML = '<option value="">সকল শাখা</option>';
    if (!cls.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
    if (!Array.isArray(rows)) return;
    rows.forEach((row) => sections.add(new Option(row.section_name_ban, row.id)));
  });

  const clearPrintMode = () => {
    document.body.classList.remove('print-id-fronts', 'print-id-backs');
  };
  window.addEventListener('afterprint', clearPrintMode);
  document.getElementById('idCardPrintFront')?.addEventListener('click', () => {
    document.body.classList.remove('print-id-backs');
    document.body.classList.add('print-id-fronts');
    window.print();
  });
  document.getElementById('idCardPrintBack')?.addEventListener('click', () => {
    document.body.classList.remove('print-id-fronts');
    document.body.classList.add('print-id-backs');
    window.print();
  });
});
JS;
}

function renderStudentIdCardSheets(array $students, array $settings, bool $loadCards, array $options = []): void
{
    $isSeatLevel = (($options['mode'] ?? 'idcard') === 'seatlevel');
    $examName = trim((string) ($options['exam_name'] ?? ''));
    $seatLabelYear = trim((string) ($options['year'] ?? date('Y')));
    if ($seatLabelYear === '') {
        $seatLabelYear = date('Y');
    }
    $seatLabelTitle = 'SEAT LABEL';
    if ($examName !== '') {
        $seatLabelTitle .= ': ' . $examName . '-' . bnDigits($seatLabelYear);
    }
    $logo = !empty($settings['logo']) ? publicSiteUrl((string) $settings['logo']) : '';
    $mpoCode = trim((string) ($settings['mpo_code'] ?? ''));
    $schoolAddress = schoolPlainAddress((string) ($settings['address'] ?? ''));
    $schoolMobile = publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''));
    $schoolEmail = trim((string) ($settings['email'] ?? ''));
    $validStudents = [];
    foreach ($students as $student) {
        if (is_array($student)) {
            $validStudents[] = $student;
        }
    }
    $seatRows = $isSeatLevel ? array_chunk($validStudents, 2) : [];
    $seatPages = $isSeatLevel ? array_chunk($seatRows, 5) : [];
    ?>
            <div id="idCardSheets" class="d-flex flex-column align-items-center gap-3<?= $isSeatLevel ? ' seat-level-mode' : '' ?>">
              <?php if ($isSeatLevel): ?>
              <?php foreach ($seatPages as $pageRows): ?>
                <div class="seat-level-page">
                  <?php foreach ($pageRows as $pair): ?>
                    <div class="seat-level-sheet">
                      <?php foreach ($pair as $student): ?>
                        <?php
                          $mobile = trim((string) ($student['mobile'] ?? ''));
                          $rollValue = h((string) ($student['cls_roll'] ?? ''));
                        ?>
                        <div class="id-card-pair">
                          <div class="id-card-front-wrap">
                            <div class="id-card id-card-front">
                              <?php if ($logo !== ''): ?>
                                <div class="id-card-watermark" aria-hidden="true"><img src="<?= h($logo) ?>" alt=""></div>
                              <?php endif; ?>
                              <div class="id-card-head">
                                <?php if ($logo !== ''): ?>
                                  <img class="id-card-head-logo" src="<?= h($logo) ?>" alt="">
                                <?php endif; ?>
                                <div>
                                  <div class="id-card-school"><?= h((string) ($settings['school_name_bn'] ?? '')) ?></div>
                                  <div class="id-card-head-meta">EIIN: <?= h((string) ($settings['eiin'] ?? '')) ?></div>
                                </div>
                              </div>
                              <div class="id-card-title-wrap">
                                <p class="id-card-title"><?= h($seatLabelTitle) ?></p>
                              </div>
                              <div class="id-card-body">
                                <div class="id-card-photo-col">
                                  <img class="id-card-photo" src="<?= h(studentPhotoUrl($student['student_pic'] ?? '')) ?>" alt="">
                                  <div class="id-card-roll-badge"><?= $rollValue !== '' ? $rollValue : '—' ?></div>
                                </div>
                                <div class="id-card-info">
                                  <div class="name-bn"><?= h((string) ($student['name_ban'] ?? '')) ?></div>
                                  <div class="id-card-fact"><span class="k">শ্রেণি</span><span class="v"><?= h(trim((string) ($student['class_name_ban'] ?? '') . (($student['section_name_ban'] ?? '') !== '' ? ' · ' . $student['section_name_ban'] : ''))) ?></span></div>
                                  <div class="id-card-fact"><span class="k">পিতা</span><span class="v"><?= h((string) ($student['fname_ban'] ?? '')) ?></span></div>
                                  <?php if (trim((string) ($student['mname_ban'] ?? '')) !== ''): ?>
                                  <div class="id-card-fact"><span class="k">মাতা</span><span class="v"><?= h((string) $student['mname_ban']) ?></span></div>
                                  <?php endif; ?>
                                  <div class="id-card-fact"><span class="k">মোবাইল</span><span class="v"><?= $mobile !== '' ? h($mobile) : '—' ?></span></div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
              <?php else: ?>
              <?php foreach ($validStudents as $student): ?>
                <?php
                  $studentId = (int) ($student['id'] ?? 0);
                  $studentName = trim((string) ($student['name_ban'] ?? $student['name_eng'] ?? 'শিক্ষার্থী'));
                  $profileUrl = $studentId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($studentId)) : '';
                  $profileQr = $profileUrl !== '' ? qrCodeSvg($profileUrl, 'id-card-qr-svg', $studentName . ' — প্রোফাইল') : '';
                  $birthDisplay = publicStaffDate($student['birth_date'] ?? null);
                  $backClassLine = studentIdCardBackClassLine($student);
                  $rollValue = h((string) ($student['cls_roll'] ?? '')) . ' · সেশন ' . h((string) ($student['session_year'] ?? ''));
                ?>
                <div class="id-card-pair">
                  <div class="id-card-front-wrap">
                    <p class="id-card-side-label">সামনের দিক</p>
                    <div class="id-card id-card-front">
                      <?php if ($logo !== ''): ?>
                        <div class="id-card-watermark" aria-hidden="true"><img src="<?= h($logo) ?>" alt=""></div>
                      <?php endif; ?>
                      <div class="id-card-head">
                        <?php if ($logo !== ''): ?>
                          <img class="id-card-head-logo" src="<?= h($logo) ?>" alt="">
                        <?php endif; ?>
                        <div>
                          <div class="id-card-school"><?= h((string) ($settings['school_name_bn'] ?? '')) ?></div>
                          <div class="id-card-head-meta">EIIN: <?= h((string) ($settings['eiin'] ?? '')) ?></div>
                        </div>
                      </div>
                      <p class="id-card-title">Student ID Card</p>
                      <div class="id-card-body">
                        <img class="id-card-photo" src="<?= h(studentPhotoUrl($student['student_pic'] ?? '')) ?>" alt="">
                        <div class="id-card-info">
                          <div class="name-bn"><?= h((string) ($student['name_ban'] ?? '')) ?></div>
                          <div class="name-en"><?= h((string) ($student['name_eng'] ?? '')) ?></div>
                          <div class="id-card-fact"><span class="k">শ্রেণি</span><span class="v"><?= h(trim((string) ($student['class_name_ban'] ?? '') . (($student['section_name_ban'] ?? '') !== '' ? ' · ' . $student['section_name_ban'] : ''))) ?></span></div>
                          <div class="id-card-fact"><span class="k">রোল</span><span class="v"><?= $rollValue ?></span></div>
                          <div class="id-card-fact"><span class="k">পিতা</span><span class="v"><?= h((string) ($student['fname_ban'] ?? '')) ?></span></div>
                          <?php if (trim((string) ($student['mname_ban'] ?? '')) !== ''): ?>
                          <div class="id-card-fact"><span class="k">মাতা</span><span class="v"><?= h((string) $student['mname_ban']) ?></span></div>
                          <?php endif; ?>
                          <?php if ($birthDisplay !== ''): ?>
                          <div class="id-card-fact"><span class="k">জন্ম তারিখ</span><span class="v"><?= h($birthDisplay) ?></span></div>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="id-card-foot">
                        <span class="id-card-foot-mobile"><?= trim((string) ($student['mobile'] ?? '')) !== '' ? 'মোবাইল: ' . h((string) $student['mobile']) : '' ?></span>
                        <span class="id-card-sign">প্রধান শিক্ষক</span>
                      </div>
                    </div>
                  </div>
                  <div class="id-card-back-wrap">
                    <p class="id-card-side-label">পেছনের দিক</p>
                    <div class="id-card id-card-back">
                      <?php if ($logo !== ''): ?>
                        <div class="id-card-watermark" aria-hidden="true"><img src="<?= h($logo) ?>" alt=""></div>
                      <?php endif; ?>
                      <div class="id-card-head">
                        <?php if ($logo !== ''): ?>
                          <img class="id-card-head-logo" src="<?= h($logo) ?>" alt="">
                        <?php endif; ?>
                        <div>
                          <div class="id-card-school"><?= h((string) ($settings['school_name_bn'] ?? '')) ?></div>
                          <?php if (trim((string) ($settings['established'] ?? '')) !== ''): ?>
                          <div class="id-card-head-meta">স্থাপিত <?= h((string) $settings['established']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div class="id-card-back-body">
                        <div class="id-card-back-copy">
                          <p class="id-card-back-title">দৃষ্টি আকর্ষণ</p>
                          <p>এই কার্ডটি প্রতিষ্ঠানের নিজস্ব পরিচিতি। ব্যবহারকারী ব্যতীত অন্য কোথাও পাওয়া গেলে অনুগ্রহ করে বিদ্যালয়ে পৌঁছে দিন।</p>
                          <?php if ($schoolAddress !== ''): ?>
                            <p><?= h($schoolAddress) ?></p>
                          <?php endif; ?>
                          <?php if ($schoolMobile !== ''): ?>
                            <p>মোবাইল: <?= h($schoolMobile) ?></p>
                          <?php endif; ?>
                          <?php if ($schoolEmail !== ''): ?>
                            <p><?= h($schoolEmail) ?></p>
                          <?php endif; ?>
                          <?php if ($backClassLine !== ''): ?>
                            <hr class="id-card-back-divider">
                            <p class="id-card-back-class"><?= h($backClassLine) ?></p>
                          <?php endif; ?>
                        </div>
                        <?php if ($profileQr !== ''): ?>
                          <div class="id-card-qr-wrap">
                            <a class="id-card-qr d-block" href="<?= h($profileUrl) ?>" target="_blank" rel="noopener" title="স্ক্যান করে শিক্ষার্থীর তথ্য দেখুন">
                              <?= $profileQr ?>
                            </a>
                            <p class="id-card-qr-name"><?= h($studentName) ?></p>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div class="id-card-foot id-card-foot-dark">
                        <span><?= $mpoCode !== '' ? 'MPO: ' . h($mpoCode) : '' ?></span>
                        <span>EIIN: <?= h((string) ($settings['eiin'] ?? '')) ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php endif; ?>
              <?php if (!$loadCards): ?>
                <div class="text-secondary">শিক্ষাবর্ষ, শ্রেণী ও শাখা বেছে ফিল্টার করুন। তারপর আইডি কার্ড দেখাবে।</div>
              <?php elseif ($validStudents === []): ?>
                <div class="text-secondary">এই ফিল্টারে কোনো শিক্ষার্থী নেই।</div>
              <?php endif; ?>
            </div>
    <?php
}
