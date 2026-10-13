<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Kanban Board</title>

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
    <meta name="title" content="AdminLTE 4 | Kanban Board" />
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
      .kanban-board {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1rem;
        align-items: start;
      }
      .kanban-lane {
        background: var(--bs-tertiary-bg);
        border-radius: var(--bs-border-radius);
        padding: 0.75rem;
        min-height: 8rem;
      }
      .kanban-lane-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
        padding: 0 0.25rem;
      }
      .kanban-cards {
        min-height: 4rem;
      }
      .kanban-card {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: var(--bs-border-radius);
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        cursor: grab;
        transition: box-shadow 0.15s ease;
      }
      .kanban-card:hover {
        box-shadow: var(--bs-box-shadow-sm);
      }
      .kanban-card.sortable-ghost {
        opacity: 0.4;
        background: var(--bs-primary-bg-subtle);
        border-style: dashed;
      }
      .kanban-card.sortable-drag {
        cursor: grabbing;
        box-shadow: var(--bs-box-shadow);
        transform: rotate(2deg);
      }
      .kanban-assignees {
        display: inline-flex;
      }
      .kanban-assignee {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 50%;
        background: var(--bs-secondary-bg);
        color: var(--bs-secondary-color);
        font-size: 0.7rem;
        font-weight: 600;
        border: 2px solid var(--bs-body-bg);
        margin-left: -0.5rem;
      }
      .kanban-assignee:first-child {
        margin-left: 0;
      }
      .kanban-add-card {
        background: transparent;
        border: 1px dashed var(--bs-border-color);
        color: var(--bs-secondary-color);
        width: 100%;
        padding: 0.5rem;
        border-radius: var(--bs-border-radius);
        font-size: 0.875rem;
      }
      .kanban-add-card:hover {
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
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
                <h1 class="mb-0 fs-3">Kanban Board</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Kanban</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <p class="text-secondary mb-0 small">
                  Drag cards between lanes. Try dropping &ldquo;In progress&rdquo; items into
                  &ldquo;Done&rdquo;.
                </p>
              </div>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" type="button">
                  <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter
                </button>
                <button class="btn btn-outline-secondary" type="button">
                  <i class="bi bi-sort-down me-1" aria-hidden="true"></i>Sort
                </button>
                <button class="btn btn-primary" type="button">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add lane
                </button>
              </div>
            </div>

            <div class="kanban-board" id="kanban-board">
              <div class="kanban-lane" data-lane-id="backlog">
                <div class="kanban-lane-header">
                  <h2 class="h6 mb-0 d-flex align-items-center gap-2">
                    <span class="badge text-bg-secondary" style="font-size: 0.65rem"> 3 </span>
                    Backlog
                  </h2>
                  <button
                    class="btn btn-sm btn-link text-secondary p-0"
                    type="button"
                    title="Lane actions"
                    aria-label="Lane actions"
                  >
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                  </button>
                </div>
                <div class="kanban-cards" data-lane-id="backlog">
                  <article class="kanban-card">
                    <span class="badge text-bg-secondary mb-2"> tech debt </span>
                    <p class="fw-semibold mb-1 small">Audit unused SCSS variables</p>
                    <p class="text-secondary small mb-2">
                      Identify deprecated Bootstrap 5.3.4 variables and add comments.
                    </p>
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="DM"> DM </span>
                      </div>
                    </div>
                  </article>
                  <article class="kanban-card">
                    <span class="badge text-bg-info mb-2"> docs </span>
                    <p class="fw-semibold mb-1 small">Document hreflang setup</p>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="JD"> JD </span>
                      </div>
                    </div>
                  </article>
                  <article class="kanban-card">
                    <span class="badge text-bg-danger mb-2"> bug </span>
                    <p class="fw-semibold mb-1 small">Investigate Safari iOS calendar drag bug</p>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="OB"> OB </span
                        ><span class="kanban-assignee" title="MK"> MK </span>
                      </div>
                      <small class="text-secondary">
                        <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                        May 28
                      </small>
                    </div>
                  </article>
                </div>
                <button class="kanban-add-card mt-2" type="button" data-add-card-for="backlog">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                  Add card
                </button>
              </div>
              <div class="kanban-lane" data-lane-id="todo">
                <div class="kanban-lane-header">
                  <h2 class="h6 mb-0 d-flex align-items-center gap-2">
                    <span class="badge text-bg-primary" style="font-size: 0.65rem"> 2 </span>
                    To do
                  </h2>
                  <button
                    class="btn btn-sm btn-link text-secondary p-0"
                    type="button"
                    title="Lane actions"
                    aria-label="Lane actions"
                  >
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                  </button>
                </div>
                <div class="kanban-cards" data-lane-id="todo">
                  <article class="kanban-card">
                    <span class="badge text-bg-primary mb-2"> feature </span>
                    <p class="fw-semibold mb-1 small">Add Tom Select recommended-integration doc</p>
                    <p class="text-secondary small mb-2">
                      Cover install, theming, single + multi select examples.
                    </p>
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="JD"> JD </span>
                      </div>
                      <small class="text-secondary">
                        <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                        May 24
                      </small>
                    </div>
                  </article>
                  <article class="kanban-card">
                    <span class="badge text-bg-primary mb-2"> feature </span>
                    <p class="fw-semibold mb-1 small">Wire up profile page avatar upload</p>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="EM"> EM </span>
                      </div>
                    </div>
                  </article>
                </div>
                <button class="kanban-add-card mt-2" type="button" data-add-card-for="todo">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                  Add card
                </button>
              </div>
              <div class="kanban-lane" data-lane-id="in-progress">
                <div class="kanban-lane-header">
                  <h2 class="h6 mb-0 d-flex align-items-center gap-2">
                    <span class="badge text-bg-warning" style="font-size: 0.65rem"> 2 </span>
                    In progress
                  </h2>
                  <button
                    class="btn btn-sm btn-link text-secondary p-0"
                    type="button"
                    title="Lane actions"
                    aria-label="Lane actions"
                  >
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                  </button>
                </div>
                <div class="kanban-cards" data-lane-id="in-progress">
                  <article class="kanban-card">
                    <span class="badge text-bg-primary mb-2"> feature </span>
                    <p class="fw-semibold mb-1 small">Build kanban board demo</p>
                    <p class="text-secondary small mb-2">
                      SortableJS, draggable between lanes, MIT license.
                    </p>
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="JD"> JD </span>
                      </div>
                      <small class="text-secondary">
                        <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
                        Today
                      </small>
                    </div>
                  </article>
                  <article class="kanban-card">
                    <span class="badge text-bg-warning mb-2"> qa </span>
                    <p class="fw-semibold mb-1 small">Tabulator + FullCalendar integration QA</p>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="OB"> OB </span>
                      </div>
                    </div>
                  </article>
                </div>
                <button class="kanban-add-card mt-2" type="button" data-add-card-for="in-progress">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                  Add card
                </button>
              </div>
              <div class="kanban-lane" data-lane-id="done">
                <div class="kanban-lane-header">
                  <h2 class="h6 mb-0 d-flex align-items-center gap-2">
                    <span class="badge text-bg-success" style="font-size: 0.65rem"> 3 </span>
                    Done
                  </h2>
                  <button
                    class="btn btn-sm btn-link text-secondary p-0"
                    type="button"
                    title="Lane actions"
                    aria-label="Lane actions"
                  >
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                  </button>
                </div>
                <div class="kanban-cards" data-lane-id="done">
                  <article class="kanban-card">
                    <span class="badge text-bg-primary mb-2"> feature </span>
                    <p class="fw-semibold mb-1 small">Upgrade to Bootstrap 5.3.8</p>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="DM"> DM </span>
                      </div>
                    </div>
                  </article>
                  <article class="kanban-card">
                    <span class="badge text-bg-primary mb-2"> feature </span>
                    <p class="fw-semibold mb-1 small">Ship 8 Tier-1 page templates</p>
                    <p class="text-secondary small mb-2">
                      Profile, settings, invoice, pricing, FAQ, 404/500/maintenance.
                    </p>
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="JD"> JD </span
                        ><span class="kanban-assignee" title="OB"> OB </span>
                      </div>
                    </div>
                  </article>
                  <article class="kanban-card">
                    <span class="badge text-bg-secondary mb-2"> tech debt </span>
                    <p class="fw-semibold mb-1 small">Drop dead eslint-config-xo deps</p>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="kanban-assignees">
                        <span class="kanban-assignee" title="DM"> DM </span>
                      </div>
                    </div>
                  </article>
                </div>
                <button class="kanban-add-card mt-2" type="button" data-add-card-for="done">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                  Add card
                </button>
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
      src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.7/Sortable.min.js"
      crossorigin="anonymous"
    ></script>
    <script>
      const updateLaneCount = (laneEl) => {
        const lane = laneEl.closest('.kanban-lane');
        const badge = lane.querySelector('.kanban-lane-header .badge');
        badge.textContent = laneEl.children.length;
      };

      document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.kanban-cards').forEach((el) => {
          new Sortable(el, {
            group: 'kanban',
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            onEnd: (evt) => {
              updateLaneCount(evt.from);
              if (evt.from !== evt.to) updateLaneCount(evt.to);
            },
          });
        });

        document.querySelectorAll('[data-add-card-for]').forEach((btn) => {
          btn.addEventListener('click', () => {
            const title = prompt('Card title:');
            if (!title) return;
            const laneId = btn.dataset.addCardFor;
            const lane = document.querySelector(`.kanban-cards[data-lane-id="${laneId}"]`);
            const card = document.createElement('article');
            card.className = 'kanban-card';
            card.innerHTML = `
              <p class="fw-semibold mb-1 small">${title.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c])}</p>
              <div class="d-flex justify-content-between align-items-center">
                <div class="kanban-assignees">
                  <span class="kanban-assignee" title="You">YO</span>
                </div>
                <small class="text-secondary">just now</small>
              </div>
            `;
            lane.append(card);
            updateLaneCount(lane);
          });
        });
      });
    </script>
  </body>
</html>
