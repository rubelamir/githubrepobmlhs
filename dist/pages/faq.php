<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | FAQ</title>

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
    <meta name="title" content="AdminLTE 4 | FAQ" />
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
                <h1 class="mb-0 fs-3">FAQ</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">FAQ</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <!-- Headline + search -->
            <div class="text-center mb-4">
              <h2 class="mb-2">How can we help?</h2>
              <p class="text-secondary mb-3">
                Search our knowledge base, or browse the topics below.
              </p>
              <form
                class="row g-2 justify-content-center"
                role="search"
                onsubmit="event.preventDefault()"
              >
                <div class="col-md-8 col-lg-6">
                  <div class="input-group input-group-lg">
                    <span class="input-group-text bg-body">
                      <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input
                      type="search"
                      class="form-control"
                      placeholder="Search the FAQ&hellip;"
                      aria-label="Search the FAQ"
                    />
                  </div>
                </div>
              </form>
            </div>

            <div class="row g-4">
              <div class="col-lg-12">
                <div class="card">
                  <div class="card-header d-flex align-items-center">
                    <i class="bi bi-rocket-takeoff fs-4 text-primary me-2" aria-hidden="true"></i>
                    <h3 class="card-title mb-0">Getting started</h3>
                  </div>
                  <div class="card-body">
                    <div class="accordion accordion-flush" id="faq-0">
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-0-0"
                            aria-expanded="false"
                            aria-controls="faq-0-0"
                          >
                            How do I create an account?
                          </button>
                        </h2>
                        <div
                          id="faq-0-0"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-0"
                        >
                          <div class="accordion-body text-secondary">
                            Click <strong>Sign up</strong> in the top-right corner, enter your email
                            and a strong password, then verify your email to activate your
                            workspace.
                          </div>
                        </div>
                      </div>
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-0-1"
                            aria-expanded="false"
                            aria-controls="faq-0-1"
                          >
                            Can I try the product before paying?
                          </button>
                        </h2>
                        <div
                          id="faq-0-1"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-0"
                        >
                          <div class="accordion-body text-secondary">
                            Yes. Every paid plan includes a 14-day free trial. No credit card is
                            required to start.
                          </div>
                        </div>
                      </div>
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-0-2"
                            aria-expanded="false"
                            aria-controls="faq-0-2"
                          >
                            How do I invite teammates?
                          </button>
                        </h2>
                        <div
                          id="faq-0-2"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-0"
                        >
                          <div class="accordion-body text-secondary">
                            From <strong>Settings &rarr; Team</strong>, click
                            <strong>Invite member</strong> and enter their email. They&rsquo;ll
                            receive an invitation link valid for seven days.
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-lg-12">
                <div class="card">
                  <div class="card-header d-flex align-items-center">
                    <i class="bi bi-credit-card fs-4 text-primary me-2" aria-hidden="true"></i>
                    <h3 class="card-title mb-0">Billing &amp; plans</h3>
                  </div>
                  <div class="card-body">
                    <div class="accordion accordion-flush" id="faq-1">
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-1-0"
                            aria-expanded="false"
                            aria-controls="faq-1-0"
                          >
                            Which payment methods do you accept?
                          </button>
                        </h2>
                        <div
                          id="faq-1-0"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-1"
                        >
                          <div class="accordion-body text-secondary">
                            We accept all major credit and debit cards. Annual Enterprise plans can
                            be paid by invoice.
                          </div>
                        </div>
                      </div>
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-1-1"
                            aria-expanded="false"
                            aria-controls="faq-1-1"
                          >
                            Can I change my plan later?
                          </button>
                        </h2>
                        <div
                          id="faq-1-1"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-1"
                        >
                          <div class="accordion-body text-secondary">
                            Yes. Upgrades take effect immediately and are pro-rated. Downgrades take
                            effect at the end of the current billing cycle.
                          </div>
                        </div>
                      </div>
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-1-2"
                            aria-expanded="false"
                            aria-controls="faq-1-2"
                          >
                            Do you offer refunds?
                          </button>
                        </h2>
                        <div
                          id="faq-1-2"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-1"
                        >
                          <div class="accordion-body text-secondary">
                            We offer a full refund within 30 days of your first paid invoice, no
                            questions asked.
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-lg-12">
                <div class="card">
                  <div class="card-header d-flex align-items-center">
                    <i class="bi bi-shield-lock fs-4 text-primary me-2" aria-hidden="true"></i>
                    <h3 class="card-title mb-0">Security &amp; privacy</h3>
                  </div>
                  <div class="card-body">
                    <div class="accordion accordion-flush" id="faq-2">
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-2-0"
                            aria-expanded="false"
                            aria-controls="faq-2-0"
                          >
                            Where is my data stored?
                          </button>
                        </h2>
                        <div
                          id="faq-2-0"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-2"
                        >
                          <div class="accordion-body text-secondary">
                            Data is stored in encrypted form in SOC 2-certified data centers. You
                            can choose your region (US or EU) when you set up your workspace.
                          </div>
                        </div>
                      </div>
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-2-1"
                            aria-expanded="false"
                            aria-controls="faq-2-1"
                          >
                            Do you support SSO?
                          </button>
                        </h2>
                        <div
                          id="faq-2-1"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-2"
                        >
                          <div class="accordion-body text-secondary">
                            Yes, on the Enterprise plan. We support SAML 2.0 and OIDC with providers
                            including Okta, Azure AD, and Google Workspace.
                          </div>
                        </div>
                      </div>
                      <div class="accordion-item">
                        <h2 class="accordion-header">
                          <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#faq-2-2"
                            aria-expanded="false"
                            aria-controls="faq-2-2"
                          >
                            How can I export or delete my data?
                          </button>
                        </h2>
                        <div
                          id="faq-2-2"
                          class="accordion-collapse collapse"
                          data-bs-parent="#faq-2"
                        >
                          <div class="accordion-body text-secondary">
                            You can export your data at any time from
                            <strong>Settings &rarr; Data</strong>. Deletion requests are honored
                            within 30 days per our DPA.
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Still need help? -->
            <div class="card mt-4">
              <div class="card-body text-center py-4">
                <i
                  class="bi bi-chat-dots text-primary"
                  style="font-size: 2.5rem"
                  aria-hidden="true"
                ></i>
                <h3 class="h5 mt-3 mb-2">Still need help?</h3>
                <p class="text-secondary mb-3">
                  Can&rsquo;t find what you&rsquo;re looking for? Our support team is happy to help.
                </p>
                <a href="mailto:support@example.com" class="btn btn-primary">
                  <i class="bi bi-envelope me-1" aria-hidden="true"></i>
                  Contact support
                </a>
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
