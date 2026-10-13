<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Calendar</title>

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
    <meta name="title" content="AdminLTE 4 | Calendar" />
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

    <style>
      .fc-event {
        cursor: pointer;
      }
      .draggable-event {
        cursor: grab;
        user-select: none;
      }
    </style>
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
                <h1 class="mb-0 fs-3">Calendar</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Calendar</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="row g-3">
              <!-- Sidebar: draggable events -->
              <div class="col-lg-3">
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title">Draggable events</h3>
                  </div>
                  <div class="card-body">
                    <p class="text-secondary small mb-3">
                      Drag an event to the calendar to schedule it.
                    </p>
                    <div id="external-events">
                      <div
                        class="draggable-event badge text-bg-primary p-2 mb-2 d-block text-start"
                        data-color="#0d6efd"
                      >
                        <i class="bi bi-grip-vertical me-1" aria-hidden="true"></i>
                        Team standup
                      </div>
                      <div
                        class="draggable-event badge text-bg-success p-2 mb-2 d-block text-start"
                        data-color="#198754"
                      >
                        <i class="bi bi-grip-vertical me-1" aria-hidden="true"></i>
                        Customer call
                      </div>
                      <div
                        class="draggable-event badge text-bg-warning p-2 mb-2 d-block text-start"
                        data-color="#ffc107"
                      >
                        <i class="bi bi-grip-vertical me-1" aria-hidden="true"></i>
                        Design review
                      </div>
                      <div
                        class="draggable-event badge text-bg-info p-2 mb-2 d-block text-start"
                        data-color="#0dcaf0"
                      >
                        <i class="bi bi-grip-vertical me-1" aria-hidden="true"></i>
                        1:1 with manager
                      </div>
                      <div
                        class="draggable-event badge text-bg-danger p-2 d-block text-start"
                        data-color="#dc3545"
                      >
                        <i class="bi bi-grip-vertical me-1" aria-hidden="true"></i>
                        Release window
                      </div>
                    </div>
                    <hr />
                    <div class="form-check form-switch">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="remove-after-drop"
                      />
                      <label class="form-check-label small" for="remove-after-drop">
                        Remove from list after dropping
                      </label>
                    </div>
                  </div>
                </div>
              </div>
              <!-- Calendar -->
              <div class="col-lg-9">
                <div class="card">
                  <div class="card-body">
                    <div id="calendar"></div>
                  </div>
                  <div class="card-footer text-secondary small">
                    Powered by
                    <a href="https://fullcalendar.io/" target="_blank" rel="noopener"
                      >FullCalendar 6</a
                    >
                    &mdash; MIT licensed, jQuery-free.
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

    <script
      src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"
      crossorigin="anonymous"
    ></script>
    <script>
      const isoDate = (d) => d.toISOString().slice(0, 10);

      document.addEventListener('DOMContentLoaded', () => {
        const calendarEl = document.getElementById('calendar');
        const externalEl = document.getElementById('external-events');
        const removeAfterDrop = document.getElementById('remove-after-drop');

        new FullCalendar.Draggable(externalEl, {
          itemSelector: '.draggable-event',
          eventData: (el) => ({
            title: el.textContent.trim(),
            backgroundColor: el.dataset.color,
            borderColor: el.dataset.color,
          }),
        });

        const today = new Date();
        const offsetDay = (n) => {
          const d = new Date(today);
          d.setDate(today.getDate() + n);
          return isoDate(d);
        };

        const calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth',
          headerToolbar: {
            start: 'prev,next today',
            center: 'title',
            end: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
          },
          height: 700,
          editable: true,
          droppable: true,
          dayMaxEvents: 3,
          drop: (info) => {
            if (removeAfterDrop.checked) {
              info.draggedEl.remove();
            }
          },
          dateClick: (info) => {
            const title = prompt('Event title:');
            if (title) {
              calendar.addEvent({
                title: title,
                start: info.dateStr,
                allDay: info.allDay,
              });
            }
          },
          eventClick: (info) => {
            if (confirm(`Delete "${info.event.title}"?`)) {
              info.event.remove();
            }
          },
          events: [
            {
              title: 'Quarterly planning',
              start: offsetDay(-2),
              backgroundColor: '#0d6efd',
              borderColor: '#0d6efd',
            },
            {
              title: 'Onboarding session',
              start: offsetDay(1),
              backgroundColor: '#198754',
              borderColor: '#198754',
            },
            {
              title: 'Design review',
              start: offsetDay(3),
              end: offsetDay(4),
              backgroundColor: '#ffc107',
              borderColor: '#ffc107',
              textColor: '#000',
            },
            {
              title: 'Release v2.5',
              start: offsetDay(7),
              backgroundColor: '#dc3545',
              borderColor: '#dc3545',
            },
            {
              title: 'All-hands',
              start: offsetDay(10),
              backgroundColor: '#6f42c1',
              borderColor: '#6f42c1',
            },
          ],
        });

        calendar.render();
      });
    </script>
  </body>
</html>
