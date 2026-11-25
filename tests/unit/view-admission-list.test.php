<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root . '/bmlhs/view_admission.php');
$pdfSource = (string) file_get_contents($root . '/bmlhs/view_admission_pdf.php');
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        $failed++;
    }
};

$check(
    !str_contains($source, 'session_year = ?')
    && !str_contains($source, "date('Y')"),
    'applicant list is not filtered to the calendar year'
);
$check(
    str_contains($source, "sections = 'others'")
    && str_contains($source, 'ভর্তির জন্য জমা পড়া প্রাথমিক আবেদনের তালিকা'),
    'copy still describes the preliminary application list'
);
$check(
    !str_contains($source, 'চলতি শিক্ষাবর্ষ'),
    'hero text no longer says current academic year'
);
$check(
    str_contains($source, 'admPrintBtn')
    && str_contains($source, 'PDF ডাউনলোড')
    && str_contains($source, 'view_admission_pdf.php'),
    'list page offers print and PDF download'
);

$online = (string) file_get_contents($root . '/bmlhs/online_admission.php');
$check(
    !str_contains($online, 'চলতি বছরের প্রাথমিক আবেদন'),
    'online admission teaser no longer says current year only'
);

$check(
    is_file($root . '/bmlhs/view_admission_pdf.php'),
    'admission applicants PDF endpoint exists'
);
$check(
    str_contains($pdfSource, "sections = 'others'")
    && str_contains($pdfSource, 'createBanglaMpdf')
    && !str_contains($pdfSource, "date('Y')"),
    'PDF lists pending applications without a calendar-year filter'
);

if ($failed > 0) {
    fwrite(STDERR, $failed . " check(s) failed\n");
    exit(1);
}
echo "ok\n";
