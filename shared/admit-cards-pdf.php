<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';
if (!function_exists('publicStudentProfileUrl')) {
    require_once dirname(__DIR__) . '/bmlhs/includes/site.php';
}
if (!function_exists('examRoutineDateBn')) {
    require_once __DIR__ . '/academics.php';
}
if (!function_exists('banglaPdfImageSrc')) {
    function banglaPdfImageSrc(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }

        return str_replace('\\', '/', $path);
    }
}

/**
 * @param list<array<string, mixed>> $students
 * @param list<array<string, mixed>> $papers
 * @param array<string, mixed> $settings
 */
function outputAdmitCardsPdf(
    array $students,
    array $settings,
    string $examName,
    string $sessionYear,
    array $papers,
    string $filename
): void {
    if ($students === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো শিক্ষার্থী নেই।');
    }

    $mpdf = createBanglaMpdf(
        (string) ($settings['school_name_bn'] ?? 'প্রবেশপত্র'),
        (string) ($settings['school_name_bn'] ?? 'School Admin')
    );
    // Match screen print: top 20mm, other sides 8mm.
    $mpdf->SetMargins(8, 8, 20);
    $mpdf->bMargin = 8;
    $mpdf->SetAutoPageBreak(false, 8);

    $context = [
        'schoolName' => trim((string) ($settings['school_name_bn'] ?? '')),
        'address' => schoolPlainAddress((string) ($settings['address'] ?? '')),
        'eiin' => trim((string) ($settings['eiin'] ?? '')),
        'mobile' => publicStaffMobileDisplay((string) ($settings['mobile'] ?? '')),
        'logo' => banglaPdfImageSrc(mpdfSchoolLogoPath($settings)),
        'examName' => $examName !== '' ? $examName : 'পরীক্ষা',
        'sessionBn' => $sessionYear !== '' ? bnDigits($sessionYear) : '',
        'papersBySection' => admitCardPapersBySection($papers),
    ];

    $mpdf->WriteHTML(admitCardPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $first = true;
    foreach ($students as $student) {
        if (!is_array($student)) {
            continue;
        }
        if ($first) {
            $first = false;
        } else {
            $mpdf->AddPage();
        }
        $mpdf->WriteHTML(
            admitCardPdfSheetHtml($student, $context),
            \Mpdf\HTMLParserMode::HTML_BODY
        );
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'admit-cards.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

/**
 * @param list<array<string, mixed>> $papers
 * @return array<int, list<array<string, mixed>>>
 */
function admitCardPapersBySection(array $papers): array
{
    $bySection = [];
    foreach ($papers as $paper) {
        if (!is_array($paper)) {
            continue;
        }
        $sec = (int) ($paper['section_id'] ?? 0);
        $bySection[$sec][] = $paper;
    }

    return $bySection;
}

/**
 * @param array<int, list<array<string, mixed>>> $papersBySection
 * @return list<array<string, mixed>>
 */
function admitCardPapersForStudent(array $student, array $papersBySection): array
{
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
    $unique = [];
    foreach ($studentPapers as $paper) {
        $key = (string) ($paper['exam_date'] ?? '') . '|' . (string) ($paper['subject_name'] ?? '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $paper;
    }

    return $unique;
}

function admitCardPdfCss(): string
{
    return <<<'CSS'
body {
  font-family: hindsiliguri, sans-serif;
  color: #0f2f26;
}
.admit-card {
  width: 100%;
  border: 1.2mm solid #0f2f26;
  padding: 4mm;
  box-sizing: border-box;
  background: #fff;
}
.admit-card-inner {
  border: 0.45mm solid #c4892a;
  padding: 3.5mm 4mm 3mm;
  box-sizing: border-box;
}
.admit-head {
  width: 100%;
  border-collapse: collapse;
  border-bottom: 0.35mm solid #0f2f26;
  margin-bottom: 2.5mm;
  padding-bottom: 2.5mm;
}
.admit-head td { vertical-align: middle; padding: 0 0 2.5mm; }
.admit-logo-cell { width: 24mm; text-align: left; }
.admit-logo { width: 22mm; height: 22mm; }
.admit-brand { text-align: center; }
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
  letter-spacing: 0.4px;
  color: #0f2f26;
  background-color: #efe4c8;
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
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 2mm;
}
.admit-body td { vertical-align: top; padding: 0; }
.admit-info { width: auto; }
.admit-fact {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1mm;
  font-size: 9.5pt;
}
.admit-fact .k {
  width: 28mm;
  color: #475569;
  font-weight: 600;
  vertical-align: top;
  padding: 0.4mm 2mm 0.4mm 0;
}
.admit-fact .v {
  font-weight: 700;
  color: #0f2f26;
  vertical-align: top;
  padding: 0.4mm 0;
}
.admit-photo-wrap {
  width: 32mm;
  text-align: right;
}
.admit-qr {
  width: 18mm;
  height: 18mm;
  margin: 0 0 2mm auto;
}
.admit-photo {
  width: 28mm;
  height: 34mm;
  border: 0.35mm solid #0f2f26;
  object-fit: cover;
}
.admit-papers {
  width: 100%;
  border-collapse: collapse;
  margin-top: 3mm;
  font-size: 9pt;
}
.admit-papers th,
.admit-papers td {
  border: 0.25mm solid #0f2f26;
  padding: 1.1mm 1.4mm;
  vertical-align: middle;
}
.admit-papers th {
  background-color: #efe4c8;
  font-weight: 700;
  text-align: center;
}
.admit-papers td.c { text-align: center; }
.admit-note {
  margin: 2.5mm 0 0;
  font-size: 8.5pt;
  line-height: 1.4;
  color: #334155;
}
.admit-sign {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10mm;
  font-size: 10pt;
  font-weight: 700;
}
.admit-sign td {
  width: 50%;
  text-align: center;
  vertical-align: top;
  padding-top: 8mm;
  border-top: 0.3mm solid #0f2f26;
}
CSS;
}

/**
 * @param array<string, mixed> $student
 * @param array<string, mixed> $context
 */
function admitCardPdfSheetHtml(array $student, array $context): string
{
    $logo = (string) ($context['logo'] ?? '');
    $schoolName = (string) ($context['schoolName'] ?? '');
    $address = (string) ($context['address'] ?? '');
    $eiin = (string) ($context['eiin'] ?? '');
    $mobile = (string) ($context['mobile'] ?? '');
    $examName = (string) ($context['examName'] ?? 'পরীক্ষা');
    $sessionBn = (string) ($context['sessionBn'] ?? '');
    $papersBySection = is_array($context['papersBySection'] ?? null) ? $context['papersBySection'] : [];
    $studentPapers = admitCardPapersForStudent($student, $papersBySection);

    $classLine = trim((string) ($student['class_name_ban'] ?? ''));
    $sectionLine = trim((string) ($student['section_name_ban'] ?? ''));
    $nameBan = trim((string) ($student['name_ban'] ?? ''));
    $nameEng = trim((string) ($student['name_eng'] ?? ''));
    $photo = banglaPdfImageSrc(mpdfStudentPhotoPath($student['student_pic'] ?? ''));

    $studentId = (int) ($student['id'] ?? 0);
    $profileUrl = $studentId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($studentId)) : '';
    $qr = $profileUrl !== '' ? banglaQrPngDataUri($profileUrl) : '';

    $logoHtml = $logo !== ''
        ? '<img class="admit-logo" src="' . h($logo) . '" alt="">'
        : '';
    $photoHtml = $photo !== ''
        ? '<img class="admit-photo" src="' . h($photo) . '" alt="">'
        : '';
    $qrHtml = $qr !== ''
        ? '<img class="admit-qr" src="' . h($qr) . '" alt="">'
        : '';

    $meta = '';
    if ($eiin !== '') {
        $meta .= 'EIIN: ' . h($eiin);
    }
    if ($eiin !== '' && $mobile !== '') {
        $meta .= ' · ';
    }
    if ($mobile !== '') {
        $meta .= 'মোবাইল: ' . h($mobile);
    }

    $facts = '';
    $factRows = [
        ['নাম', $nameBan],
    ];
    if ($nameEng !== '') {
        $factRows[] = ['Name', $nameEng];
    }
    $factRows[] = ['পিতা', trim((string) ($student['fname_ban'] ?? ''))];
    $factRows[] = ['মাতা', trim((string) ($student['mname_ban'] ?? ''))];
    $factRows[] = ['শ্রেণী', $classLine . ($sectionLine !== '' ? ' · ' . $sectionLine : '')];
    $factRows[] = ['রোল', bnDigits((string) ($student['cls_roll'] ?? ''))];
    foreach ($factRows as [$k, $v]) {
        $facts .= '<table class="admit-fact"><tr>'
            . '<td class="k">' . h($k) . '</td>'
            . '<td class="v">' . h($v) . '</td>'
            . '</tr></table>';
    }

    $papersHtml = '';
    if ($studentPapers !== []) {
        $papersHtml .= '<table class="admit-papers"><thead><tr>'
            . '<th style="width:18%">তারিখ</th><th>বিষয়</th><th style="width:18%">সময়</th>'
            . '<th style="width:12%">পূর্ণমান</th><th style="width:14%">কক্ষ</th>'
            . '</tr></thead><tbody>';
        foreach ($studentPapers as $paper) {
            $time = examRoutineTimeLabel(
                (string) ($paper['start_time'] ?? ''),
                (string) ($paper['end_time'] ?? '')
            );
            $papersHtml .= '<tr>'
                . '<td class="c">' . h(examRoutineDateBn((string) ($paper['exam_date'] ?? ''))) . '</td>'
                . '<td>' . h((string) ($paper['subject_name'] ?? '')) . '</td>'
                . '<td class="c">' . h($time !== '' ? $time : '—') . '</td>'
                . '<td class="c">' . h((string) (($paper['full_marks'] ?? '') !== '' ? $paper['full_marks'] : '—')) . '</td>'
                . '<td class="c">' . h((string) (($paper['room'] ?? '') !== '' ? $paper['room'] : '—')) . '</td>'
                . '</tr>';
        }
        $papersHtml .= '</tbody></table>';
    } else {
        $papersHtml = '<p class="admit-note">এই শ্রেণী/শাখার পরীক্ষার রুটিন এখনো যোগ করা হয়নি। Exam routine থেকে বিষয় যোগ করুন।</p>';
    }

    return '<div class="admit-card"><div class="admit-card-inner">'
        . '<table class="admit-head"><tr>'
        . '<td class="admit-logo-cell">' . $logoHtml . '</td>'
        . '<td class="admit-brand">'
        . '<div class="admit-school">' . h($schoolName) . '</div>'
        . ($address !== '' ? '<div class="admit-meta">' . h($address) . '</div>' : '')
        . ($meta !== '' ? '<div class="admit-meta">' . $meta . '</div>' : '')
        . '</td></tr></table>'
        . '<div class="admit-title">প্রবেশপত্র / ADMIT CARD</div>'
        . '<div class="admit-exam">' . h($examName)
        . ($sessionBn !== '' ? ' · শিক্ষাবর্ষ ' . h($sessionBn) : '')
        . '</div>'
        . '<table class="admit-body"><tr>'
        . '<td class="admit-info">' . $facts . '</td>'
        . '<td class="admit-photo-wrap">' . $qrHtml . $photoHtml . '</td>'
        . '</tr></table>'
        . $papersHtml
        . '<p class="admit-note">নির্দেশনা: পরীক্ষার হলে প্রবেশপত্র ও বিদ্যালয়ের পরিচয়পত্র সঙ্গে রাখতে হবে। পরীক্ষা চলাকালীন কোনো প্রকার অসদুপায় গ্রহণযোগ্য নয়।</p>'
        . '<table class="admit-sign"><tr>'
        . '<td>শ্রেণী শিক্ষক</td><td>প্রধান শিক্ষক</td>'
        . '</tr></table>'
        . '</div></div>';
}
