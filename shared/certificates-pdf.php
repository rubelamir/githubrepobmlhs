<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';
require_once __DIR__ . '/certificate-border.php';
if (!function_exists('publicStudentProfileUrl')) {
    require_once dirname(__DIR__) . '/bmlhs/includes/site.php';
}

function outputCertificatesPdf(
    array $students,
    array $settings,
    string $type,
    string $typeLabel,
    string $issueDate,
    string $filename,
    string $lang = 'bn',
    ?string $savePath = null
): void {
    if ($students === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো শিক্ষার্থী নেই।');
    }

    $lang = certificateLanguage($lang);
    $heading = certificateSchoolHeading($settings, $lang);
    $copy = certificateSheetCopy($lang);
    $mpdf = createBanglaMpdf(
        $heading['name'] !== '' ? $heading['name'] : 'Certificate',
        $heading['name'] !== '' ? $heading['name'] : 'School Admin'
    );
    // Script auto-wrapping drops artificial italic on Bangla emphasis.
    $mpdf->autoScriptToLang = false;
    $outer = certificateOuterMarginMm();
    $mpdf->SetMargins($outer, $outer, $outer);
    $mpdf->bMargin = $outer;
    $mpdf->SetAutoPageBreak(false, $outer);

    $context = [
        'schoolName' => $heading['name'],
        'address' => $heading['address'],
        'eiin' => trim((string) ($settings['eiin'] ?? '')),
        'mobile' => trim((string) ($settings['mobile'] ?? '')),
        'logo' => certificatePdfImageSrc(mpdfSchoolLogoPath($settings)),
        'typeLabel' => $typeLabel,
        'issueDate' => $issueDate,
        'lang' => $lang,
        'copy' => $copy,
    ];

    $mpdf->WriteHTML(certificatePdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
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
            certificatePdfSheetHtml($student, $type, $settings, $context),
            \Mpdf\HTMLParserMode::HTML_BODY
        );
        certificateBorderPaintPdfFrames($mpdf);
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'certificates.pdf';
    if ($savePath !== null) {
        $mpdf->Output($savePath, \Mpdf\Output\Destination::FILE);
        return;
    }

    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

function certificatePdfImageSrc(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }

    return str_replace('\\', '/', $path);
}

function certificatePdfCss(): string
{
    $borderCss = certificateBorderCss('pdf');

    return $borderCss . <<<'CSS'
body {
  font-family: hindsiliguri, sans-serif;
  color: #000;
}
.head {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 4mm;
}
.title-row {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 6mm;
}
.title-row td {
  vertical-align: middle;
  padding: 0;
  color: #000;
}
.head td {
  vertical-align: top;
  padding: 0;
  color: #000;
}
.logo-cell { width: 24mm; text-align: center; vertical-align: top; }
.identity-cell { width: 22mm; text-align: right; vertical-align: top; padding-right: 0; }
.school-cell { text-align: center; padding: 0 1mm; vertical-align: top; }
.logo { height: 24mm; width: auto; max-width: 24mm; }
.qr {
  width: 26mm;
  height: 26mm;
  margin-top: 0;
  border: 0.3mm solid #1e3a5f;
}
.photo {
  width: 24mm;
  height: 30mm;
  object-fit: cover;
  border: 0.3mm solid #1e3a5f;
  margin-right: -3mm;
}
.school-name {
  font-size: 20pt;
  font-weight: bold;
  line-height: 1.15;
  margin: 0 0 1.6mm;
  color: #000;
  white-space: nowrap;
}
.school-meta {
  font-size: 11pt;
  line-height: 1.45;
  font-weight: normal;
  color: #000;
}
.badge-wrap { text-align: center; margin: 0; }
table.badge {
  border-collapse: collapse;
  margin: 0 auto;
}
table.badge td {
  border: 0.45mm solid #000;
  padding: 2mm 8mm 2.4mm;
  font-size: 14pt;
  font-weight: bold;
  line-height: 1.2;
  text-align: center;
  white-space: nowrap;
  color: #000;
}
.issue-date {
  text-align: right;
  font-size: 11pt;
  font-weight: normal;
  margin: 0 0 8mm;
  color: #000;
}
.body {
  font-size: 14pt;
  font-weight: normal;
  line-height: 1.95;
  text-align: justify;
  margin: 0 0 6mm;
  color: #000;
}
.body .cert-em,
.cert-em {
  font-family: hindsiliguribold;
  font-weight: normal;
  font-style: italic;
}
.note {
  font-size: 12pt;
  font-weight: normal;
  text-align: justify;
  margin: 0;
  color: #000;
}
.signs {
  width: 100%;
  margin-top: 12mm;
  border-collapse: collapse;
}
.signs td {
  width: 50%;
  vertical-align: top;
  font-size: 12pt;
  font-weight: bold;
  color: #000;
  padding: 0;
}
.sign-left { text-align: left; }
.sign-right { text-align: right; }
.sign-head-wrap {
  width: auto;
  margin-left: auto;
  border-collapse: collapse;
}
.sign-head-wrap td {
  text-align: center;
  font-size: 12pt;
  font-weight: bold;
  color: #000;
  padding: 0;
  width: auto;
  white-space: nowrap;
}
.sign-school-name {
  display: block;
  white-space: nowrap;
}
CSS;
}

function certificatePdfSheetHtml(array $student, string $type, array $settings, array $context): string
{
    $lang = certificateLanguage((string) ($context['lang'] ?? 'bn'));
    $copy = is_array($context['copy'] ?? null) ? $context['copy'] : certificateSheetCopy($lang);
    $logo = (string) ($context['logo'] ?? '');
    $photo = certificatePdfImageSrc(mpdfStudentPhotoPath($student['student_pic'] ?? ''));
    $body = certificateDocumentBody($student, $type, $settings, $lang);
    $logoHtml = $logo !== ''
        ? '<img class="logo" src="' . h($logo) . '" alt="">'
        : '';
    $photoHtml = $photo !== ''
        ? '<img class="photo" src="' . h($photo) . '" alt="">'
        : '';
    $studentId = (int) ($student['id'] ?? 0);
    $profileUrl = $studentId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($studentId)) : '';
    $qr = $profileUrl !== '' ? banglaQrPngDataUri($profileUrl) : '';
    $qrHtml = $qr !== ''
        ? '<img class="qr" src="' . h($qr) . '" width="74" height="74" alt="">'
        : '';
    $dateLabel = (string) ($copy['date'] ?? 'তারিখ');
    $mobileLabel = (string) ($copy['mobile'] ?? 'মোবাইল');
    $headLabel = (string) ($copy['headTeacher'] ?? 'প্রধান শিক্ষক');
    $note = (string) ($copy['note'] ?? '');
    $schoolName = (string) ($context['schoolName'] ?? '');
    $schoolNameDisplay = $lang === 'en' ? mb_strtoupper($schoolName, 'UTF-8') : $schoolName;

    $bodyHtml = '';
    foreach (preg_split("/\n\s*\n/", trim($body)) ?: [$body] as $para) {
        $para = trim((string) $para);
        if ($para === '') {
            continue;
        }
        $bodyHtml .= '<p class="body">'
            . str_replace(["\x1E", "\x1F"], ['<span class="cert-em">', '</span>'], h($para))
            . '</p>';
    }
    $noteHtml = certificateSkipGenericNote($type, $lang)
        ? ''
        : '<p class="note">' . h($note) . '</p>';

    $schoolNameHtml = '<table class="school-name-wrap" width="100%" style="border-collapse:collapse;"><tr>'
        . '<td class="school-name" align="center" nowrap="nowrap" style="white-space:nowrap;">'
        . h($schoolNameDisplay)
        . '</td></tr></table>';

    return certificateBorderOpenHtml('pdf')
        . '<table class="head"><tr>'
        . '<td class="logo-cell">' . $logoHtml . '</td>'
        . '<td class="school-cell">'
        . $schoolNameHtml
        . '<div class="school-meta">' . h((string) ($context['address'] ?? '')) . '</div>'
        . '<div class="school-meta">EIIN: ' . h((string) ($context['eiin'] ?? ''))
        . ' · ' . h($mobileLabel) . ': ' . h((string) ($context['mobile'] ?? '')) . '</div>'
        . '</td>'
        . '<td class="identity-cell">' . $photoHtml . '</td>'
        . '</tr></table>'
        . '<table class="title-row"><tr>'
        . '<td class="logo-cell" valign="middle">' . $qrHtml . '</td>'
        . '<td class="badge-wrap" valign="middle" align="center">'
        . '<table class="badge" align="center"><tr><td nowrap="nowrap">' . h((string) ($context['typeLabel'] ?? '')) . '</td></tr></table>'
        . '</td>'
        . '<td class="identity-cell" valign="middle"></td>'
        . '</tr></table>'
        . '<div class="issue-date">' . h($dateLabel) . ': ' . h((string) ($context['issueDate'] ?? '')) . '</div>'
        . $bodyHtml
        . $noteHtml
        . '<table class="signs"><tr>'
        . '<td class="sign-left">' . h($dateLabel) . ': ' . h((string) ($context['issueDate'] ?? '')) . '</td>'
        . '<td class="sign-right">'
        . '<table class="sign-head-wrap" align="right"><tr><td nowrap="nowrap" style="white-space:nowrap;text-align:center;">'
        . h($headLabel) . '<br><span class="sign-school-name" style="white-space:nowrap;">' . h($schoolNameDisplay) . '</span>'
        . '</td></tr></table>'
        . '</td>'
        . '</tr></table>'
        . certificateBorderCloseHtml('pdf');
}
