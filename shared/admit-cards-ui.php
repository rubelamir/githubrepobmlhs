<?php
declare(strict_types=1);

function admitCardUiCss(): string
{
    return <<<'CSS'
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
.admit-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
#admitCardSheets {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}
.admit-card {
  width: 190mm;
  max-width: 100%;
  box-sizing: border-box;
  padding: 4mm;
  background: #fff;
  color: #0f2f26;
  font-family: "Hind Siliguri", "Noto Sans Bengali", "Segoe UI", sans-serif;
  border: 1.2mm solid #0f2f26;
  box-shadow: 0 10px 24px rgba(15, 47, 38, .12);
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
  page-break-inside: avoid;
  break-inside: avoid;
}
.admit-card-inner {
  border: 0.45mm solid #c4892a;
  padding: 3.5mm 4mm 3mm;
  min-height: 100%;
  box-sizing: border-box;
}
.admit-head {
  display: flex;
  align-items: center;
  gap: 3mm;
  border-bottom: 0.35mm solid #0f2f26;
  padding-bottom: 2.5mm;
  margin-bottom: 2.5mm;
}
.admit-logo {
  width: 22mm;
  height: 22mm;
  object-fit: contain;
  flex: 0 0 auto;
}
.admit-brand { flex: 1; text-align: center; }
.admit-school {
  font-size: 20pt;
  font-weight: 700;
  line-height: 1.25;
  color: #0f2f26;
}
.admit-meta {
  font-size: 11pt;
  color: #334155;
  line-height: 1.4;
}
.admit-title {
  margin: 2mm 0 3mm;
  text-align: center;
  font-size: 13pt;
  font-weight: 700;
  letter-spacing: .4px;
  color: #0f2f26;
  background: #efe4c8;
  border: 0.3mm solid #c4892a;
  padding: 1.2mm 2mm;
}
.admit-exam {
  text-align: center;
  font-size: 10.5pt;
  font-weight: 700;
  margin-bottom: 2.5mm;
}
.admit-body {
  display: flex;
  gap: 3.5mm;
  align-items: flex-start;
}
.admit-info { flex: 1; min-width: 0; }
.admit-fact {
  display: grid;
  grid-template-columns: 28mm 1fr;
  gap: 1mm 2mm;
  font-size: 9.5pt;
  line-height: 1.35;
  margin-bottom: 1mm;
}
.admit-fact .k { color: #475569; font-weight: 600; }
.admit-fact .v { font-weight: 700; color: #0f2f26; }
.admit-photo-wrap {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  gap: 2.5mm;
  flex: 0 0 auto;
}
.admit-photo {
  width: 26mm;
  height: 32mm;
  object-fit: cover;
  border: 0.35mm solid #0f2f26;
  background: #e2e8f0;
  display: block;
}
.admit-qr {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22mm;
  height: 32mm;
}
.admit-qr svg,
.admit-qr img {
  width: 22mm;
  height: 22mm;
  display: block;
}
.admit-papers {
  width: 100%;
  border-collapse: collapse;
  margin-top: 3mm;
  font-size: 8.5pt;
}
.admit-papers th,
.admit-papers td {
  border: 0.25mm solid #0f2f26;
  padding: 1.1mm 1.4mm;
  vertical-align: middle;
}
.admit-papers th {
  background: #0f2f26;
  color: #fff;
  font-weight: 700;
  text-align: center;
}
.admit-papers td:nth-child(1),
.admit-papers td:nth-child(3),
.admit-papers td:nth-child(4),
.admit-papers td:nth-child(5) { text-align: center; }
.admit-note {
  margin: 2.5mm 0 0;
  font-size: 8pt;
  color: #334155;
  line-height: 1.4;
}
.admit-sign {
  display: flex;
  justify-content: space-between;
  gap: 4mm;
  margin-top: 7mm;
  font-size: 9pt;
  text-align: center;
}
.admit-sign div { min-width: 38mm; }
.admit-sign strong {
  display: block;
  border-top: 0.3mm solid #0f2f26;
  padding-top: 1mm;
  margin-top: 8mm;
  font-weight: 700;
}
@media print {
  @page {
    size: A4 portrait;
    margin-top: 20mm;
    margin-right: 8mm;
    margin-bottom: 8mm;
    margin-left: 8mm;
  }
  .admit-actions, .no-print { display: none !important; }
  html, body {
    background: #fff !important;
    margin: 0 !important;
    padding: 0 !important;
    height: auto !important;
    min-height: 0 !important;
  }
  .app-sidebar, .app-header, .app-footer { display: none !important; }
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
    background: #fff !important;
  }
  #admitCardSheets {
    display: block !important;
    align-items: stretch;
    justify-content: flex-start;
    gap: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  .admit-card {
    width: 100% !important;
    max-width: 100% !important;
    box-shadow: none !important;
    margin: 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
    page-break-after: auto !important;
    break-after: auto !important;
  }
  #admitCardSheets .admit-card:not(:last-child) {
    page-break-after: always !important;
    break-after: page !important;
  }
}
CSS;
}

function admitCardUiScripts(): string
{
    return <<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  const cls = document.getElementById('filterClass');
  const sections = document.getElementById('filterSection');
  cls?.addEventListener('change', async () => {
    sections.innerHTML = '<option value="0">সকল শাখা</option>';
    if (!cls.value) return;
    const rows = await (await fetch('academic-api.php?type=sections&class_id=' + encodeURIComponent(cls.value))).json();
    if (!Array.isArray(rows)) return;
    rows.forEach((row) => sections.add(new Option(row.section_name_ban, row.id)));
  });
  document.getElementById('admitCardPrint')?.addEventListener('click', () => window.print());
});
JS;
}

/**
 * @param list<array<string, mixed>> $students
 * @param list<array<string, mixed>> $papers
 * @param array<string, mixed> $settings
 */
function renderAdmitCardSheets(
    array $students,
    array $settings,
    string $examName,
    string $sessionYear,
    array $papers,
    bool $loaded
): void {
    if (!$loaded) {
        echo '<div class="card stat-card"><div class="card-body text-secondary">ফিল্টার করে অ্যাডমিট কার্ড তৈরি করুন।</div></div>';

        return;
    }
    if ($students === []) {
        echo '<div class="card stat-card"><div class="card-body text-secondary">এই ফিল্টারে কোনো শিক্ষার্থী নেই।</div></div>';

        return;
    }

    $logo = !empty($settings['logo']) ? publicSiteUrl((string) $settings['logo']) : '';
    $schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
    $address = schoolPlainAddress((string) ($settings['address'] ?? ''));
    $eiin = trim((string) ($settings['eiin'] ?? ''));
    $mobile = publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''));
    $sessionBn = $sessionYear !== '' ? bnDigits($sessionYear) : '';

    $papersBySection = [];
    foreach ($papers as $paper) {
        if (!is_array($paper)) {
            continue;
        }
        $sec = (int) ($paper['section_id'] ?? 0);
        $papersBySection[$sec][] = $paper;
    }
    ?>
            <div id="admitCardSheets">
              <?php foreach ($students as $student): ?>
                <?php
                  if (!is_array($student)) {
                      continue;
                  }
                  $studentId = (int) ($student['id'] ?? 0);
                  $studentName = trim((string) ($student['name_ban'] ?? $student['name_eng'] ?? 'শিক্ষার্থী'));
                  $profileUrl = $studentId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($studentId)) : '';
                  $profileQr = $profileUrl !== '' ? qrCodeSvg($profileUrl, 'admit-qr-svg', $studentName . ' — প্রোফাইল') : '';
                  $classLine = trim((string) ($student['class_name_ban'] ?? ''));
                  $sectionLine = trim((string) ($student['section_name_ban'] ?? ''));
                  $studentSectionId = (int) ($student['sections'] ?? 0);
                  $studentPapers = [];
                  foreach ($papersBySection[0] ?? [] as $paper) {
                      $studentPapers[] = $paper;
                  }
                  if ($studentSectionId > 0) {
                      foreach ($papersBySection[$studentSectionId] ?? [] as $paper) {
                          $studentPapers[] = $paper;
                      }
                  }
                  usort(
                      $studentPapers,
                      static function (array $a, array $b): int {
                          $dateCmp = strcmp((string) ($a['exam_date'] ?? ''), (string) ($b['exam_date'] ?? ''));
                          if ($dateCmp !== 0) {
                              return $dateCmp;
                          }

                          return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
                      }
                  );
                  $seen = [];
                  $uniquePapers = [];
                  foreach ($studentPapers as $paper) {
                      $key = (string) ($paper['exam_date'] ?? '') . '|' . (string) ($paper['subject_name'] ?? '');
                      if (isset($seen[$key])) {
                          continue;
                      }
                      $seen[$key] = true;
                      $uniquePapers[] = $paper;
                  }
                  $studentPapers = $uniquePapers;
                ?>
                <article class="admit-card">
                  <div class="admit-card-inner">
                    <div class="admit-head">
                      <?php if ($logo !== ''): ?>
                        <img class="admit-logo" src="<?= h($logo) ?>" alt="">
                      <?php endif; ?>
                      <div class="admit-brand">
                        <div class="admit-school"><?= h($schoolName) ?></div>
                        <?php if ($address !== ''): ?>
                          <div class="admit-meta"><?= h($address) ?></div>
                        <?php endif; ?>
                        <div class="admit-meta">
                          <?php if ($eiin !== ''): ?>EIIN: <?= h($eiin) ?><?php endif; ?>
                          <?php if ($eiin !== '' && $mobile !== ''): ?> · <?php endif; ?>
                          <?php if ($mobile !== ''): ?>মোবাইল: <?= h($mobile) ?><?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <div class="admit-title">প্রবেশপত্র / ADMIT CARD</div>
                    <div class="admit-exam">
                      <?= h($examName !== '' ? $examName : 'পরীক্ষা') ?>
                      <?php if ($sessionBn !== ''): ?> · শিক্ষাবর্ষ <?= h($sessionBn) ?><?php endif; ?>
                    </div>

                    <div class="admit-body">
                      <div class="admit-info">
                        <div class="admit-fact"><span class="k">নাম</span><span class="v"><?= h((string) ($student['name_ban'] ?? '')) ?></span></div>
                        <?php if (trim((string) ($student['name_eng'] ?? '')) !== ''): ?>
                          <div class="admit-fact"><span class="k">Name</span><span class="v"><?= h((string) $student['name_eng']) ?></span></div>
                        <?php endif; ?>
                        <div class="admit-fact"><span class="k">পিতা</span><span class="v"><?= h((string) ($student['fname_ban'] ?? '')) ?></span></div>
                        <div class="admit-fact"><span class="k">মাতা</span><span class="v"><?= h((string) ($student['mname_ban'] ?? '')) ?></span></div>
                        <div class="admit-fact">
                          <span class="k">শ্রেণী</span>
                          <span class="v"><?= h($classLine) ?><?= $sectionLine !== '' ? ' · ' . h($sectionLine) : '' ?></span>
                        </div>
                        <div class="admit-fact">
                          <span class="k">রোল</span>
                          <span class="v"><?= h(bnDigits((string) ($student['cls_roll'] ?? ''))) ?></span>
                        </div>
                      </div>
                      <div class="admit-photo-wrap">
                        <?php if ($profileQr !== ''): ?>
                          <div class="admit-qr"><?= $profileQr ?></div>
                        <?php endif; ?>
                        <img class="admit-photo" src="<?= h(studentPhotoUrl($student['student_pic'] ?? '')) ?>" alt="">
                      </div>
                    </div>

                    <?php if ($studentPapers !== []): ?>
                      <table class="admit-papers">
                        <thead>
                          <tr>
                            <th style="width:18%">তারিখ</th>
                            <th>বিষয়</th>
                            <th style="width:18%">সময়</th>
                            <th style="width:12%">পূর্ণমান</th>
                            <th style="width:14%">কক্ষ</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($studentPapers as $paper): ?>
                            <tr>
                              <td><?= h(examRoutineDateBn((string) ($paper['exam_date'] ?? ''))) ?></td>
                              <td><?= h((string) ($paper['subject_name'] ?? '')) ?></td>
                              <td><?= h(examRoutineTimeLabel((string) ($paper['start_time'] ?? ''), (string) ($paper['end_time'] ?? ''))) ?: '—' ?></td>
                              <td><?= h((string) ($paper['full_marks'] ?? '')) ?: '—' ?></td>
                              <td><?= h((string) ($paper['room'] ?? '')) ?: '—' ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <p class="admit-note">এই শ্রেণী/শাখার পরীক্ষার রুটিন এখনো যোগ করা হয়নি। Exam routine থেকে বিষয় যোগ করুন।</p>
                    <?php endif; ?>

                    <p class="admit-note">নির্দেশনা: পরীক্ষার হলে প্রবেশপত্র ও বিদ্যালয়ের পরিচয়পত্র সঙ্গে রাখতে হবে। পরীক্ষা চলাকালীন কোনো প্রকার অসদুপায় গ্রহণযোগ্য নয়।</p>

                    <div class="admit-sign">
                      <div><strong>শ্রেণী শিক্ষক</strong></div>
                      <div><strong>প্রধান শিক্ষক</strong></div>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
    <?php
}
