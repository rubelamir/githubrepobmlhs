<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Widgets - Social &amp; Post</title>

    <!--begin::Theme Init (prevents flash of incorrect theme on load, #6043)-->
    <script>
      (() => {
        'use strict';
        const root = document.documentElement;

        // Applications with their own theming opt out of AdminLTE's color mode
        // entirely, here as well as in the bundle.
        if (root.getAttribute('data-lte-color-mode') === 'off') {
          return;
        }

        const STORAGE_KEY = 'lte-theme';
        let stored = null;
        try {
          stored = localStorage.getItem(STORAGE_KEY);
        } catch {
          // localStorage may be unavailable (private mode, sandboxed iframe).
        }
        // Mirror the precedence in color-mode.ts: the visitor's stored choice
        // wins, then a theme this page declared itself, then the OS preference.
        const authored = root.getAttribute('data-bs-theme');
        let resolved = 'light';
        if (stored === 'dark' || stored === 'light') {
          resolved = stored;
        } else if (authored === 'dark' || authored === 'light') {
          resolved = authored;
        } else if (globalThis.matchMedia('(prefers-color-scheme: dark)').matches) {
          resolved = 'dark';
        }
        root.setAttribute('data-bs-theme', resolved);
        root.style.colorScheme = resolved;
        // Flag values computed here, so the bundle does not mistake them for a
        // theme the page declared and stop following the OS preference.
        if (resolved !== authored) {
          root.setAttribute('data-lte-theme-resolved', '');
        }
      })();
    </script>
    <!--end::Theme Init-->

    <!--begin::Accessibility Meta Tags-->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
    <meta name="color-scheme" content="light dark" />
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)" />
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)" />
    <!--end::Accessibility Meta Tags-->

    <!--begin::Primary Meta Tags-->
    <meta name="title" content="AdminLTE 4 | Widgets - Social &amp; Post" />
    <meta name="author" content="ColorlibHQ" />
    <meta
      name="description"
      content="AdminLTE is a free Bootstrap 5 admin dashboard template with almost 50 example pages, built with vanilla JS and designed with accessibility in mind."
    />
    <meta
      name="keywords"
      content="bootstrap 5, bootstrap, bootstrap 5 admin dashboard, bootstrap 5 dashboard, bootstrap 5 charts, bootstrap 5 calendar, bootstrap 5 datepicker, bootstrap 5 tables, bootstrap 5 datatable, vanilla js datatable, colorlibhq, colorlibhq dashboard, colorlibhq admin dashboard, accessible admin panel"
    />
    <!--end::Primary Meta Tags-->

    <!--begin::Accessibility Features-->
    <!-- Skip links will be dynamically added by accessibility.js -->
    <meta name="supported-color-schemes" content="light dark" />
    <link rel="preload" href="../css/adminlte.css" as="style" />
    <!--end::Accessibility Features-->

    <!--begin::Fonts-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css"
      integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q="
      crossorigin="anonymous"
      media="print"
      onload="this.media = 'all'"
    />
    <!--end::Fonts-->

    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(OverlayScrollbars)-->

    <!--begin::Third Party Plugin(Bootstrap Icons)-->
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      crossorigin="anonymous"
    />
    <!--end::Third Party Plugin(Bootstrap Icons)-->

    <!--begin::Required Plugin(AdminLTE)-->
    <link rel="stylesheet" href="../css/adminlte.css" />
    <!--end::Required Plugin(AdminLTE)-->
  </head>
  <!--end::Head-->
  <!--begin::Body-->
  <body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <!--begin::App Wrapper-->
    <div class="app-wrapper">
      <?php $basePath = '../'; include __DIR__ . '/../includes/navbar.php'; ?>
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
      <!--begin::App Main-->
      <main class="app-main">
        <!--begin::App Content Header-->
        <div class="app-content-header">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-sm-6">
                <h1 class="mb-0 fs-3">Social &amp; Post Widgets</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">
                      Social &amp; Post Widgets
                    </li>
                  </ol>
                </nav>
              </div>
            </div>
            <!--end::Row-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content Header-->
        <!--begin::App Content-->
        <div class="app-content">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::User Widgets-->
            <h5 class="mb-3">User widgets</h5>
            <!--begin::Row-->
            <div class="row g-4">
              <!--begin::Col-->
              <div class="col-lg-6">
                <!--begin::Widget User-->
                <div class="card widget-user mb-0">
                  <!--begin::Widget User Header-->
                  <div class="widget-user-header text-bg-primary">
                    <h3 class="widget-user-username">Elizabeth Pierce</h3>
                    <h5 class="widget-user-desc">Web Designer</h5>
                  </div>
                  <!--end::Widget User Header-->
                  <div class="widget-user-image">
                    <img
                      class="rounded-circle shadow"
                      src="../assets/img/user3-128x128.jpg"
                      alt=""
                    />
                  </div>
                  <!--begin::Card Footer-->
                  <div class="card-footer">
                    <!--begin::Row-->
                    <div class="row">
                      <div class="col-sm-4 border-end">
                        <div class="description-block">
                          <p class="description-header">3,200</p>
                          <span class="description-text">Sales</span>
                        </div>
                      </div>
                      <div class="col-sm-4 border-end">
                        <div class="description-block">
                          <p class="description-header">13,000</p>
                          <span class="description-text">Followers</span>
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="description-block">
                          <p class="description-header">35</p>
                          <span class="description-text">Products</span>
                        </div>
                      </div>
                    </div>
                    <!--end::Row-->
                  </div>
                  <!--end::Card Footer-->
                </div>
                <!--end::Widget User-->
              </div>
              <!--end::Col-->
              <!--begin::Col-->
              <div class="col-lg-6">
                <!--begin::Widget User 2-->
                <div class="card widget-user-2 mb-0">
                  <!--begin::Widget User Header-->
                  <div class="widget-user-header text-bg-warning">
                    <div class="widget-user-image">
                      <img
                        class="rounded-circle shadow"
                        src="../assets/img/user7-128x128.jpg"
                        alt=""
                      />
                    </div>
                    <div>
                      <h3 class="widget-user-username">Nadia Carmichael</h3>
                      <h5 class="widget-user-desc">Lead Developer</h5>
                    </div>
                  </div>
                  <!--end::Widget User Header-->
                  <!--begin::Card Footer-->
                  <div class="card-footer p-0">
                    <ul class="nav flex-column">
                      <li class="nav-item">
                        <a href="#" class="nav-link link-body-emphasis">
                          Projects
                          <span class="float-end badge text-bg-primary">31</span>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#" class="nav-link link-body-emphasis">
                          Tasks
                          <span class="float-end badge text-bg-info">5</span>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#" class="nav-link link-body-emphasis">
                          Completed Projects
                          <span class="float-end badge text-bg-success">12</span>
                        </a>
                      </li>
                      <li class="nav-item">
                        <a href="#" class="nav-link link-body-emphasis">
                          Followers
                          <span class="float-end badge text-bg-danger">842</span>
                        </a>
                      </li>
                    </ul>
                  </div>
                  <!--end::Card Footer-->
                </div>
                <!--end::Widget User 2-->
              </div>
              <!--end::Col-->
            </div>
            <!--end::Row-->
            <!--end::User Widgets-->

            <!--begin::Social & Post-->
            <h5 class="mb-3 mt-4">Social feed</h5>
            <!--begin::Row-->
            <div class="row g-4">
              <!--begin::Col-->
              <div class="col-lg-6">
                <!--begin::Social Widget-->
                <div class="card mb-0">
                  <!--begin::Card Header-->
                  <div class="card-header">
                    <div class="user-block">
                      <img class="rounded-circle" src="../assets/img/user1-128x128.jpg" alt="" />
                      <span class="username">
                        <a href="#">Jonathan Burke Jr.</a>
                      </span>
                      <span class="description">Shared publicly &middot; 7:30 PM today</span>
                    </div>
                    <div class="card-tools">
                      <button
                        type="button"
                        class="btn btn-tool"
                        data-lte-toggle="card-collapse"
                        aria-label="Collapse card"
                      >
                        <i data-lte-icon="expand" class="bi bi-plus-lg"></i>
                        <i data-lte-icon="collapse" class="bi bi-dash-lg"></i>
                      </button>
                    </div>
                  </div>
                  <!--end::Card Header-->
                  <!--begin::Card Body-->
                  <div class="card-body">
                    <img class="img-fluid rounded mb-3" src="../assets/img/photo1.png" alt="" />
                    <p>I took this photo this morning. What do you guys think?</p>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1">
                      <i class="bi bi-share me-1" aria-hidden="true"></i> Share
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary">
                      <i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i> Like
                    </button>
                    <span class="float-end text-secondary">45 likes &middot; 2 comments</span>
                  </div>
                  <!--end::Card Body-->
                  <!--begin::Card Footer-->
                  <div class="card-footer">
                    <div class="user-block user-block-sm mb-3">
                      <img class="rounded-circle" src="../assets/img/user4-128x128.jpg" alt="" />
                      <span class="username"><a href="#">Maria Gonzales</a></span>
                      <span class="description">Posted 5 minutes ago</span>
                      <span class="comment">
                        It is a long established fact that a reader will be distracted by the
                        readable content of a page.
                      </span>
                    </div>
                    <div class="user-block user-block-sm">
                      <img class="rounded-circle" src="../assets/img/user5-128x128.jpg" alt="" />
                      <span class="username"><a href="#">Nora Havisham</a></span>
                      <span class="description">Posted 27 minutes ago</span>
                      <span class="comment">
                        The point of using Lorem Ipsum is that it has a more-or-less normal
                        distribution of letters.
                      </span>
                    </div>
                    <!--begin::Comment Form-->
                    <form class="mt-3">
                      <label for="social-comment" class="visually-hidden"> Write a comment </label>
                      <input
                        type="text"
                        id="social-comment"
                        class="form-control form-control-sm"
                        placeholder="Type a comment"
                      />
                    </form>
                    <!--end::Comment Form-->
                  </div>
                  <!--end::Card Footer-->
                </div>
                <!--end::Social Widget-->
              </div>
              <!--end::Col-->
              <!--begin::Col-->
              <div class="col-lg-6">
                <!--begin::Post Widget-->
                <div class="card mb-0">
                  <!--begin::Card Header-->
                  <div class="card-header">
                    <div class="card-title">Latest activity</div>
                  </div>
                  <!--end::Card Header-->
                  <!--begin::Card Body-->
                  <div class="card-body">
                    <!--begin::Post-->
                    <div class="post">
                      <div class="user-block">
                        <img class="rounded-circle" src="../assets/img/user1-128x128.jpg" alt="" />
                        <span class="username"><a href="#">Jonathan Burke Jr.</a></span>
                        <span class="description">Shared publicly &middot; 7:30 PM today</span>
                      </div>
                      <p>
                        Lorem ipsum represents a long-held tradition for designers, typographers and
                        the like.
                      </p>
                      <p class="mb-0">
                        <a href="#" class="link-secondary me-2">
                          <i class="bi bi-share me-1" aria-hidden="true"></i> Share
                        </a>
                        <a href="#" class="link-secondary">
                          <i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i> Like
                        </a>
                      </p>
                    </div>
                    <!--end::Post-->
                    <!--begin::Post-->
                    <div class="post">
                      <div class="user-block">
                        <img class="rounded-circle" src="../assets/img/user7-128x128.jpg" alt="" />
                        <span class="username"><a href="#">Sarah Ross</a></span>
                        <span class="description">Sent you a message &middot; 3 days ago</span>
                      </div>
                      <p>
                        Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium
                        doloremque laudantium.
                      </p>
                      <p class="mb-0">
                        <a href="#" class="link-secondary me-2">
                          <i class="bi bi-share me-1" aria-hidden="true"></i> Share
                        </a>
                        <a href="#" class="link-secondary">
                          <i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i> Like
                        </a>
                      </p>
                    </div>
                    <!--end::Post-->
                    <!--begin::Post-->
                    <div class="post">
                      <div class="user-block">
                        <img class="rounded-circle" src="../assets/img/user6-128x128.jpg" alt="" />
                        <span class="username"><a href="#">Adam Jones</a></span>
                        <span class="description">Posted 5 photos &middot; 1 week ago</span>
                      </div>
                      <!--begin::Row-->
                      <div class="row g-2 mb-3">
                        <div class="col-4">
                          <img class="img-fluid rounded" src="../assets/img/photo2.png" alt="" />
                        </div>
                        <div class="col-4">
                          <img class="img-fluid rounded" src="../assets/img/photo3.jpg" alt="" />
                        </div>
                        <div class="col-4">
                          <img class="img-fluid rounded" src="../assets/img/photo4.jpg" alt="" />
                        </div>
                      </div>
                      <!--end::Row-->
                      <p class="mb-0">
                        <a href="#" class="link-secondary me-2">
                          <i class="bi bi-share me-1" aria-hidden="true"></i> Share
                        </a>
                        <a href="#" class="link-secondary">
                          <i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i> Like
                        </a>
                      </p>
                    </div>
                    <!--end::Post-->
                  </div>
                  <!--end::Card Body-->
                </div>
                <!--end::Post Widget-->
              </div>
              <!--end::Col-->
            </div>
            <!--end::Row-->
            <!--end::Social & Post-->
          </div>
          <!--end::Container-->
        </div>
        <!--end::App Content-->
      </main>
      <!--end::App Main-->
      <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    <!--end::App Wrapper-->
    <!--begin::Script-->
    <!--begin::Third Party Plugin(OverlayScrollbars)-->
    <script
      src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Third Party Plugin(OverlayScrollbars)--><!--begin::Required Plugin(popperjs for Bootstrap 5)-->
    <script
      src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Required Plugin(popperjs for Bootstrap 5)--><!--begin::Required Plugin(Bootstrap 5)-->
    <script
      src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js"
      crossorigin="anonymous"
    ></script>
    <!--end::Required Plugin(Bootstrap 5)--><!--begin::Required Plugin(AdminLTE)-->
    <script src="../js/adminlte.js"></script>
    <!--end::Required Plugin(AdminLTE)-->
    <!--begin::OverlayScrollbars Configure-->
    <script>
      const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
      const Default = {
        scrollbarTheme: 'os-theme-light',
        scrollbarAutoHide: 'leave',
        scrollbarClickScroll: true,
      };
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);

        // Disable OverlayScrollbars on mobile devices to prevent touch interference
        const isMobile = window.innerWidth <= 992;

        if (
          sidebarWrapper &&
          OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined &&
          !isMobile
        ) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: {
              theme: Default.scrollbarTheme,
              autoHide: Default.scrollbarAutoHide,
              clickScroll: Default.scrollbarClickScroll,
            },
          });
        }
      });
    </script>
    <!--end::OverlayScrollbars Configure-->
    <!--begin::Charts follow the colour mode-->
    <script>
      // ApexCharts draws light-theme tooltips and axis text unless told otherwise,
      // which is unreadable in dark mode (#6105). Give it the page's colour mode as
      // a global default before any chart is created — this runs before the chart
      // pages' own scripts — and keep every chart that has a `chart.id` in step
      // when the mode changes (ColorMode, the OS in auto mode, or your own code).
      (() => {
        'use strict';
        const mode = () =>
          document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
        // `Apex` is ApexCharts' global-options object; it must exist before the library loads.
        // theme.mode also sets a dark chart background — keep the card's instead.
        // eslint-disable-next-line unicorn/no-global-object-property-assignment
        globalThis.Apex ||= {};
        const apex = globalThis.Apex;
        apex.theme = { mode: mode() };
        apex.chart = Object.assign(apex.chart || {}, { background: 'transparent' });
        new MutationObserver(() => {
          const next = mode();
          apex.theme = { mode: next };
          const instances = apex._chartInstances || [];
          for (const { chart } of instances) {
            chart.updateOptions({ theme: { mode: next } }, false, false);
          }
        }).observe(document.documentElement, {
          attributes: true,
          attributeFilter: ['data-bs-theme'],
        });
      })();
    </script>
    <!--end::Charts follow the colour mode-->

    <!--begin::Color Mode Toggle-->
    <!-- The light/dark/auto switcher ships in adminlte.js as the ColorMode
     module (since 4.1) — no page script needed. Only the no-flash snippet
     in <head> stays inline, because it must run before first paint. -->
    <!--end::Color Mode Toggle-->

    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
