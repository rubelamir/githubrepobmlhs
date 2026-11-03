<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/site.php';

// Public, static listing pages. Pages needing login, signed links or query input are left out on purpose.
$pages = [
    '' => ['1.0', 'daily'],
    'about' => ['0.8', 'monthly'],
    'notice' => ['0.9', 'daily'],
    'events' => ['0.8', 'weekly'],
    'image_gallery' => ['0.6', 'weekly'],
    'download_preview' => ['0.6', 'weekly'],
    'teacher_staff' => ['0.7', 'monthly'],
    'managing_comitee' => ['0.6', 'monthly'],
    'meritorious_students' => ['0.6', 'monthly'],
    'retirement_teacher' => ['0.4', 'yearly'],
    'ssc_result' => ['0.7', 'yearly'],
    'syllabus' => ['0.6', 'yearly'],
    'academic_calender' => ['0.6', 'yearly'],
    'routine' => ['0.5', 'monthly'],
    'contact' => ['0.7', 'yearly'],
    'online_admission' => ['0.8', 'monthly'],
    'terms_conditions' => ['0.2', 'yearly'],
];

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $path => [$priority, $frequency]) {
    if ($path !== '' && !is_file(__DIR__ . '/' . $path . '.php')) {
        continue;
    }
    $loc = htmlspecialchars(publicAbsoluteUrl($path), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    echo "  <url><loc>{$loc}</loc><changefreq>{$frequency}</changefreq><priority>{$priority}</priority></url>\n";
}
echo '</urlset>' . "\n";
