<?php
declare(strict_types=1);

/**
 * Shared Testimonial certificate sheet for screen/print and mPDF.
 * Screen uses CSS rings; PDF paints matching rings and the same content stage.
 */

if (!function_exists('banglaQrPngDataUri')) {
    require_once __DIR__ . '/mpdf-bangla.php';
}
if (!function_exists('certificateOuterMarginMm')) {
    require_once __DIR__ . '/certificate-border.php';
}

/** Content insets inside the inner ring (matches .cert-ring-inner padding). */
function certificateTestimonialContentPad(): array
{
    $m = certificateInnerMarginMm();

    return ['top' => $m, 'right' => $m, 'bottom' => $m, 'left' => $m];
}

/**
 * Distance from sheet content edge (after 8mm page/sheet pad) to text area.
 * = outer ring + gold ring + double ring + inner padding.
 */
function certificateTestimonialContentInset(): array
{
    // Outer: 0.7 border + 1.4 pad = 2.1
    // Gold:  0.45 border + 1.4 pad = 1.85
    // Double ≈ 1.2 visual thickness
    $rings = 2.1 + 1.85 + 1.2;
    $pad = certificateTestimonialContentPad();

    return [
        'top' => $rings + $pad['top'],
        'right' => $rings + $pad['right'],
        'bottom' => $rings + $pad['bottom'],
        'left' => $rings + $pad['left'],
    ];
}

function certificateTestimonialSheetCss(string $mode = 'html'): string
{
    $fontFamily = $mode === 'pdf'
        ? 'hindsiliguri, sans-serif'
        : '"Hind Siliguri", "Noto Sans Bengali", "Segoe UI", sans-serif';
    $outer = certificateOuterMarginMm();

    if ($mode === 'pdf') {
        $inset = certificateTestimonialContentInset();
        // Sheet chrome is page margin (outer). Content clears painted rings + inner pad.
        return <<<CSS
body {
  font-family: {$fontFamily};
  font-weight: normal;
  color: #000;
}
.certificate-sheet {
  width: 100%;
  margin: 0;
  padding: {$inset['top']}mm {$inset['right']}mm {$inset['bottom']}mm {$inset['left']}mm;
  box-sizing: border-box;
  background: #fff;
  color: #000;
  font-family: {$fontFamily};
  font-weight: normal;
}
.certificate-stage {
  width: 100%;
  height: 250mm;
  border-collapse: collapse;
}
.certificate-main,
.certificate-foot {
  padding: 0;
  color: #000;
}
.certificate-main { vertical-align: top; }
.certificate-foot { vertical-align: bottom; height: 22mm; }
CSS
        . certificateTestimonialContentCss();
    }

    return <<<CSS
.certificate-sheet {
  width: 210mm;
  max-width: 210mm;
  min-height: 297mm;
  height: 297mm;
  margin: 0 auto 1.25rem;
  padding: {$outer}mm;
  box-sizing: border-box;
  background: #fff !important;
  color: #000 !important;
  font-family: {$fontFamily};
  font-weight: normal;
  border: 0;
  box-shadow: 0 12px 32px rgba(15, 23, 42, .12);
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
  page-break-inside: avoid;
  break-inside: avoid;
}
CSS
    . certificateBorderCss('html') . <<<CSS
.certificate-sheet, .certificate-sheet * { color: #000 !important; }
CSS
    . certificateTestimonialContentCss();
}

function certificateTestimonialContentCss(): string
{
    return <<<CSS
.certificate-head {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 3mm;
}
.certificate-head td { padding: 0; vertical-align: top; }
.certificate-brand-side {
  width: 26mm;
  text-align: center;
  vertical-align: top;
}
.certificate-logo {
  height: 24mm;
  width: auto;
  max-width: 26mm;
}
.certificate-title-row {
  width: 100%;
  border-collapse: collapse;
  margin: 0 0 6mm;
}
.certificate-title-row td {
  padding: 0;
  vertical-align: middle;
}
.certificate-qr {
  width: 26mm;
  height: 26mm;
  padding: 1.5mm;
  border: 1px solid #1e3a5f;
  background: #fff;
  box-sizing: border-box;
  display: block;
  text-align: center;
}
.certificate-qr img {
  width: 23mm;
  height: 23mm;
  display: block;
  border: 0;
  margin: 0 auto;
}
.certificate-id-wrap {
  width: 26mm;
  text-align: right;
  vertical-align: top;
  padding-right: 0;
}
.certificate-brand {
  text-align: center;
  vertical-align: top;
}
.certificate-brand .h3 {
  font-size: 20pt;
  font-weight: 700;
  margin: 0 0 2mm;
  line-height: 1.25;
  white-space: nowrap;
}
.certificate-meta {
  font-size: 10pt;
  line-height: 1.35;
  font-weight: normal;
}
.certificate-photo {
  width: 26mm;
  height: 32mm;
  border: 1px solid #1e3a5f;
}
.certificate-type {
  text-align: center;
  vertical-align: middle;
}
.certificate-type span {
  display: inline-block;
  border: 1.5px solid #1e3a5f;
  padding: 2mm 8mm;
  font-size: 14pt;
  font-weight: 700;
  letter-spacing: 0.04em;
}
.certificate-issued {
  text-align: right;
  font-size: 11pt;
  font-weight: normal;
  margin-bottom: 4mm;
}
.certificate-copy {
  font-size: 12pt;
  line-height: 1.65;
  text-align: justify;
  font-weight: normal;
}
.certificate-body {
  margin: 0 0 3.5mm;
  font-size: 12pt;
  font-weight: normal;
  line-height: 1.65;
  text-align: justify;
}
.certificate-body strong,
.certificate-body em,
.certificate-body strong em,
.certificate-body em strong,
.certificate-body .cert-em,
.cert-em {
  font-weight: 700;
  font-style: italic;
}
.certificate-signs {
  width: 100%;
  border-collapse: collapse;
  margin-top: 14mm;
  font-size: 11pt;
  font-weight: normal;
}
.certificate-signs td {
  width: 50%;
  vertical-align: bottom;
  padding: 0;
  font-size: 11pt;
  font-weight: normal;
}
.certificate-sign-left { text-align: left; }
.certificate-sign-head {
  text-align: center;
  min-width: 45mm;
  white-space: nowrap;
}
.certificate-sign-school {
  display: block;
  white-space: nowrap;
}
CSS;
}

/**
 * Paint rings that mirror screen CSS borders (inside 8mm sheet padding).
 */
function certificateBorderPaintTestimonialFrames(\Mpdf\Mpdf $mpdf): void
{
    $left = (float) $mpdf->lMargin;
    $top = (float) $mpdf->tMargin;
    $width = (float) $mpdf->w - (float) $mpdf->lMargin - (float) $mpdf->rMargin;
    $height = (float) $mpdf->h - (float) $mpdf->tMargin - (float) $mpdf->bMargin;

    // Outer navy — matches border: 0.7mm solid #1e3a5f
    $mpdf->SetDrawColor(30, 58, 95);
    $mpdf->SetLineWidth(0.7);
    $mpdf->Rect($left, $top, $width, $height);

    // Gold — after outer border+pad (0.7+1.4=2.1)
    $goldInset = 2.1;
    $mpdf->SetDrawColor(196, 163, 90);
    $mpdf->SetLineWidth(0.45);
    $mpdf->Rect(
        $left + $goldInset,
        $top + $goldInset,
        $width - (2 * $goldInset),
        $height - (2 * $goldInset)
    );

    // Inner double navy — after gold border+pad (2.1+0.45+1.4=3.95)
    $innerOuter = 3.95;
    $innerInner = 4.55;
    $mpdf->SetDrawColor(30, 58, 95);
    $mpdf->SetLineWidth(0.4);
    $mpdf->Rect(
        $left + $innerOuter,
        $top + $innerOuter,
        $width - (2 * $innerOuter),
        $height - (2 * $innerOuter)
    );
    $mpdf->SetLineWidth(0.35);
    $mpdf->Rect(
        $left + $innerInner,
        $top + $innerInner,
        $width - (2 * $innerInner),
        $height - (2 * $innerInner)
    );
}

/**
 * @param array{
 *   settings: array,
 *   type: string,
 *   typeTitle: string,
 *   lang: string,
 *   sheetCopy: array,
 *   schoolHeading: array,
 *   issueDate: string,
 *   logoSrc?: string,
 *   photoSrc?: string,
 *   qrHtml?: string,
 *   profileUrl?: string,
 *   sheetId?: int
 * } $context
 */
function certificateTestimonialSheetHtml(array $student, array $context, string $mode = 'html'): string
{
    $settings = is_array($context['settings'] ?? null) ? $context['settings'] : [];
    $type = (string) ($context['type'] ?? 'testimonial');
    $typeTitle = (string) ($context['typeTitle'] ?? '');
    $lang = certificateLanguage((string) ($context['lang'] ?? 'bn'));
    $sheetCopy = is_array($context['sheetCopy'] ?? null) ? $context['sheetCopy'] : certificateSheetCopy($lang);
    $schoolHeading = is_array($context['schoolHeading'] ?? null)
        ? $context['schoolHeading']
        : certificateSchoolHeading($settings, $lang);
    $issueDate = (string) ($context['issueDate'] ?? '');
    $sheetId = (int) ($context['sheetId'] ?? ($student['id'] ?? 0));

    $schoolName = trim((string) ($schoolHeading['name'] ?? ''));
    $schoolNameDisplay = $lang === 'en' ? mb_strtoupper($schoolName, 'UTF-8') : $schoolName;

    $logoSrc = trim((string) ($context['logoSrc'] ?? ''));
    if ($logoSrc === '' && $mode === 'html' && !empty($settings['logo'])) {
        $logoSrc = publicSiteUrl((string) $settings['logo']);
    }
    $photoSrc = trim((string) ($context['photoSrc'] ?? ''));
    if ($photoSrc === '' && $mode === 'html') {
        $photoSrc = studentPhotoUrl($student['student_pic'] ?? '');
    }

    $profileUrl = trim((string) ($context['profileUrl'] ?? ''));
    $qrHtml = (string) ($context['qrHtml'] ?? '');
    // QR without <a href> so print CSS does not append the URL under the code.
    if ($qrHtml === '' && $profileUrl !== '') {
        $qrPng = banglaQrPngDataUri($profileUrl);
        if ($qrPng !== '') {
            $qrHtml = '<div class="certificate-qr"><img src="' . h($qrPng) . '" alt=""></div>';
        }
    }

    $body = certificateDocumentBody($student, $type, $settings, $lang);
    $bodyHtml = '';
    foreach (preg_split("/\n\s*\n/", trim($body)) ?: [$body] as $para) {
        $para = trim((string) $para);
        if ($para === '') {
            continue;
        }
        $bodyHtml .= '<p class="certificate-body">' . certificateBodyHtml($para) . '</p>';
    }

    $logoHtml = $logoSrc !== ''
        ? '<img class="certificate-logo" src="' . h($logoSrc) . '" alt="">'
        : '';
    $photoHtml = $photoSrc !== ''
        ? '<img class="certificate-photo" src="' . h($photoSrc) . '" alt="">'
        : '';

    $main = '<table class="certificate-head"><tr>'
        . '<td class="certificate-brand-side">' . $logoHtml . '</td>'
        . '<td class="certificate-brand">'
        . '<div class="h3">' . h($schoolNameDisplay) . '</div>'
        . '<div class="certificate-meta">' . h((string) ($schoolHeading['address'] ?? '')) . '</div>'
        . '<div class="certificate-meta">EIIN: ' . h((string) ($settings['eiin'] ?? ''))
        . ' · ' . h((string) ($sheetCopy['mobile'] ?? 'Mobile')) . ': '
        . h((string) ($settings['mobile'] ?? '')) . '</div>'
        . '</td>'
        . '<td class="certificate-id-wrap">' . $photoHtml . '</td>'
        . '</tr></table>'
        . '<table class="certificate-title-row"><tr>'
        . '<td class="certificate-brand-side">' . $qrHtml . '</td>'
        . '<td class="certificate-type"><span>' . h($typeTitle) . '</span></td>'
        . '<td class="certificate-id-wrap"></td>'
        . '</tr></table>'
        . '<div class="certificate-issued">' . h((string) ($sheetCopy['date'] ?? 'Date')) . ': ' . h($issueDate) . '</div>'
        . '<div class="certificate-copy">' . $bodyHtml . '</div>';

    $foot = '<table class="certificate-signs"><tr>'
        . '<td class="certificate-sign-left">' . h((string) ($sheetCopy['date'] ?? 'Date')) . ': ' . h($issueDate) . '</td>'
        . '<td class="certificate-sign-head" nowrap="nowrap" style="white-space:nowrap;">'
        . h((string) ($sheetCopy['headTeacher'] ?? 'Head Teacher'))
        . '<br><span class="certificate-sign-school" style="white-space:nowrap;">' . h($schoolNameDisplay) . '</span>'
        . '</td>'
        . '</tr></table>';

    $inner = $main . $foot;

    $idAttr = $mode === 'html' && $sheetId > 0 ? ' id="cert-' . $sheetId . '"' : '';

    if ($mode === 'pdf') {
        return '<div class="certificate-sheet">' . $inner . '</div>';
    }

    $languageClass = $lang === 'en' ? ' is-english' : '';

    return '<div class="certificate-sheet' . $languageClass . '"' . $idAttr . '>'
        . certificateBorderOpenHtml('html')
        . $inner
        . certificateBorderCloseHtml('html')
        . '</div>';
}
