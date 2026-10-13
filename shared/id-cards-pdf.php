<?php
declare(strict_types=1);

require_once __DIR__ . '/mpdf-bangla.php';

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

function outputStudentIdCardsPdf(
    array $students,
    array $settings,
    string $filename,
    string $address = '',
    string $mobile = '',
    string $email = ''
): void {
    if ($students === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো শিক্ষার্থী নেই।');
    }

    $mpdf = createBanglaMpdf(
        (string) ($settings['school_name_bn'] ?? 'আইডি কার্ড'),
        (string) ($settings['school_name_bn'] ?? 'School Admin')
    );
    $context = [
        'schoolName' => trim((string) ($settings['school_name_bn'] ?? '')),
        'eiin' => trim((string) ($settings['eiin'] ?? '')),
        'established' => trim((string) ($settings['established'] ?? '')),
        'mpo' => trim((string) ($settings['mpo_code'] ?? '')),
        'address' => $address,
        'mobile' => $mobile,
        'email' => $email,
        'logo' => banglaPdfImageSrc(mpdfSchoolLogoPath($settings)),
    ];

    $mpdf->WriteHTML(studentIdCardPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $first = true;
    foreach (['front', 'back'] as $side) {
        foreach (array_chunk($students, 8) as $chunk) {
            if ($first) {
                $first = false;
            } else {
                $mpdf->AddPage();
            }
            studentIdCardPdfPaintPage($mpdf, $chunk, $side, $context);
        }
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'student-id-cards.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

function banglaPdfImageSrc(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }

    return str_replace('\\', '/', $path);
}

function studentIdCardPdfMetrics(): array
{
    // HTML print card is 323 x 204 px (CR80 85.6 x 54 mm).
    $px = 85.6 / 323;

    return [
        'width' => 85.6,
        'height' => 54.0,
        'gapX' => 2.5,
        'gapY' => 5.0,
        'wm' => 124 * $px,
        'wmTop' => (52 + (204 - 52 - 26 - 124) / 2) * $px,
        'wmFrontLeft' => (80 + (323 - 80 - 124) / 2) * $px,
        'wmBackLeft' => ((323 - 124) / 2) * $px,
    ];
}

function studentIdCardPdfPaintPage(\Mpdf\Mpdf $mpdf, array $students, string $side, array $context): void
{
    $metrics = studentIdCardPdfMetrics();
    $wmFile = trim((string) ($context['logo'] ?? '')) !== ''
        ? studentIdCardWatermarkPngPath((string) $context['logo'])
        : '';

    $count = count($students);
    $cols = $count > 1 ? 2 : 1;
    $rows = max(1, (int) ceil($count / 2));
    $gridW = $cols * $metrics['width'] + ($cols - 1) * $metrics['gapX'];
    // Horizontally center on the page; keep cards at the top (no vertical centering).
    $marginX = max(0.0, ($mpdf->w - $gridW) / 2);
    $marginY = max(0.0, (float) $mpdf->tMargin);

    foreach (array_values($students) as $index => $student) {
        $col = $index % 2;
        $row = intdiv($index, 2);
        $x = $marginX + $col * ($metrics['width'] + $metrics['gapX']);
        $y = $marginY + $row * ($metrics['height'] + $metrics['gapY']);

        $mpdf->SetDrawColor(15, 47, 38);
        $mpdf->SetFillColor(255, 255, 255);
        $mpdf->SetLineWidth(0.3);
        $mpdf->Rect($x, $y, $metrics['width'], $metrics['height'], 'F');

        $html = $side === 'back'
            ? studentIdCardBackHtml($student, $context)
            : studentIdCardFrontHtml($student, $context);

        $mpdf->WriteFixedPosHTML($html, $x, $y, $metrics['width'], $metrics['height'], 'hidden');

        if ($wmFile !== '' && is_file($wmFile)) {
            // Same 124px faded logo as card UI; back is full-card centered, front is shifted past the photo.
            $wmX = $x + ($side === 'back' ? $metrics['wmBackLeft'] : $metrics['wmFrontLeft']);
            $wmY = $y + $metrics['wmTop'];
            $mpdf->Image($wmFile, $wmX, $wmY, $metrics['wm'], $metrics['wm'], 'png', '', true, false);
        }
    }
}

function studentIdCardWatermarkPngPath(string $logoPath): string
{
    $logoPath = str_replace('\\', '/', $logoPath);
    if ($logoPath === '' || !is_file($logoPath) || !function_exists('imagecreatetruecolor')) {
        return $logoPath;
    }

    $mtime = (string) (@filemtime($logoPath) ?: 0);
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bmlhs-idcard-wm-' . md5($logoPath . '|' . $mtime) . '.png';
    if (is_file($tmp) && filesize($tmp) > 32) {
        return str_replace('\\', '/', $tmp);
    }

    $binary = @file_get_contents($logoPath);
    if ($binary === false) {
        return $logoPath;
    }
    $src = @imagecreatefromstring($binary);
    if ($src === false) {
        return $logoPath;
    }

    // Match HTML print: .id-card-watermark img { width/height: 124px; opacity: .18; object-fit: contain }
    $box = 124;
    $dst = imagecreatetruecolor($box, $box);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $box, $box, $clear);

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = min($box / max(1, $srcW), $box / max(1, $srcH));
    $dstW = max(1, (int) round($srcW * $scale));
    $dstH = max(1, (int) round($srcH * $scale));
    $dstX = (int) round(($box - $dstW) / 2);
    $dstY = (int) round(($box - $dstH) / 2);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagealphablending($dst, false);

    for ($x = 0; $x < $box; $x++) {
        for ($y = 0; $y < $box; $y++) {
            $rgba = imagecolorat($dst, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;
            $opacity = (127 - $alpha) / 127;
            if ($opacity <= 0) {
                continue;
            }
            $newAlpha = 127 - (int) round($opacity * 0.18 * 127);
            imagesetpixel(
                $dst,
                $x,
                $y,
                imagecolorallocatealpha($dst, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $newAlpha)
            );
        }
    }

    imagesavealpha($dst, true);
    $written = imagepng($dst, $tmp);
    imagedestroy($dst);
    imagedestroy($src);
    if ($written !== true || !is_file($tmp)) {
        return $logoPath;
    }

    return str_replace('\\', '/', $tmp);
}

function studentIdCardPdfCss(): string
{
    return <<<'CSS'
body { font-family: hindsiliguri; color: #0f2f26; }
.sheet { width: 100%; border-collapse: collapse; }
.slot { width: 50%; vertical-align: top; padding: 0 2.5mm 5mm 0; }
.card { width: 85.6mm; height: 54mm; border: 0.3mm solid #0f2f26; border-collapse: collapse; color: #0f2f26; background: transparent; }
.headcell { background-color: #0f2f26; color: #ffffff; padding: 1.4mm 2mm; }
.headinner td { color: #ffffff; vertical-align: middle; }
.school { font-size: 9.2pt; font-weight: bold; color: #ffffff; line-height: 1.2; }
.meta { font-size: 7.2pt; color: #ffffff; line-height: 1.2; margin-top: 0.4mm; }
.logo { width: 7.4mm; height: 7.4mm; }
.goldline { font-size: 1px; line-height: 2px; height: 2px; }
.titlecell { background-color: #e8c078; color: #0f2f26; font-size: 7.4pt; font-weight: bold; letter-spacing: 0.8pt; padding: 0.7mm 2mm; }
.bodycell { padding: 1.4mm 2mm 1mm 2mm; color: #0f2f26; background: transparent; }
.photo { width: 16.5mm; height: 20.5mm; border: 0.35mm solid #1a4d3e; }
.name { font-size: 11pt; font-weight: bold; line-height: 1.18; color: #0f2f26; }
.eng { font-size: 7.6pt; line-height: 1.2; color: #3d5249; }
.k { font-size: 8pt; color: #5a6f66; }
.v { font-size: 8.2pt; font-weight: bold; color: #0f2f26; line-height: 1.32; }
.footcell { background-color: #efe4c8; font-size: 7.4pt; padding: 1.2mm 2mm; color: #0f2f26; }
.footcell-dark { background-color: #0f2f26; font-size: 7.4pt; padding: 1.2mm 2mm; color: #e8c078; }
.notice { font-size: 8pt; font-weight: bold; color: #0f2f26; }
.copy { font-size: 6.7pt; line-height: 1.35; color: #1a2a24; }
.classline { font-size: 7.2pt; font-weight: bold; color: #0f2f26; line-height: 1.3; }
.divider { font-size: 1px; line-height: 1px; height: 0.35mm; }
.qr { width: 17mm; height: 17mm; border: 0.35mm solid #1a4d3e; }
.qr-name { font-size: 6pt; font-weight: bold; color: #0f2f26; text-align: center; line-height: 1.15; }
CSS;
}

function studentIdCardFrontHtml(array $student, array $context): string
{
    $photo = banglaPdfImageSrc(mpdfStudentPhotoPath((string) ($student['student_pic'] ?? '')));
    $photoHtml = $photo !== ''
        ? '<img class="photo" src="' . h($photo) . '" width="47" height="58" alt="">'
        : '<table width="47" cellpadding="0" cellspacing="0" style="border:0.35mm solid #1a4d3e;"><tr><td width="47" height="58" bgcolor="#e2e8f0">&nbsp;</td></tr></table>';
    $birth = function_exists('publicStaffDate') ? publicStaffDate($student['birth_date'] ?? null) : '';
    $classLine = trim((string) ($student['class_name_ban'] ?? ''));
    $sectionLine = trim((string) ($student['section_name_ban'] ?? ''));
    $classDisplay = $classLine . ($sectionLine !== '' ? ' · ' . $sectionLine : '');
    $fact = static function (string $label, string $value): string {
        if ($value === '') {
            return '';
        }

        return '<tr><td class="k" width="22mm" valign="top">' . h($label) . '</td><td class="v" valign="top">' . h($value) . '</td></tr>';
    };

    return studentIdCardShellHtml(
        $context,
        'EIIN: ' . (string) $context['eiin'],
        '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="19mm" valign="top">' . $photoHtml . '</td>'
        . '<td valign="top">'
        . '<div class="name">' . h(trim((string) ($student['name_ban'] ?? ''))) . '</div>'
        . '<div class="eng">' . h(trim((string) ($student['name_eng'] ?? ''))) . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:0.6mm;">'
        . $fact('শ্রেণি', $classDisplay)
        . $fact('রোল', trim((string) ($student['cls_roll'] ?? '')) . ' · সেশন ' . trim((string) ($student['session_year'] ?? '')))
        . $fact('পিতা', trim((string) ($student['fname_ban'] ?? '')))
        . $fact('মাতা', trim((string) ($student['mname_ban'] ?? '')))
        . ($birth !== '' ? $fact('জন্ম তারিখ', $birth) : '')
        . '</table>'
        . '</td></tr></table>',
        trim((string) ($student['mobile'] ?? '')) !== '' ? 'মোবাইল: ' . h(trim((string) $student['mobile'])) : '',
        'প্রধান শিক্ষক',
        'STUDENT ID CARD'
    );
}

function studentIdCardBackHtml(array $student, array $context): string
{
    $established = trim((string) ($context['established'] ?? ''));
    $headMeta = $established !== '' ? 'স্থাপিত ' . $established : '';
    $studentId = (int) ($student['id'] ?? 0);
    $profileUrl = $studentId > 0 ? publicAbsoluteUrl(publicStudentProfileUrl($studentId)) : '';
    $qr = $profileUrl !== '' ? banglaQrPngDataUri($profileUrl) : '';
    $details = '';
    if (trim((string) ($context['address'] ?? '')) !== '') {
        $details .= '<div class="copy">' . h((string) $context['address']) . '</div>';
    }
    if (trim((string) ($context['mobile'] ?? '')) !== '') {
        $details .= '<div class="copy">মোবাইল: ' . h((string) $context['mobile']) . '</div>';
    }
    if (trim((string) ($context['email'] ?? '')) !== '') {
        $details .= '<div class="copy">' . h((string) $context['email']) . '</div>';
    }
    $classLine = studentIdCardBackClassLine($student);
    if ($classLine !== '') {
        $details .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin:1mm 0 0.5mm 0;"><tr><td class="divider" bgcolor="#c4892a" style="height:0.35mm;font-size:1px;line-height:1px;">&nbsp;</td></tr></table>'
            . '<div class="classline">' . h($classLine) . '</div>';
    }

    $studentName = trim((string) ($student['name_ban'] ?? $student['name_eng'] ?? ''));
    $body = '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td valign="top">'
        . '<div class="notice">দৃষ্টি আকর্ষণ</div>'
        . '<div class="copy">এই কার্ডটি প্রতিষ্ঠানের নিজস্ব পরিচিতি। ব্যবহারকারী ব্যতীত অন্য কোথাও পাওয়া গেলে অনুগ্রহ করে বিদ্যালয়ে পৌঁছে দিন।</div>'
        . $details
        . '</td>'
        . '<td width="21mm" valign="top" align="center">'
        . ($qr !== '' ? '<img class="qr" src="' . h($qr) . '" width="48" height="48" alt="">'
            . ($studentName !== '' ? '<div class="qr-name">' . h($studentName) . '</div>' : '') : '')
        . '</td></tr></table>';

    $mpo = trim((string) ($context['mpo'] ?? ''));

    return studentIdCardShellHtml(
        $context,
        $headMeta,
        $body,
        $mpo !== '' ? 'MPO: ' . h($mpo) : '',
        'EIIN: ' . h((string) $context['eiin']),
        '',
        true
    );
}

function studentIdCardShellHtml(
    array $context,
    string $meta,
    string $body,
    string $footLeft,
    string $footRight,
    string $titleBar = '',
    bool $darkFooter = false
): string {
    $logo = (string) ($context['logo'] ?? '');
    $logoHtml = $logo !== ''
        ? '<img class="logo" src="' . h($logo) . '" width="21" height="21" alt="">'
        : '';
    $footClass = $darkFooter ? 'footcell-dark' : 'footcell';
    $footBg = $darkFooter ? '#0f2f26' : '#efe4c8';
    $footColor = $darkFooter ? '#e8c078' : '#0f2f26';

    $logoGap = $titleBar !== '' ? '2.4mm' : '1.4mm';
    $bodyHeight = $titleBar !== '' ? '31.2mm' : '36.4mm';

    return '<table class="card" width="242" cellpadding="0" cellspacing="0" style="width:85.6mm;height:54mm;">'
        . '<tr><td class="headcell" bgcolor="#0f2f26">'
        . '<table class="headinner" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . ($logoHtml !== '' ? '<td width="24" style="padding-right:' . $logoGap . ';">' . $logoHtml . '</td>' : '')
        . '<td>'
        . '<div class="school" style="color:#ffffff;">' . h((string) $context['schoolName']) . '</div>'
        . ($meta !== '' ? '<div class="meta" style="color:#ffffff;">' . h($meta) . '</div>' : '')
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td class="goldline" bgcolor="#c4892a">&nbsp;</td></tr>'
        . ($titleBar !== '' ? '<tr><td class="titlecell" bgcolor="#e8c078" align="center" style="color:#0f2f26;">' . h($titleBar) . '</td></tr>' : '')
        . '<tr><td class="bodycell" height="' . $bodyHeight . '" style="height:' . $bodyHeight . ';">' . $body . '</td></tr>'
        . '<tr><td class="' . $footClass . '" bgcolor="' . $footBg . '">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="color:' . $footColor . ';">' . $footLeft . '</td>'
        . '<td align="right" style="color:' . $footColor . ';">' . $footRight . '</td>'
        . '</tr></table>'
        . '</td></tr>'
        . '</table>';
}

function outputSeatLevelCardsPdf(array $students, array $settings, string $filename, string $examName = '', string $year = ''): void
{
    if ($students === []) {
        throw new RuntimeException('এই ফিল্টারে কোনো শিক্ষার্থী নেই।');
    }

    $mpdf = createBanglaMpdf(
        (string) ($settings['school_name_bn'] ?? 'সিট লেবেল'),
        (string) ($settings['school_name_bn'] ?? 'School Admin')
    );
    // Same Bangla font stack as ID cards; only tighten page margins for 5×2 layout.
    $mpdf->SetMargins(10, 10, 10);
    $mpdf->SetAutoPageBreak(false, 10);

    $year = trim($year) !== '' ? trim($year) : date('Y');
    $context = [
        'schoolName' => trim((string) ($settings['school_name_bn'] ?? '')),
        'eiin' => trim((string) ($settings['eiin'] ?? '')),
        'logo' => banglaPdfImageSrc(mpdfSchoolLogoPath($settings)),
        'examName' => trim($examName),
        'year' => $year,
    ];

    $mpdf->WriteHTML(seatLevelCardPdfCss(), \Mpdf\HTMLParserMode::HEADER_CSS);
    $first = true;
    foreach (array_chunk(array_values($students), 10) as $chunk) {
        if ($first) {
            $first = false;
        } else {
            $mpdf->AddPage();
        }
        seatLevelPdfPaintPage($mpdf, $chunk, $context);
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'seat-label.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
}

function seatLabelRollBadgePngPath(string $roll): string
{
    $roll = trim($roll);
    if ($roll === '') {
        $roll = '—';
    }
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bmlhs-seat-roll-' . md5($roll) . '.png';
    if (is_file($tmp) && filesize($tmp) > 32) {
        return str_replace('\\', '/', $tmp);
    }

    // Match screen .id-card-roll-badge (30×30 CSS px) at higher raster for sharp PDF.
    $box = 120;
    $im = imagecreatetruecolor($box, $box);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefilledrectangle($im, 0, 0, $box, $box, $clear);
    imagealphablending($im, true);

    $cx = (int) ($box / 2);
    $cy = (int) ($box / 2);
    $outer = (int) ($box / 2 - 2);
    $border = imagecolorallocate($im, 15, 47, 38); // #0f2f26
    $gold = imagecolorallocate($im, 232, 192, 120); // #e8c078
    $goldLite = imagecolorallocate($im, 246, 231, 193); // #f6e7c1
    $ink = imagecolorallocate($im, 15, 47, 38);

    imagefilledellipse($im, $cx, $cy, $outer * 2, $outer * 2, $border);
    imagefilledellipse($im, $cx, $cy, ($outer - 5) * 2, ($outer - 5) * 2, $gold);
    // Soft highlight like screen radial gradient.
    imagefilledellipse($im, (int) ($cx - $outer * 0.22), (int) ($cy - $outer * 0.25), (int) ($outer * 0.9), (int) ($outer * 0.9), $goldLite);

    $font = rtrim(mpdfBanglaTtfDir(), '/\\') . DIRECTORY_SEPARATOR . 'HindSiliguri-Bold.ttf';
    if (is_file($font) && function_exists('imagettftext')) {
        $size = mb_strlen($roll, 'UTF-8') > 2 ? 28.0 : 36.0;
        $bbox = imagettfbbox($size, 0, $font, $roll);
        $textW = (float) abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0));
        $textH = (float) abs(($bbox[7] ?? 0) - ($bbox[1] ?? 0));
        $tx = (int) round(($box - $textW) / 2 - ($bbox[0] ?? 0));
        $ty = (int) round(($box + $textH) / 2 - 2);
        imagettftext($im, $size, 0, $tx, $ty, $ink, $font, $roll);
    } else {
        $fontW = (int) imagefontwidth(5) * strlen($roll);
        imagestring($im, 5, (int) (($box - $fontW) / 2), (int) (($box - imagefontheight(5)) / 2), $roll, $ink);
    }

    imagesavealpha($im, true);
    $written = imagepng($im, $tmp);
    imagedestroy($im);
    if ($written !== true || !is_file($tmp)) {
        return '';
    }

    return str_replace('\\', '/', $tmp);
}

function seatLevelPdfMetrics(): array
{
    // Screen seat-label: card 86×44mm; CSS px at 96dpi.
    $px = static fn (float $n): float => $n * 25.4 / 96;
    $cardW = 86.0;
    $cardH = 44.0;
    $wm = $px(74);
    $boxLeft = $px(48);
    $boxTop = $px(28);
    $boxW = $cardW - $boxLeft;
    $boxH = $cardH - $boxTop;

    return [
        'width' => $cardW,
        'height' => $cardH,
        'gapX' => 7.0,
        'gapY' => 6.0,
        'wm' => $wm,
        'wmLeft' => $boxLeft + ($boxW - $wm) / 2,
        'wmTop' => $boxTop + ($boxH - $wm) / 2,
    ];
}

function seatLevelCardPdfCss(): string
{
    // Font sizes slightly larger than screen px→pt, still within 86×44mm card.
    return <<<'CSS'
body, .sl-card, .sl-school, .sl-meta, .sl-title, .sl-name, .sl-k, .sl-v {
  font-family: hindsiliguri;
  color: #0f2f26;
}
.sl-card {
  width: 86mm;
  height: 44mm;
  border: 0.26mm solid #0f2f26;
  border-collapse: collapse;
  color: #0f2f26;
  background-color: #ffffff;
}
.sl-head {
  background-color: #0f2f26;
  color: #ffffff;
  padding: 0.7mm 1.5mm 0.4mm;
}
.sl-school { font-size: 11pt; font-weight: bold; color: #ffffff; line-height: 1.08; }
.sl-meta { font-size: 8.3pt; color: #ffffff; line-height: 1.08; margin-top: 0; }
.sl-logo { width: 5.6mm; height: 5.6mm; }
.sl-gold { font-size: 1px; line-height: 0.5mm; height: 0.5mm; }
.sl-titlecell {
  background-color: #e8c078;
  color: #0f2f26;
  padding: 0.4mm 1.4mm 0.6mm;
  border-bottom: 0.26mm solid #d4b06a;
}
.sl-title {
  font-size: 8.7pt;
  font-weight: bold;
  letter-spacing: 0.1pt;
  color: #0f2f26;
  line-height: 1.15;
}
.sl-body { padding: 0.4mm 1.5mm; color: #0f2f26; }
.sl-photo-col { width: 12.2mm; }
.sl-photo {
  width: 12.2mm;
  height: 13.8mm;
  border: 0.5mm solid #1a4d3e;
}
.sl-roll-img {
  width: 7.9mm;
  height: 7.9mm;
  margin-top: 0.8mm;
}
.sl-name { font-size: 12.8pt; font-weight: bold; line-height: 1.1; color: #0f2f26; margin-bottom: 0.2mm; }
.sl-k { font-size: 10pt; color: #5a6f66; line-height: 1.15; }
.sl-v { font-size: 10.4pt; font-weight: bold; color: #0f2f26; line-height: 1.15; }
.sl-fact { padding-top: 0.1mm; padding-bottom: 0.1mm; }
CSS;
}

function seatLevelCardHtml(array $student, array $context): string
{
    $photo = banglaPdfImageSrc(mpdfStudentPhotoPath((string) ($student['student_pic'] ?? '')));
    $photoHtml = $photo !== ''
        ? '<img class="sl-photo" src="' . h($photo) . '" width="46" height="52" alt="" style="width:12.2mm;height:13.8mm;border:0.5mm solid #1a4d3e;">'
        : '<table width="46" cellpadding="0" cellspacing="0" style="width:12.2mm;height:13.8mm;border:0.5mm solid #1a4d3e;"><tr><td width="46" height="52" bgcolor="#e2e8f0">&nbsp;</td></tr></table>';

    $classLine = trim((string) ($student['class_name_ban'] ?? ''));
    $sectionLine = trim((string) ($student['section_name_ban'] ?? ''));
    $classDisplay = $classLine . ($sectionLine !== '' ? ' · ' . $sectionLine : '');
    $mobile = trim((string) ($student['mobile'] ?? ''));
    $roll = trim((string) ($student['cls_roll'] ?? ''));
    $rollBadge = seatLabelRollBadgePngPath($roll);
    $rollHtml = $rollBadge !== ''
        ? '<img class="sl-roll-img" src="' . h($rollBadge) . '" width="30" height="30" alt="" style="width:7.9mm;height:7.9mm;margin-top:0.8mm;">'
        : '<div style="width:7.9mm;height:7.9mm;margin:0.8mm auto 0;border:0.45mm solid #0f2f26;border-radius:4mm;background-color:#e8c078;color:#0f2f26;font-size:10.5pt;font-weight:bold;text-align:center;line-height:7mm;">'
            . h($roll !== '' ? $roll : '—') . '</div>';

    $logo = (string) ($context['logo'] ?? '');
    $logoHtml = $logo !== ''
        ? '<img class="sl-logo" src="' . h($logo) . '" width="22" height="22" alt="" style="width:5.8mm;height:5.8mm;">'
        : '';
    $examName = trim((string) ($context['examName'] ?? ''));
    $year = trim((string) ($context['year'] ?? date('Y')));
    if ($year === '') {
        $year = date('Y');
    }
    $titleText = 'SEAT LABEL';
    if ($examName !== '') {
        $titleText .= ': ' . $examName . '-' . bnDigits($year);
    }

    $fact = static function (string $label, string $value): string {
        return '<tr><td class="sl-fact sl-k" width="12.5mm" valign="middle">' . h($label) . '</td>'
            . '<td class="sl-fact sl-v" valign="middle">' . h($value !== '' ? $value : '—') . '</td></tr>';
    };

    $mother = trim((string) ($student['mname_ban'] ?? ''));

    // Same column structure as screen: photo column (photo + roll badge) | info facts.
    return '<table class="sl-card" width="325" cellpadding="0" cellspacing="0" style="width:86mm;height:44mm;">'
        . '<tr><td class="sl-head" bgcolor="#0f2f26" style="background-color:#0f2f26;">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . ($logoHtml !== '' ? '<td width="24" style="padding-right:1.6mm;vertical-align:middle;">' . $logoHtml . '</td>' : '')
        . '<td style="vertical-align:middle;">'
        . '<div class="sl-school" style="color:#ffffff;">' . h((string) $context['schoolName']) . '</div>'
        . '<div class="sl-meta" style="color:#ffffff;">EIIN: ' . h((string) $context['eiin']) . '</div>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td class="sl-gold" bgcolor="#c4892a" style="background-color:#c4892a;height:0.5mm;line-height:0.5mm;font-size:1px;">&nbsp;</td></tr>'
        . '<tr><td class="sl-titlecell" bgcolor="#e8c078" align="center" style="background-color:#e8c078;color:#0f2f26;">'
        . '<div class="sl-title">' . h($titleText) . '</div></td></tr>'
        . '<tr><td class="sl-body" height="24.5mm" style="height:24.5mm;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="height:24.5mm;"><tr>'
        . '<td class="sl-photo-col" width="12.5mm" valign="top" align="center" style="width:12.5mm;">'
        . $photoHtml
        . $rollHtml
        . '</td>'
        . '<td width="1.6mm"></td>'
        . '<td valign="middle">'
        . '<div class="sl-name">' . h(trim((string) ($student['name_ban'] ?? ''))) . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0">'
        . $fact('শ্রেণি', $classDisplay)
        . $fact('পিতা', trim((string) ($student['fname_ban'] ?? '')))
        . ($mother !== '' ? $fact('মাতা', $mother) : '')
        . $fact('মোবাইল', $mobile)
        . '</table>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '</table>';
}

function seatLevelPdfPaintPage(\Mpdf\Mpdf $mpdf, array $students, array $context): void
{
    $metrics = seatLevelPdfMetrics();
    $wmFile = trim((string) ($context['logo'] ?? '')) !== ''
        ? studentIdCardWatermarkPngPath((string) $context['logo'])
        : '';

    $cols = 2;
    $gridW = $cols * $metrics['width'] + ($cols - 1) * $metrics['gapX'];
    $marginX = max(0.0, ($mpdf->w - $gridW) / 2);
    $marginY = max(0.0, (float) $mpdf->tMargin);

    foreach (array_values($students) as $index => $student) {
        $col = $index % 2;
        $row = intdiv($index, 2);
        $x = $marginX + $col * ($metrics['width'] + $metrics['gapX']);
        $y = $marginY + $row * ($metrics['height'] + $metrics['gapY']);

        $mpdf->SetDrawColor(15, 47, 38);
        $mpdf->SetFillColor(255, 255, 255);
        $mpdf->SetLineWidth(0.26);
        $mpdf->Rect($x, $y, $metrics['width'], $metrics['height'], 'F');

        $html = seatLevelCardHtml($student, $context);
        $mpdf->WriteFixedPosHTML($html, $x, $y, $metrics['width'], $metrics['height'], 'hidden');

        // Faded logo over body/info area — same visual as screen opacity .18 watermark.
        if ($wmFile !== '' && is_file($wmFile)) {
            $mpdf->Image(
                $wmFile,
                $x + $metrics['wmLeft'],
                $y + $metrics['wmTop'],
                $metrics['wm'],
                $metrics['wm'],
                'png',
                '',
                true,
                false
            );
        }

        $mpdf->SetDrawColor(15, 47, 38);
        $mpdf->SetLineWidth(0.26);
        $mpdf->Rect($x, $y, $metrics['width'], $metrics['height'], 'D');
    }
}

