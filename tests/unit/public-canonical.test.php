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
    publicCanonicalUrl('https://bmlhs.site/bmlhs/index.php') === 'https://bmlhs.site/bmlhs/',
    'homepage index.php is the directory'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/bmlhs/index.php?search=empty') === 'https://bmlhs.site/bmlhs/?search=empty',
    'homepage query stays on the directory'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/bmlhs/') === 'https://bmlhs.site/bmlhs/',
    'clean homepage stays unchanged'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/bmlhs/about.php') === 'https://bmlhs.site/bmlhs/about',
    'page suffix is dropped'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/bmlhs/about.php?x=1') === 'https://bmlhs.site/bmlhs/about?x=1',
    'page query stays after the suffix'
);
$check(
    publicCanonicalUrl('https://bmlhs.site/bmlhs/notice_single?id=4') === 'https://bmlhs.site/bmlhs/notice_single?id=4',
    'signed page address stays unchanged'
);
$check(
    publicCanonicalUrl('https://example.test:8080/AdminLTE-4.9.1/bmlhs/index.php') === 'https://example.test:8080/AdminLTE-4.9.1/bmlhs/',
    'local subdirectory homepage is the directory'
);

if ($failures > 0) {
    fwrite(STDERR, $failures . " canonical checks failed\n");
    exit(1);
}

fwrite(STDOUT, "public canonical checks passed\n");
