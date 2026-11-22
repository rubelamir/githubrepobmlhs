<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$check(
    publicCanonicalUrl('https://bmlhs.site/index.php') === 'https://bmlhs.site/',
    'homepage index.php is the directory'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/index.php?search=empty') === 'https://bmlhs.site/?search=empty',
    'homepage query stays on the directory'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/') === 'https://bmlhs.site/',
    'clean homepage stays unchanged'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/about.php') === 'https://bmlhs.site/about',
    'page suffix is dropped'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/about.php?x=1') === 'https://bmlhs.site/about?x=1',
    'page query stays after the suffix'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/notice_single?id=4') === 'https://bmlhs.site/notice_single?id=4',
    'signed page address stays unchanged'
);
$check(
    publicCanonicalUrl('https://example.test:8080/AdminLTE-4.9.1/index.php') === 'https://example.test:8080/AdminLTE-4.9.1/',
    'local subdirectory homepage is the directory'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/bmlhs/index.php') === 'https://bmlhs.site/bmlhs/',
    'legacy /bmlhs/ index.php still cleans to its directory'
);

$_SERVER['DOCUMENT_ROOT'] = projectRootPath();
$check(publicSiteUrl() === '/', 'public site root URL has no /bmlhs prefix');
$check(publicSiteUrl('about') === '/about', 'public page URL has no /bmlhs prefix');
$check(adminUrl('index.php') === '/dist/index.php', 'admin URL still uses /dist');

if ($failures > 0) {
    fwrite(STDERR, $failures . " canonical checks failed\n");
    exit(1);
}

fwrite(STDOUT, "public canonical checks passed\n");
