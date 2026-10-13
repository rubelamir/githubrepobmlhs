<?php ob_start();
include_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/includes/site.php';
startAppSession();
require_once __DIR__ . '/includes/security.php';
$school = currentSchool();
$logo = optimizedPublicImageUrl(schoolLogoUrl($school));
$pageMeta = isset($pageMeta) && is_array($pageMeta) ? $pageMeta : [];
$documentTitle = trim((string) ($pageMeta['title'] ?? ''));
$documentTitle = $documentTitle === ''
    ? (string) $school['school_name_bn']
    : $documentTitle . ' | ' . $school['school_name_bn'];
$metaDescription = trim((string) ($pageMeta['description'] ?? $school['about_short'] ?? ''));
$metaImage = trim((string) ($pageMeta['image'] ?? $logo));
if ($metaImage === '') {
    $metaImage = $logo;
}
$metaType = trim((string) ($pageMeta['type'] ?? 'website'));
if ($metaType === '') {
    $metaType = 'website';
}
$metaUrl = trim((string) ($pageMeta['url'] ?? ''));
if ($metaUrl === '') {
    $metaUrl = currentAbsoluteUrl();
} elseif (preg_match('#^https?://#i', $metaUrl) !== 1) {
    $metaUrl = publicAbsoluteUrl($metaUrl);
}
if (preg_match('#^https?://#i', $metaImage) !== 1) {
    $metaImage = publicAbsoluteUrl($metaImage);
}

// Pages may declare `$pageAssets` before including this file to load only the
// vendor libraries they use. Without it no extra vendor library is loaded.
$schoolAssetKeys = ['animate', 'owl', 'sweetalert', 'datatables', 'fancybox', 'croppie', 'apexcharts', 'jquery'];
$pageAssets = isset($pageAssets) && is_array($pageAssets) ? $pageAssets : [];
$useAsset = [];
foreach ($schoolAssetKeys as $schoolAssetKey) {
    $useAsset[$schoolAssetKey] = in_array($schoolAssetKey, $pageAssets, true);
}
$useAsset['jquery'] = $useAsset['jquery'] || $useAsset['owl'] || $useAsset['datatables'] || $useAsset['croppie'];
?>
<!DOCTYPE html>
<html lang="bn">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($documentTitle) ?></title>
    <meta name="title" content="<?= h($documentTitle) ?>" />
         <meta name="description" content="<?= h($metaDescription) ?>">
  <meta name="keywords" content="<?= h($school['school_name_en']) ?>, <?= h($school['school_name_bn']) ?>">
  <meta name="author" content="Abdullah Al Hossain Rubel">
        <link rel="canonical" href="<?= h($metaUrl) ?>">
        <meta property="og:type" content="<?= h($metaType) ?>">
        <meta property="og:locale" content="bn_BD">
        <meta property="og:site_name" content="<?= h((string) $school['school_name_bn']) ?>">
        <meta property="og:title" content="<?= h($documentTitle) ?>">
        <meta property="og:description" content="<?= h($metaDescription) ?>">
        <meta property="og:url" content="<?= h($metaUrl) ?>">
        <meta property="og:image" content="<?= h($metaImage) ?>">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="<?= h($documentTitle) ?>">
        <meta name="twitter:description" content="<?= h($metaDescription) ?>">
        <meta name="twitter:image" content="<?= h($metaImage) ?>">
        <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <link rel="preload" href="fonts/ttf/NotoSansBengali-Regular.ttf" as="font" type="font/ttf" crossorigin>
        <link rel="preload" href="fonts/literata-700.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="stylesheet" href="css/critical.css?v=<?= (int) @filemtime(__DIR__ . '/css/critical.css') ?>">
        <link rel="stylesheet" href="css/style.css?v=<?= (int) @filemtime(__DIR__ . '/css/style.css') ?>">
        <link rel="stylesheet" href="vendor/bootstrap-5.3.1-dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="vendor/fontawesome-free-6.4.2-web/css/all.min.css">
        <?php if ($useAsset['animate']): ?>
        <link rel="stylesheet" href="vendor/animate.css_4.1.1_animate.min.css">
        <?php endif; ?>
        <?php if ($useAsset['owl']): ?>
        <link rel="stylesheet" href="vendor/OwlCarousel2-2.3.4/dist/assets/owl.carousel.min.css">
        <?php endif; ?>
        <?php if ($useAsset['sweetalert']): ?>
        <link rel="stylesheet" href="vendor/sweetalert2/sweetalert2@11.7.27_dist_sweetalert2.min.css">
        <?php endif; ?>
        <?php if ($useAsset['datatables']): ?>
        <link rel="stylesheet" href="vendor/DataTables/datatables.min.css">
        <?php endif; ?>
        <?php if ($useAsset['fancybox']): ?>
        <link rel="stylesheet" href="vendor/fancybox/fancybox.css">
        <?php endif; ?>
        <?php if ($useAsset['croppie']): ?>
        <link rel="stylesheet" href="vendor/Croppie-2.6.4/croppie.css">
        <?php endif; ?>
        <?php if ($useAsset['apexcharts']): ?>
        <link rel="stylesheet" href="vendor/apexcharts/apexcharts.css">
        <?php endif; ?>
        <link rel="stylesheet" href="css/school-theme.css?v=<?= (int) @filemtime(__DIR__ . '/css/school-theme.css') ?>">
        <link rel="shortcut icon" href="<?= h($logo) ?>" type="image/x-icon">
        <noscript>
            <style>.school-preloader { display: none !important; }</style>
        </noscript>
        <?php include("function_header.php");?>
    </head>
    <body class="school-site">
        <div class="school-preloader" id="schoolPreloader">
            <div class="school-preloader-card">
                <div class="school-preloader-emblem">
                    <span class="school-preloader-ring" aria-hidden="true"></span>
                    <img src="<?= h($logo) ?>" alt="" width="76" height="76">
                </div>
                <p class="school-preloader-name"><?= h($school['school_name_bn']) ?></p>
                <p class="school-preloader-en"><?= h($school['school_name_en']) ?></p>
                <div class="school-preloader-bar" role="progressbar" aria-label="পেজ লোড হচ্ছে"><span></span></div>
            </div>
        </div>
        <script>
        (function () {
            var root = document.documentElement;
            var loader = document.getElementById('schoolPreloader');
            if (!loader) {
                return;
            }
            root.classList.add('school-loading');
            var hidden = false;
            function hideLoader() {
                if (hidden) {
                    return;
                }
                hidden = true;
                root.classList.remove('school-loading');
                loader.classList.add('is-hidden');
                window.setTimeout(function () {
                    loader.style.display = 'none';
                }, 500);
            }
            window.addEventListener('load', function () {
                window.setTimeout(hideLoader, 150);
            });
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    hideLoader();
                }
            });
            // Never leave the overlay up if an asset stalls.
            window.setTimeout(hideLoader, 5000);
        })();
        </script>
        <div class="school-topbar py-1">
            <div class="container-md d-flex justify-content-between flex-wrap gap-2">
                <span>EIIN: <?= h($school['eiin']) ?> | MPO: <?= h($school['mpo_code']) ?></span>
                <span class="d-flex align-items-center flex-wrap gap-2 gap-sm-3">
                    <span>
                        <i class="fa-solid fa-phone" aria-hidden="true"></i>
                        <a href="tel:<?= h(preg_replace('/\s+/', '', (string) $school['mobile'])) ?>"><?= h($school['mobile']) ?></a>
                    </span>
                    <a class="school-topbar-login" href="<?= h(adminUrl('examples/login-v2.php')) ?>">
                        <i class="fa-solid fa-user-tie" aria-hidden="true"></i> Staff Login
                    </a>
                </span>
            </div>
        </div>
        <div class="school-shell">
                <header class="school-brand">
                    <div class="school-brand-atmosphere" aria-hidden="true"></div>
                    <div class="row align-items-center m-0 school-brand-inner">
                    <div class="col-sm-2 d-flex justify-content-center">
                        <a href="index" class="school-logo-link">
                            <img class="img-fluid school-logo" src="<?= h($logo) ?>" alt="<?= h($school['school_name_en']) ?>">
                        </a>
                    </div>
                    <div class="col-sm-10 text-center text-sm-start">
                        <p class="school-brand-eyebrow">স্থাপিত <?= h($school['established']) ?></p>
                        <h1 class="school-brand-title"><?= h($school['school_name_bn']) ?></h1>
                        <div class="school-brand-en"><?= h($school['school_name_en']) ?></div>
                        <div class="meta"><?= h(schoolAddressWithoutPostOffice((string) $school['address'])) ?></div>
                    </div>
                    </div>
                </header>
