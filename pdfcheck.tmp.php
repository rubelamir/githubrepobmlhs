<?php
require __DIR__ . '/dist/includes/auth.php';
require_once __DIR__ . '/shared/certificate-sheet.php';
require_once __DIR__ . '/shared/certificates-pdf.php';
$db = getDatabaseConnection();
$settings = function_exists('schoolSettings') ? schoolSettings($db) : [];
$s = fetchRows($db, "SELECT * FROM students_info WHERE ssc_batch_year='2026' AND status=3 ORDER BY id LIMIT 1");
foreach (['bn', 'en'] as $lang) {
    outputCertificatesPdf($s, $settings, 'testimonial', certificateTypeTitle('testimonial', $lang), date('d/m/Y'), "t-$lang.pdf", $lang, __DIR__ . "/pdfcheck-$lang.pdf");
    echo "pdfcheck-$lang.pdf\n";
}
