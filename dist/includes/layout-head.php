<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$mobileAdminDarkTheme = !empty($_SESSION['mobile_admin_dark_theme']);

if (!isset($basePath)) {
    $basePath = '';
}
$pageTitle = $pageTitle ?? 'School Admin';
$panelSettings = (isset($settings) && is_array($settings)) ? $settings : schoolSettings(getDatabaseConnection());
$panelFavicon = schoolFileUrl((string) ($panelSettings['logo'] ?? ''), '', publicSiteUrl('image/school_logo.png'));
?>
<!doctype html>
<html lang="bn">
  <head>
    <meta charset="utf-8" />
    <title><?= h($pageTitle) ?></title>
    <script>
      (() => {
        'use strict';
        const root = document.documentElement;
        if (root.getAttribute('data-lte-color-mode') === 'off') return;
        const STORAGE_KEY = 'lte-theme';
        const mobileAdminDarkTheme = <?= $mobileAdminDarkTheme ? 'true' : 'false' ?>;
        let stored = null;
        try { stored = localStorage.getItem(STORAGE_KEY); } catch {}
        const authored = root.getAttribute('data-bs-theme');
        let resolved = 'light';
        if (mobileAdminDarkTheme) resolved = 'dark';
        else if (stored === 'dark' || stored === 'light') resolved = stored;
        else if (authored === 'dark' || authored === 'light') resolved = authored;
        else if (globalThis.matchMedia('(prefers-color-scheme: dark)').matches) resolved = 'dark';
        if (mobileAdminDarkTheme) {
          try { localStorage.setItem(STORAGE_KEY, 'dark'); } catch {}
        }
        root.setAttribute('data-bs-theme', resolved);
        root.style.colorScheme = resolved;
        if (resolved !== authored) root.setAttribute('data-lte-theme-resolved', '');
      })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="csrf-token" content="<?= h(csrfToken()) ?>" />
    <link rel="icon" href="<?= h($panelFavicon) ?>" />
    <link rel="shortcut icon" href="<?= h($panelFavicon) ?>" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" media="print" onload="this.media='all'" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="<?= h($basePath) ?>css/adminlte.css" />
    <?php if (!empty($pageCssFiles) && is_array($pageCssFiles)): ?>
      <?php foreach ($pageCssFiles as $cssFile): ?>
    <link rel="stylesheet" href="<?= h((string) $cssFile) ?>" />
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($includeCropper)): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css" />
    <?php endif; ?>
    <link rel="stylesheet" href="<?= h($basePath) ?>css/responsive.css?v=<?= (int) @filemtime(__DIR__ . '/../css/responsive.css') ?>" />
    <style>
      .stat-card { border: 0; box-shadow: 0 .35rem 1rem rgba(15, 23, 42, .08); }
      .stat-card .icon-wrap { width: 3rem; height: 3rem; border-radius: .9rem; display: grid; place-items: center; font-size: 1.35rem; }
      .nav-school-brand { letter-spacing: .12em; white-space: nowrap; }
      .id-card { width: 323px; height: 204px; border: 1px solid #1e3a5f; border-radius: 10px; overflow: hidden; background: #fff; page-break-inside: avoid; }
      .certificate-sheet { width: 210mm; max-width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm; background: #fff; color: #000; box-sizing: border-box; }
      .teacher-card { transition: transform .2s ease, box-shadow .2s ease; }
      .teacher-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.12); }
      .teacher-card-image { width: 120px; height: 145px; object-fit: cover; }
      .sidebar-brand .brand-image { object-fit: contain; background: #fff; }
      .app-header [data-bmlhs-sidebar-toggle] { min-width: 48px; min-height: 48px; display: inline-flex; align-items: center; justify-content: center; }
      .app-header .sidebar-toggle-icon { font-size: 1.6rem; line-height: 1; }
      @media print {
        .app-sidebar, .app-header, .app-footer, .no-print { display: none !important; }
        .app-main { margin: 0 !important; padding: 0 !important; }
        .app-wrapper { min-height: auto; }
      }
    </style>
    <?php if (!empty($pageStyles)): ?>
    <style><?= $pageStyles ?></style>
    <?php endif; ?>
  </head>
  <body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
      <?php include __DIR__ . '/navbar.php'; ?>
      <?php include __DIR__ . '/sidebar.php'; ?>
      <main class="app-main">
