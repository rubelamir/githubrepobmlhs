<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Ribbons</title>

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
    <meta name="title" content="AdminLTE 4 | Ribbons" />
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
                <h1 class="mb-0 fs-3">Ribbons</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Ribbons</li>
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
            <div class="callout callout-info mb-4">
              <p>
                A ribbon is pinned to the top corner of its nearest positioned ancestor. Cards are
                already positioned, so
                <code>.ribbon-wrapper</code> works inside one as-is — on any other element, add
                <code>.position-relative</code> to the parent.
              </p>
              <p class="mb-0">
                Keep labels short. The banner is clipped to the corner, so only about
                <code>64px</code> of it is readable at the default size — roughly six characters.
                Step up to <code>.ribbon-lg</code> or <code>.ribbon-xl</code> for longer words.
              </p>
            </div>

            <!--begin::Colors-->
            <h5 class="mb-3">Colors</h5>
            <!--begin::Row-->
            <div class="row g-4">
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-primary">New</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">primary</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-primary </code>
                  </div>
                </div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-secondary">Draft</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">secondary</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-secondary </code>
                  </div>
                </div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-success">Live</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">success</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-success </code>
                  </div>
                </div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-danger">Hot</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">danger</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-danger </code>
                  </div>
                </div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-warning">Beta</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">warning</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-warning </code>
                  </div>
                </div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-info">Tip</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">info</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-info </code>
                  </div>
                </div>
              </div>
              <div class="col-md-4 col-sm-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-dark">Pro</div>
                  </div>
                  <div class="card-body" style="min-height: 5rem">
                    <h6 class="card-title text-capitalize mb-0">dark</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis"> .ribbon.text-bg-dark </code>
                  </div>
                </div>
              </div>
            </div>
            <!--end::Row-->
            <!--end::Colors-->

            <!--begin::Sizes-->
            <h5 class="mb-3 mt-4">Sizes</h5>
            <!--begin::Row-->
            <div class="row g-4">
              <div class="col-lg-4">
                <div class="card mb-0">
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-primary">Default</div>
                  </div>
                  <div class="card-body" style="min-height: 9rem">
                    <h6 class="card-title mb-0">Default</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis">.ribbon-wrapper</code>
                  </div>
                </div>
              </div>
              <div class="col-lg-4">
                <div class="card mb-0">
                  <div class="ribbon-wrapper ribbon-lg">
                    <div class="ribbon text-bg-success">Large</div>
                  </div>
                  <div class="card-body" style="min-height: 9rem">
                    <h6 class="card-title mb-0">Large</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis">.ribbon-wrapper.ribbon-lg</code>
                  </div>
                </div>
              </div>
              <div class="col-lg-4">
                <div class="card mb-0">
                  <div class="ribbon-wrapper ribbon-xl">
                    <div class="ribbon text-bg-danger">X-Large</div>
                  </div>
                  <div class="card-body" style="min-height: 9rem">
                    <h6 class="card-title mb-0">Extra large</h6>
                  </div>
                  <div class="card-footer">
                    <code class="text-body-emphasis">.ribbon-wrapper.ribbon-xl</code>
                  </div>
                </div>
              </div>
            </div>
            <!--end::Row-->
            <!--end::Sizes-->

            <!--begin::Outside a card-->
            <h5 class="mb-3 mt-4">Outside a card</h5>
            <!--begin::Row-->
            <div class="row g-4">
              <div class="col-lg-6">
                <div
                  class="position-relative overflow-hidden rounded border bg-body p-4"
                  style="min-height: 10rem"
                >
                  <div class="ribbon-wrapper">
                    <div class="ribbon text-bg-warning">New</div>
                  </div>
                  <h6>Any positioned element</h6>
                  <p class="mb-0">
                    The wrapper only needs an ancestor with
                    <code>position: relative</code>. Add <code>.overflow-hidden</code> as well when
                    the parent is rounded, so the banner is clipped to the corner radius.
                  </p>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="card mb-0">
                  <div class="ribbon-wrapper ribbon-lg">
                    <div class="ribbon text-bg-info">
                      <a href="#">Linked</a>
                    </div>
                  </div>
                  <div class="card-body" style="min-height: 10rem">
                    <h6>Interactive ribbon</h6>
                    <p class="mb-0 pe-4">
                      A ribbon can wrap a link. It inherits whatever contrast colour the utility
                      picked, so the label stays readable in both colour modes.
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <!--end::Row-->
            <!--end::Outside a card-->
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
