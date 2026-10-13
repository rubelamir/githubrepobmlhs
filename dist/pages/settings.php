<?php
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Settings</title>

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
    <meta name="title" content="AdminLTE 4 | Settings" />
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
                <h1 class="mb-0 fs-3">Settings</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Settings</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="row g-3">
              <!-- Left rail -->
              <div class="col-md-3">
                <div
                  class="list-group list-group-flush nav nav-pills flex-column"
                  id="settings-nav"
                  role="tablist"
                >
                  <a
                    href="#account"
                    class="list-group-item list-group-item-action active"
                    data-bs-toggle="pill"
                    role="tab"
                    aria-selected="true"
                  >
                    <i class="bi bi-person me-2" aria-hidden="true"></i>Account
                  </a>
                  <a
                    href="#notifications"
                    class="list-group-item list-group-item-action"
                    data-bs-toggle="pill"
                    role="tab"
                  >
                    <i class="bi bi-bell me-2" aria-hidden="true"></i>Notifications
                  </a>
                  <a
                    href="#security"
                    class="list-group-item list-group-item-action"
                    data-bs-toggle="pill"
                    role="tab"
                  >
                    <i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Security
                  </a>
                  <a
                    href="#billing"
                    class="list-group-item list-group-item-action"
                    data-bs-toggle="pill"
                    role="tab"
                  >
                    <i class="bi bi-credit-card me-2" aria-hidden="true"></i>Billing
                  </a>
                  <a
                    href="#danger"
                    class="list-group-item list-group-item-action text-danger"
                    data-bs-toggle="pill"
                    role="tab"
                  >
                    <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>
                    Danger zone
                  </a>
                </div>
              </div>

              <!-- Tab content -->
              <div class="col-md-9">
                <div class="tab-content">
                  <!-- Account -->
                  <div class="tab-pane fade show active" id="account" role="tabpanel">
                    <div class="card">
                      <div class="card-header">
                        <h3 class="card-title">Account</h3>
                      </div>
                      <div class="card-body">
                        <form class="row g-3">
                          <div class="col-md-6">
                            <label class="form-label" for="settings-name"> Full name </label>
                            <input
                              type="text"
                              class="form-control"
                              id="settings-name"
                              value="Jane Doe"
                            />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="settings-email"> Email </label>
                            <input
                              type="email"
                              class="form-control"
                              id="settings-email"
                              value="jane@example.com"
                            />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="settings-tz"> Time zone </label>
                            <select class="form-select" id="settings-tz">
                              <option>UTC</option>
                              <option selected>America/Los_Angeles</option>
                              <option>Europe/London</option>
                              <option>Asia/Tokyo</option>
                            </select>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="settings-lang"> Language </label>
                            <select class="form-select" id="settings-lang">
                              <option selected>English</option>
                              <option>Español</option>
                              <option>Français</option>
                              <option>Deutsch</option>
                            </select>
                          </div>
                          <div class="col-12">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>

                  <!-- Notifications -->
                  <div class="tab-pane fade" id="notifications" role="tabpanel">
                    <div class="card">
                      <div class="card-header">
                        <h3 class="card-title">Notifications</h3>
                      </div>
                      <div class="card-body">
                        <p class="text-secondary">Choose what to be notified about.</p>
                        <div
                          class="d-flex justify-content-between align-items-start py-2 border-bottom"
                        >
                          <div>
                            <p class="mb-0 fw-semibold">Product updates</p>
                            <small class="text-secondary">Major releases and changelogs</small>
                          </div>
                          <div class="form-check form-switch mb-0">
                            <input
                              class="form-check-input"
                              type="checkbox"
                              role="switch"
                              id="notif-0"
                              checked
                            />
                            <label class="visually-hidden" for="notif-0">
                              Toggle Product updates
                            </label>
                          </div>
                        </div>
                        <div
                          class="d-flex justify-content-between align-items-start py-2 border-bottom"
                        >
                          <div>
                            <p class="mb-0 fw-semibold">Security alerts</p>
                            <small class="text-secondary"
                              >Sign-in attempts and account changes</small
                            >
                          </div>
                          <div class="form-check form-switch mb-0">
                            <input
                              class="form-check-input"
                              type="checkbox"
                              role="switch"
                              id="notif-1"
                              checked
                            />
                            <label class="visually-hidden" for="notif-1">
                              Toggle Security alerts
                            </label>
                          </div>
                        </div>
                        <div
                          class="d-flex justify-content-between align-items-start py-2 border-bottom"
                        >
                          <div>
                            <p class="mb-0 fw-semibold">Weekly digest</p>
                            <small class="text-secondary"
                              >A summary of activity in your workspace</small
                            >
                          </div>
                          <div class="form-check form-switch mb-0">
                            <input
                              class="form-check-input"
                              type="checkbox"
                              role="switch"
                              id="notif-2"
                            />
                            <label class="visually-hidden" for="notif-2">
                              Toggle Weekly digest
                            </label>
                          </div>
                        </div>
                        <div
                          class="d-flex justify-content-between align-items-start py-2 border-bottom"
                        >
                          <div>
                            <p class="mb-0 fw-semibold">Mentions</p>
                            <small class="text-secondary">When a teammate @mentions you</small>
                          </div>
                          <div class="form-check form-switch mb-0">
                            <input
                              class="form-check-input"
                              type="checkbox"
                              role="switch"
                              id="notif-3"
                            />
                            <label class="visually-hidden" for="notif-3"> Toggle Mentions </label>
                          </div>
                        </div>
                        <button class="btn btn-primary mt-3">Save preferences</button>
                      </div>
                    </div>
                  </div>

                  <!-- Security -->
                  <div class="tab-pane fade" id="security" role="tabpanel">
                    <div class="card">
                      <div class="card-header">
                        <h3 class="card-title">Password</h3>
                      </div>
                      <div class="card-body">
                        <form class="row g-3">
                          <div class="col-md-12">
                            <label class="form-label" for="pwd-current"> Current password </label>
                            <input type="password" class="form-control" id="pwd-current" />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="pwd-new"> New password </label>
                            <input type="password" class="form-control" id="pwd-new" />
                          </div>
                          <div class="col-md-6">
                            <label class="form-label" for="pwd-confirm">
                              Confirm new password
                            </label>
                            <input type="password" class="form-control" id="pwd-confirm" />
                          </div>
                          <div class="col-12">
                            <button type="submit" class="btn btn-primary">Update password</button>
                          </div>
                        </form>
                      </div>
                    </div>
                    <div class="card mt-3">
                      <div class="card-header">
                        <h3 class="card-title">Two-factor authentication</h3>
                      </div>
                      <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                          <p class="mb-0 fw-semibold">Authenticator app</p>
                          <small class="text-secondary">
                            Use an authenticator app such as 1Password or Authy.
                          </small>
                        </div>
                        <button class="btn btn-outline-primary">Enable</button>
                      </div>
                    </div>
                  </div>

                  <!-- Billing -->
                  <div class="tab-pane fade" id="billing" role="tabpanel">
                    <div class="card">
                      <div class="card-header">
                        <h3 class="card-title">Current plan</h3>
                      </div>
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                          <div>
                            <p class="mb-0 fw-semibold">Pro plan</p>
                            <small class="text-secondary">
                              $29 / month &middot; Renews June 18, 2026
                            </small>
                          </div>
                          <a href="#" class="btn btn-outline-primary btn-sm"> Change plan </a>
                        </div>
                        <hr />
                        <p class="fw-semibold mb-2">Payment method</p>
                        <div class="d-flex justify-content-between align-items-center">
                          <div>
                            <i class="bi bi-credit-card-2-front me-2" aria-hidden="true"></i>
                            Visa ending in 4242
                          </div>
                          <a href="#" class="btn btn-link btn-sm">Update</a>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Danger zone -->
                  <div class="tab-pane fade" id="danger" role="tabpanel">
                    <div class="card border-danger">
                      <div class="card-header bg-danger-subtle">
                        <h3 class="card-title text-danger">Danger zone</h3>
                      </div>
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                          <div>
                            <p class="mb-0 fw-semibold">Export account data</p>
                            <small class="text-secondary">
                              Download a copy of all your data as a ZIP archive.
                            </small>
                          </div>
                          <button class="btn btn-outline-secondary">Export</button>
                        </div>
                        <hr />
                        <div class="d-flex justify-content-between align-items-start">
                          <div>
                            <p class="mb-0 fw-semibold text-danger">Delete account</p>
                            <small class="text-secondary">
                              This will permanently delete your account and all associated data.
                              This cannot be undone.
                            </small>
                          </div>
                          <button class="btn btn-danger">Delete account</button>
                        </div>
                      </div>
                    </div>
                  </div>
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
