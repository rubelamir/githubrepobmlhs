<?php
declare(strict_types=1);

function mpdfBanglaFontDir(): string
{
    return projectRootPath() . DIRECTORY_SEPARATOR . 'bmlhs' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR;
}

function mpdfBanglaTtfDir(): string
{
    return mpdfBanglaFontDir() . 'ttf' . DIRECTORY_SEPARATOR;
}

function mpdfBanglaFontFamily(): string
{
    // Noto digits stay clearer than Hind Siliguri; OTL must stay off (mPDF rejects Noto GPOS).
    return 'notosansbengali';
}

function mpdfRequireLibrary(): void
{
    // mPDF is the active PDF backend; no legacy TCPDF runtime is required.
}

function mpdfEnsureBanglaFont(): string
{
    return mpdfBanglaFontFamily();
}

function banglaQrPngDataUri(string $text, int $scale = 4): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    require_once projectRootPath() . '/bmlhs/include/barcodes/qrcode.php';
    try {
        $barcode = (new QRcode($text, 'M'))->getBarcodeArray();
    } catch (Throwable $exception) {
        error_log('QR code generation failed: ' . $exception->getMessage());
        return '';
    }
    if (!is_array($barcode) || empty($barcode['bcode'])) {
        return '';
    }

    $quiet = 2;
    $span = max((int) $barcode['num_rows'], (int) $barcode['num_cols']) + ($quiet * 2);
    $scale = max(2, $scale);
    $px = $span * $scale;
    $im = imagecreatetruecolor($px, $px);
    $white = imagecolorallocate($im, 255, 255, 255);
    $ink = imagecolorallocate($im, 15, 47, 38);
    imagefilledrectangle($im, 0, 0, $px, $px, $white);
    foreach ($barcode['bcode'] as $row => $columns) {
        foreach ($columns as $col => $bit) {
            if ((int) $bit !== 1) {
                continue;
            }
            imagefilledrectangle(
                $im,
                ($col + $quiet) * $scale,
                ($row + $quiet) * $scale,
                (($col + $quiet + 1) * $scale) - 1,
                (($row + $quiet + 1) * $scale) - 1,
                $ink
            );
        }
    }
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    imagedestroy($im);

    return $png === '' ? '' : 'data:image/png;base64,' . base64_encode($png);
}

function createBanglaMpdf(
    string $title = '',
    string $author = '',
    string $format = 'A4',
    string $orientation = 'P'
): \Mpdf\Mpdf
{
    require_once projectRootPath() . '/shared/pdf-tools/vendor/autoload.php';

    $temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bmlhs-mpdf';
    if (!is_dir($temp) && !mkdir($temp, 0775, true) && !is_dir($temp)) {
        throw new RuntimeException('PDF temporary folder could not be created.');
    }

    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $defaultFonts = (new \Mpdf\Config\FontVariables())->getDefaults();

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => $format,
        'orientation' => $orientation,
        'margin_left' => 12,
        'margin_right' => 12,
        'margin_top' => 12,
        'margin_bottom' => 12,
        'tempDir' => $temp,
        'fontDir' => array_merge($defaultConfig['fontDir'], [rtrim(mpdfBanglaTtfDir(), '/\\')]),
        'fontdata' => $defaultFonts['fontdata'] + [
            'hindsiliguri' => [
                'R' => 'HindSiliguri-Regular.ttf',
                'B' => 'HindSiliguri-Bold.ttf',
                // Omit I/BI files so mPDF applies artificial italic on R/B.
                'useOTL' => 0xFF,
            ],
            // Bold face as its own family so italic can be slanted on real bold glyphs.
            'hindsiliguribold' => [
                'R' => 'HindSiliguri-Bold.ttf',
                'useOTL' => 0xFF,
            ],
            'notosansbengali' => [
                'R' => 'NotoSansBengali-Regular.ttf',
                'B' => 'NotoSansBengali-Bold.ttf',
                // useOTL 0xFF trips "GPOS Lookup Type 5, Format 3 not supported" on this TTF.
                'useOTL' => 0,
            ],
        ],
        'default_font' => mpdfBanglaFontFamily(),
        'default_font_size' => 10,
        'autoScriptToLang' => true,
        'autoLangToFont' => false,
    ]);
    $mpdf->SetTitle($title !== '' ? $title : 'PDF');
    $mpdf->SetAuthor($author !== '' ? $author : 'School Admin');
    $mpdf->SetCreator('School Admin');
    // Allow artificial bold/italic when face files are missing.
    $mpdf->useSubstitutions = true;

    return $mpdf;
}

function mpdfCreateBanglaDocument(string $title = '', string $author = ''): \Mpdf\Mpdf
{
    return createBanglaMpdf($title, $author, 'A4', 'P');
}

function mpdfLocalMediaPath(string $stored, string $directory = ''): string
{
    $stored = trim(str_replace('\\', '/', $stored));
    if ($stored === '' || preg_match('#^(https?:)?//#i', $stored) === 1) {
        return '';
    }

    $candidates = [];
    if ($directory !== '') {
        $candidates[] = publicSitePath(trim($directory, '/') . '/' . publicMediaBasename($stored));
    }
    $candidates[] = publicSitePath($stored);
    $basename = publicMediaBasename($stored);
    if ($basename !== '') {
        $candidates[] = publicSitePath($basename);
        $candidates[] = publicSitePath('image/' . $basename);
        $candidates[] = publicSitePath('student_pic/' . $basename);
    }

    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return '';
}

function mpdfSchoolLogoPath(array $settings): string
{
    $logo = trim((string) ($settings['logo'] ?? ''));
    $path = mpdfLocalMediaPath($logo, 'image');
    if ($path !== '') {
        return $path;
    }

    $fallback = publicSitePath('image/school_logo.png');

    return is_file($fallback) ? $fallback : '';
}

/**
 * School letterhead (logo + name + address + EIIN/mobile) for report PDFs.
 *
 * @param array<string, mixed> $settings
 */
function mpdfSchoolPadHtml(array $settings): string
{
    $logoPath = mpdfSchoolLogoPath($settings);
    $logo = $logoPath !== '' && is_file($logoPath) ? str_replace('\\', '/', $logoPath) : '';
    $schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
    $address = function_exists('schoolPlainAddress')
        ? schoolPlainAddress((string) ($settings['address'] ?? ''))
        : trim((string) ($settings['address'] ?? ''));
    $eiin = trim((string) ($settings['eiin'] ?? ''));
    $mobile = function_exists('publicStaffMobileDisplay')
        ? publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''))
        : trim((string) ($settings['mobile'] ?? ''));
    $meta = '';
    if ($eiin !== '') {
        $meta .= 'EIIN: ' . h(function_exists('bnDigits') ? bnDigits($eiin) : $eiin);
    }
    if ($eiin !== '' && $mobile !== '') {
        $meta .= ' · ';
    }
    if ($mobile !== '') {
        $meta .= 'মোবাইল: ' . h(function_exists('bnDigits') ? bnDigits($mobile) : $mobile);
    }

    return '<table class="school-pad"><tr>'
        . '<td class="school-pad-logo">'
        . ($logo !== '' ? '<img src="' . h($logo) . '" alt="">' : '')
        . '</td>'
        . '<td class="school-pad-brand">'
        . '<div class="school-pad-name">' . h($schoolName) . '</div>'
        . ($address !== '' ? '<div class="school-pad-meta">' . h($address) . '</div>' : '')
        . ($meta !== '' ? '<div class="school-pad-meta">' . $meta . '</div>' : '')
        . '</td>'
        . '<td class="school-pad-logo"></td>'
        . '</tr></table>';
}

function mpdfSchoolPadCss(): string
{
    $font = mpdfBanglaFontFamily();

    return <<<CSS
body { font-family: {$font}, sans-serif; color: #16231e; }
.school-pad { width: 100%; border-collapse: collapse; margin: 0 0 8px; border-bottom: 2px solid #1a3a30; }
.school-pad td { border: 0; padding: 0 4px 8px; vertical-align: middle; }
.school-pad-logo { width: 18%; text-align: center; }
.school-pad-logo img { width: 52px; height: 52px; }
.school-pad-brand { width: 64%; text-align: center; }
.school-pad-name { font-size: 18px; font-weight: bold; line-height: 1.25; }
.school-pad-meta { font-size: 11px; color: #334; margin-top: 2px; }
CSS;
}

function mpdfStudentPhotoPath(?string $file): string
{
    $path = mpdfLocalMediaPath((string) $file, 'student_pic');
    if ($path !== '') {
        return $path;
    }

    $fallback = publicSitePath('image/student.png');

    return is_file($fallback) ? $fallback : '';
}

function mpdfDrawQrCode(\Mpdf\Mpdf $pdf, string $text, float $x, float $y, float $size): void
{
    $text = trim($text);
    if ($text === '' || $size <= 0) {
        return;
    }

    $dataUri = banglaQrPngDataUri($text, 4);
    if ($dataUri === '') {
        return;
    }

    $pdf->Image($dataUri, $x, $y, $size, $size, 'PNG');
}
