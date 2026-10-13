<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Invoice</title>

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
    <meta name="title" content="AdminLTE 4 | Invoice" />
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
  <body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
      <?php $basePath = '../'; include __DIR__ . '/../includes/navbar.php'; ?>
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="row">
              <div class="col-sm-6">
                <h1 class="mb-0 fs-3">Invoice</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Invoice</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <!-- Action bar (hidden on print) -->
            <div class="d-flex justify-content-end gap-2 mb-3 d-print-none">
              <button class="btn btn-outline-secondary" onclick="window.print()" type="button">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
              </button>
              <a href="#" class="btn btn-outline-secondary">
                <i class="bi bi-download me-1" aria-hidden="true"></i>PDF
              </a>
              <a href="#" class="btn btn-primary">
                <i class="bi bi-send me-1" aria-hidden="true"></i>Send invoice
              </a>
            </div>

            <div class="card">
              <div class="card-body p-4 p-md-5">
                <!-- Header -->
                <div class="row mb-4">
                  <div class="col-sm-6">
                    <h2 class="h4 mb-0 text-primary fw-semibold">AdminLTE, Inc.</h2>
                    <p class="text-secondary mb-0 small">
                      795 Folsom Ave, Suite 600<br />
                      San Francisco, CA 94107<br />
                      billing@example.com
                    </p>
                  </div>
                  <div class="col-sm-6 text-sm-end">
                    <h1 class="h2 mb-1">Invoice</h1>
                    <p class="text-secondary mb-0">
                      <span class="fw-semibold">#</span>INV-2026-00428
                    </p>
                    <span class="badge text-bg-success mt-1">Paid</span>
                  </div>
                </div>

                <!-- Billing details -->
                <div class="row mb-4">
                  <div class="col-sm-6">
                    <p class="text-secondary small mb-1">Billed to</p>
                    <p class="mb-0 fw-semibold">Acme Corporation</p>
                    <p class="text-secondary small mb-0">
                      Attn: Jane Doe<br />
                      1234 Market Street<br />
                      San Francisco, CA 94103
                    </p>
                  </div>
                  <div class="col-sm-6 text-sm-end">
                    <p class="text-secondary small mb-1">Issue date</p>
                    <p class="mb-2">May 18, 2026</p>
                    <p class="text-secondary small mb-1">Due date</p>
                    <p class="mb-0">June 1, 2026</p>
                  </div>
                </div>

                <!-- Items -->
                <div class="table-responsive mb-3">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th class="border-top-0">Description</th>
                        <th class="border-top-0 text-end" style="width: 6rem">Qty</th>
                        <th class="border-top-0 text-end" style="width: 9rem">Unit price</th>
                        <th class="border-top-0 text-end" style="width: 9rem">Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <p class="mb-0 fw-semibold">Pro plan subscription</p>
                          <small class="text-secondary">May 18 - Jun 18, 2026</small>
                        </td>
                        <td class="text-end">1</td>
                        <td class="text-end">$29.00</td>
                        <td class="text-end">$29.00</td>
                      </tr>
                      <tr>
                        <td>
                          <p class="mb-0 fw-semibold">Additional seats</p>
                          <small class="text-secondary">Pro-rated for current period</small>
                        </td>
                        <td class="text-end">3</td>
                        <td class="text-end">$12.50</td>
                        <td class="text-end">$37.50</td>
                      </tr>
                      <tr>
                        <td>
                          <p class="mb-0 fw-semibold">SMS notifications add-on</p>
                          <small class="text-secondary">1,000 messages</small>
                        </td>
                        <td class="text-end">1</td>
                        <td class="text-end">$5.00</td>
                        <td class="text-end">$5.00</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Totals -->
                <div class="row justify-content-end">
                  <div class="col-md-5 col-lg-4">
                    <dl class="row mb-0">
                      <dt class="col-7 text-secondary fw-normal">Subtotal</dt>
                      <dd class="col-5 text-end mb-2">$71.50</dd>
                      <dt class="col-7 text-secondary fw-normal">Tax (8.25%)</dt>
                      <dd class="col-5 text-end mb-2">$5.90</dd>
                      <dt class="col-7 fw-semibold border-top pt-2">Total due</dt>
                      <dd class="col-5 text-end fw-semibold border-top pt-2 mb-0">$77.40 USD</dd>
                    </dl>
                  </div>
                </div>

                <!-- Footer note -->
                <hr class="my-4" />
                <p class="text-secondary small mb-0">
                  Thanks for your business. Payment is due within 14 days. If you have any questions
                  about this invoice, please contact
                  <a href="mailto:billing@example.com">billing@example.com</a>.
                </p>
              </div>
            </div>
          </div>
        </div>
      </main>
      <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
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
  </body>
</html>
