<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Pricing</title>

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
    <meta name="title" content="AdminLTE 4 | Pricing" />
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
                <h1 class="mb-0 fs-3">Pricing</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Pricing</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <!-- Headline -->
            <div class="text-center mb-4">
              <h2 class="mb-2">Pick the plan that fits your team</h2>
              <p class="text-secondary mb-0">
                Simple, transparent pricing. No hidden fees. Cancel any time.
              </p>
            </div>

            <!-- Billing toggle -->
            <div class="d-flex justify-content-center mb-4">
              <div class="btn-group" role="group" aria-label="Billing period">
                <input type="radio" class="btn-check" name="billing" id="billing-monthly" checked />
                <label class="btn btn-outline-primary" for="billing-monthly">Monthly</label>
                <input type="radio" class="btn-check" name="billing" id="billing-yearly" />
                <label class="btn btn-outline-primary" for="billing-yearly">
                  Yearly <span class="badge text-bg-success ms-1">Save 20%</span>
                </label>
              </div>
            </div>

            <!-- Plans -->
            <div class="row g-4 row-cols-1 row-cols-md-3 mb-4">
              <!-- Starter -->
              <div class="col">
                <div class="card h-100">
                  <div class="card-body p-4">
                    <h5 class="fw-semibold">Starter</h5>
                    <p class="text-secondary small mb-3">For individuals getting started.</p>
                    <div class="mb-3">
                      <span class="display-5 fw-bold">$0</span>
                      <span class="text-secondary">/mo</span>
                    </div>
                    <a href="#" class="btn btn-outline-primary w-100 mb-3">Get started</a>
                    <ul class="list-unstyled small mb-0">
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Up to 3 projects
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Community support
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        1 GB storage
                      </li>
                      <li class="text-secondary">
                        <i class="bi bi-dash-circle me-2" aria-hidden="true"></i>
                        Advanced analytics
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
              <!-- Pro (highlighted) -->
              <div class="col">
                <div class="card h-100 border-primary shadow-sm position-relative">
                  <span
                    class="badge text-bg-primary position-absolute top-0 start-50 translate-middle"
                    >Most popular</span
                  >
                  <div class="card-body p-4">
                    <h5 class="fw-semibold">Pro</h5>
                    <p class="text-secondary small mb-3">For growing teams that need more.</p>
                    <div class="mb-3">
                      <span class="display-5 fw-bold">$29</span>
                      <span class="text-secondary">/mo</span>
                    </div>
                    <a href="#" class="btn btn-primary w-100 mb-3">Start free trial</a>
                    <ul class="list-unstyled small mb-0">
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Unlimited projects
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Priority email support
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        100 GB storage
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Advanced analytics
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
              <!-- Enterprise -->
              <div class="col">
                <div class="card h-100">
                  <div class="card-body p-4">
                    <h5 class="fw-semibold">Enterprise</h5>
                    <p class="text-secondary small mb-3">For organizations with custom needs.</p>
                    <div class="mb-3">
                      <span class="display-5 fw-bold">Custom</span>
                    </div>
                    <a href="#" class="btn btn-outline-primary w-100 mb-3">Contact sales</a>
                    <ul class="list-unstyled small mb-0">
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Everything in Pro
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        SSO &amp; SCIM
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Dedicated account manager
                      </li>
                      <li class="mb-2">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                        Custom SLA
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>

            <!-- Feature comparison table -->
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Compare features</h3>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table mb-0 align-middle">
                    <thead>
                      <tr>
                        <th>Feature</th>
                        <th class="text-center">Starter</th>
                        <th class="text-center">Pro</th>
                        <th class="text-center">Enterprise</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>Projects</td>
                        <td class="text-center">3</td>
                        <td class="text-center">Unlimited</td>
                        <td class="text-center">Unlimited</td>
                      </tr>
                      <tr>
                        <td>Storage</td>
                        <td class="text-center">1 GB</td>
                        <td class="text-center">100 GB</td>
                        <td class="text-center">Custom</td>
                      </tr>
                      <tr>
                        <td>Team members</td>
                        <td class="text-center">1</td>
                        <td class="text-center">Up to 20</td>
                        <td class="text-center">Unlimited</td>
                      </tr>
                      <tr>
                        <td>Advanced analytics</td>
                        <td class="text-center">
                          <i class="bi bi-x-lg text-secondary" aria-hidden="true"></i>
                          <span class="visually-hidden">No</span>
                        </td>
                        <td class="text-center">
                          <i class="bi bi-check-lg text-success" aria-hidden="true"></i>
                          <span class="visually-hidden">Yes</span>
                        </td>
                        <td class="text-center">
                          <i class="bi bi-check-lg text-success" aria-hidden="true"></i>
                          <span class="visually-hidden">Yes</span>
                        </td>
                      </tr>
                      <tr>
                        <td>SSO &amp; SCIM</td>
                        <td class="text-center">
                          <i class="bi bi-x-lg text-secondary" aria-hidden="true"></i>
                          <span class="visually-hidden">No</span>
                        </td>
                        <td class="text-center">
                          <i class="bi bi-x-lg text-secondary" aria-hidden="true"></i>
                          <span class="visually-hidden">No</span>
                        </td>
                        <td class="text-center">
                          <i class="bi bi-check-lg text-success" aria-hidden="true"></i>
                          <span class="visually-hidden">Yes</span>
                        </td>
                      </tr>
                      <tr>
                        <td>Support</td>
                        <td class="text-center">Community</td>
                        <td class="text-center">Priority email</td>
                        <td class="text-center">Dedicated</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
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
