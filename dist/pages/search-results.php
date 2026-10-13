<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Search Results</title>

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
    <meta name="title" content="AdminLTE 4 | Search Results" />
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
      <!--begin::App Main-->
      <main class="app-main">
        <!--begin::App Content Header-->
        <div class="app-content-header">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-sm-6">
                <h1 class="mb-0 fs-3">Search Results</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Search Results</li>
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
            <!--begin::Row-->
            <div class="row g-4">
              <!--begin::Results-->
              <div class="col-lg-8">
                <!--begin::Search Form-->
                <form class="mb-3" role="search" action="#">
                  <label for="results-query" class="visually-hidden"> Search </label>
                  <div class="input-group">
                    <input
                      type="search"
                      id="results-query"
                      class="form-control form-control-lg"
                      value="invoice"
                      placeholder="Search pages, users and files"
                    />
                    <button class="btn btn-primary" type="submit">
                      <i class="bi bi-search me-1" aria-hidden="true"></i>
                      Search
                    </button>
                  </div>
                </form>
                <!--end::Search Form-->

                <p class="text-body-secondary" aria-live="polite">
                  <strong>128</strong> results for <strong>&ldquo;invoice&rdquo;</strong> &middot;
                  0.21 seconds
                </p>

                <!--begin::Result List-->
                <div class="card">
                  <!--begin::Type Tabs-->
                  <div class="card-header p-0 pt-1">
                    <ul class="nav nav-tabs" role="tablist">
                      <li class="nav-item" role="presentation">
                        <button
                          class="nav-link active"
                          type="button"
                          role="tab"
                          aria-selected="true"
                        >
                          All
                          <span class="badge text-bg-secondary ms-1"> 128 </span>
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" role="tab" aria-selected="false">
                          Pages
                          <span class="badge text-bg-secondary ms-1"> 64 </span>
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" role="tab" aria-selected="false">
                          Users
                          <span class="badge text-bg-secondary ms-1"> 41 </span>
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" role="tab" aria-selected="false">
                          Files
                          <span class="badge text-bg-secondary ms-1"> 18 </span>
                        </button>
                      </li>
                      <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" role="tab" aria-selected="false">
                          Projects
                          <span class="badge text-bg-secondary ms-1"> 5 </span>
                        </button>
                      </li>
                    </ul>
                  </div>
                  <!--end::Type Tabs-->
                  <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                      <li class="list-group-item py-3">
                        <div class="d-flex gap-3">
                          <span
                            class="badge text-bg-primary flex-shrink-0 d-flex align-items-center"
                            style="height: 2rem; width: 2rem; justify-content: center"
                          >
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                          </span>
                          <div class="flex-grow-1 overflow-hidden">
                            <h2 class="fs-5 mb-1">
                              <a
                                href="../pages/invoice.php"
                                class="link-body-emphasis link-underline-opacity-0 link-underline-opacity-100-hover"
                              >
                                Invoice #A-1042
                              </a>
                            </h2>
                            <p class="fs-7 text-success mb-1 text-truncate">/pages/invoice.php</p>
                            <p class="mb-1">
                              Issued 2 August 2026 to Colorlib Ltd. Contains 4 line items totalling
                              $2,310.00, due in 14 days.
                            </p>
                            <p class="fs-7 text-secondary mb-0">
                              <span class="badge text-bg-light me-1"> Page </span>
                              Updated 3 hours ago
                            </p>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item py-3">
                        <div class="d-flex gap-3">
                          <span
                            class="badge text-bg-primary flex-shrink-0 d-flex align-items-center"
                            style="height: 2rem; width: 2rem; justify-content: center"
                          >
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                          </span>
                          <div class="flex-grow-1 overflow-hidden">
                            <h2 class="fs-5 mb-1">
                              <a
                                href="../pages/settings.php"
                                class="link-body-emphasis link-underline-opacity-0 link-underline-opacity-100-hover"
                              >
                                Invoice template settings
                              </a>
                            </h2>
                            <p class="fs-7 text-success mb-1 text-truncate">/pages/settings.php</p>
                            <p class="mb-1">
                              Configure the default invoice footer, tax rate and payment terms
                              applied to every new invoice.
                            </p>
                            <p class="fs-7 text-secondary mb-0">
                              <span class="badge text-bg-light me-1"> Page </span>
                              Updated 5 days ago
                            </p>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item py-3">
                        <div class="d-flex gap-3">
                          <span
                            class="badge text-bg-info flex-shrink-0 d-flex align-items-center"
                            style="height: 2rem; width: 2rem; justify-content: center"
                          >
                            <i class="bi bi-person" aria-hidden="true"></i>
                          </span>
                          <div class="flex-grow-1 overflow-hidden">
                            <h2 class="fs-5 mb-1">
                              <a
                                href="../pages/profile.php"
                                class="link-body-emphasis link-underline-opacity-0 link-underline-opacity-100-hover"
                              >
                                Alexander Pierce
                              </a>
                            </h2>
                            <p class="fs-7 text-success mb-1 text-truncate">/pages/profile.php</p>
                            <p class="mb-1">
                              Billing administrator · Raised 18 invoices this quarter ·
                              alexander.pierce@example.com
                            </p>
                            <p class="fs-7 text-secondary mb-0">
                              <span class="badge text-bg-light me-1"> User </span>
                              Active 12 minutes ago
                            </p>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item py-3">
                        <div class="d-flex gap-3">
                          <span
                            class="badge text-bg-secondary flex-shrink-0 d-flex align-items-center"
                            style="height: 2rem; width: 2rem; justify-content: center"
                          >
                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                          </span>
                          <div class="flex-grow-1 overflow-hidden">
                            <h2 class="fs-5 mb-1">
                              <a
                                href="../pages/file-manager.php"
                                class="link-body-emphasis link-underline-opacity-0 link-underline-opacity-100-hover"
                              >
                                invoice-archive-2025.zip
                              </a>
                            </h2>
                            <p class="fs-7 text-success mb-1 text-truncate">
                              /pages/file-manager.php
                            </p>
                            <p class="mb-1">
                              Archive · 48.2 MB · Uploaded by Nadia Carmichael to Finance / Archive.
                            </p>
                            <p class="fs-7 text-secondary mb-0">
                              <span class="badge text-bg-light me-1"> File </span>
                              Uploaded 12 Jan 2026
                            </p>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item py-3">
                        <div class="d-flex gap-3">
                          <span
                            class="badge text-bg-success flex-shrink-0 d-flex align-items-center"
                            style="height: 2rem; width: 2rem; justify-content: center"
                          >
                            <i class="bi bi-kanban" aria-hidden="true"></i>
                          </span>
                          <div class="flex-grow-1 overflow-hidden">
                            <h2 class="fs-5 mb-1">
                              <a
                                href="../pages/projects.php"
                                class="link-body-emphasis link-underline-opacity-0 link-underline-opacity-100-hover"
                              >
                                Invoicing rebuild
                              </a>
                            </h2>
                            <p class="fs-7 text-success mb-1 text-truncate">/pages/projects.php</p>
                            <p class="mb-1">
                              6 of 11 tasks complete. Blocked on the payment-provider migration;
                              next milestone 30 September.
                            </p>
                            <p class="fs-7 text-secondary mb-0">
                              <span class="badge text-bg-light me-1"> Project </span>
                              3 open tasks
                            </p>
                          </div>
                        </div>
                      </li>
                    </ul>
                  </div>
                </div>
                <!--end::Result List-->

                <!--begin::Pagination-->
                <nav aria-label="Search results pages" class="mt-3">
                  <ul class="pagination justify-content-center mb-0">
                    <li class="page-item disabled">
                      <a class="page-link" href="#" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                      </a>
                    </li>
                    <li class="page-item active" aria-current="page">
                      <a class="page-link" href="#">1</a>
                    </li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item">
                      <a class="page-link" href="#" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                      </a>
                    </li>
                  </ul>
                </nav>
                <!--end::Pagination-->
              </div>
              <!--end::Results-->
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
            </div>
            <!--end::Row-->
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
