<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Advanced Form Elements</title>

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
    <meta name="title" content="AdminLTE 4 | Advanced Form Elements" />
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

    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/tom-select@2.6.1/dist/css/tom-select.bootstrap5.min.css"
      integrity="sha384-qNqaCnsmyTrYVwmqv4/4PcwMK8ZFAQnYPpVjWor+6cX6rKsPhebzu8vO2J3s+VZg"
      crossorigin="anonymous"
    />
    <link
      rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css"
      integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt"
      crossorigin="anonymous"
    />
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
                <h1 class="mb-0 fs-3">Advanced Elements</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item"><a href="#">Forms</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Advanced Elements</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="row g-4">
              <div class="col-12">
                <div class="callout callout-info">
                  AdminLTE deliberately doesn't bundle form widgets. The controls on this page use
                  the recommended third-party integrations &mdash;
                  <a
                    href="https://tom-select.js.org/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="callout-link"
                    >Tom Select</a
                  >
                  and
                  <a
                    href="https://flatpickr.js.org/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="callout-link"
                    >Flatpickr</a
                  >. See the
                  <a href="../docs/integrations.php" class="callout-link"
                    >Recommended Integrations</a
                  >
                  page for the full list with install snippets.
                </div>
              </div>

              <!-- Tom Select -->
              <div class="col-md-6">
                <div class="card card-primary card-outline mb-4">
                  <div class="card-header">
                    <div class="card-title">Tom Select</div>
                  </div>
                  <div class="card-body">
                    <div class="mb-4">
                      <label for="country" class="form-label">Single select with search</label>
                      <select
                        id="country"
                        class="form-control"
                        aria-label="Single select with search"
                      >
                        <option value="">Select a country&hellip;</option>
                        <option>Australia</option>
                        <option>Brazil</option>
                        <option>Canada</option>
                        <option>France</option>
                        <option>Germany</option>
                        <option>Italy</option>
                        <option>Japan</option>
                        <option>Latvia</option>
                        <option>Netherlands</option>
                        <option>Spain</option>
                        <option>United Kingdom</option>
                        <option>United States</option>
                      </select>
                      <div class="form-text">
                        Start typing to filter the list; the &times; button clears the selection
                        (<code>clear_button</code>).
                      </div>
                    </div>
                    <div class="mb-4">
                      <label for="post-tags" class="form-label">Multi-select with tags</label>
                      <select
                        id="post-tags"
                        class="form-control"
                        aria-label="Multi-select with tags"
                        multiple
                      >
                        <option value="bootstrap" selected>Bootstrap</option>
                        <option value="javascript" selected>JavaScript</option>
                        <option value="css">CSS</option>
                        <option value="accessibility">Accessibility</option>
                        <option value="performance">Performance</option>
                        <option value="tooling">Tooling</option>
                      </select>
                      <div class="form-text">
                        Type to filter, press Enter to create a new tag, use the &times; button to
                        remove one.
                      </div>
                    </div>
                    <div>
                      <label for="reviewer" class="form-label">Remote data with pagination</label>
                      <select
                        id="reviewer"
                        class="form-control"
                        aria-label="Remote data with pagination"
                      ></select>
                      <div class="form-text">
                        Backed by 72 records behind a stubbed endpoint. Typing is debounced
                        (<code>loadThrottle</code>), stale requests are cancelled with
                        <code>AbortController</code>, and scrolling to the bottom of the list pulls
                        the next page (<code>virtual_scroll</code>).
                      </div>
                    </div>
                  </div>
                  <div class="card-footer text-body-secondary small">
                    Powered by
                    <a
                      href="https://tom-select.js.org/"
                      target="_blank"
                      rel="noopener"
                      class="link-body-emphasis"
                      >Tom Select</a
                    >
                    &mdash; vanilla JS, no jQuery required.
                  </div>
                </div>
              </div>

              <!-- Flatpickr -->
              <div class="col-md-6">
                <div class="card card-success card-outline mb-4">
                  <div class="card-header">
                    <div class="card-title">Flatpickr</div>
                  </div>
                  <div class="card-body">
                    <div class="mb-4">
                      <label for="delivery-date" class="form-label">Date picker</label>
                      <input
                        type="text"
                        id="delivery-date"
                        class="form-control"
                        placeholder="Select a delivery date"
                      />
                    </div>
                    <div class="mb-4">
                      <label for="meeting-start" class="form-label">Date &amp; time picker</label>
                      <input
                        type="text"
                        id="meeting-start"
                        class="form-control"
                        placeholder="Select date and time"
                      />
                    </div>
                    <div class="mb-4">
                      <label for="report-period" class="form-label">Range picker</label>
                      <input
                        type="text"
                        id="report-period"
                        class="form-control"
                        placeholder="Select a reporting period"
                      />
                      <div class="form-text">Pick a start date, then an end date.</div>
                    </div>
                    <div>
                      <label for="reminder-time" class="form-label">Time picker</label>
                      <input
                        type="text"
                        id="reminder-time"
                        class="form-control"
                        placeholder="Select a time"
                      />
                    </div>
                  </div>
                  <div class="card-footer text-body-secondary small">
                    Powered by
                    <a
                      href="https://flatpickr.js.org/"
                      target="_blank"
                      rel="noopener"
                      class="link-body-emphasis"
                      >Flatpickr</a
                    >
                    &mdash; vanilla JS, no jQuery required.
                  </div>
                </div>
              </div>

              <!-- Select2 → Tom Select -->
              <div class="col-12" id="select2">
                <div class="card card-secondary card-outline mb-4">
                  <div class="card-header">
                    <div class="card-title">Coming from Select2?</div>
                  </div>
                  <div class="card-body">
                    <p>
                      <a href="https://select2.org/" target="_blank" rel="noopener noreferrer"
                        >Select2</a
                      >
                      is still maintained, but it is still a jQuery plugin &mdash; there is no
                      jQuery-free build. Tom Select covers the same feature set in roughly
                      <strong>20&nbsp;kB gzip</strong>, against Select2's
                      <strong>~50&nbsp;kB</strong> once jQuery is counted. The API maps across
                      almost one-to-one:
                    </p>
                    <div
                      class="table-responsive"
                      tabindex="0"
                      role="region"
                      aria-label="Select2 to Tom Select API mapping"
                    >
                      <table class="table table-sm table-striped align-middle mb-0">
                        <caption class="visually-hidden">
                          Select2 options and methods mapped to their Tom Select equivalents
                        </caption>
                        <thead>
                          <tr>
                            <th scope="col" style="width: 50%">Select2</th>
                            <th scope="col">Tom Select</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr>
                            <td><code>$("#x").select2()</code></td>
                            <td><code>new TomSelect("#x")</code></td>
                          </tr>
                          <tr>
                            <td><code>tags: true</code></td>
                            <td><code>create: true</code></td>
                          </tr>
                          <tr>
                            <td><code>allowClear: true</code></td>
                            <td><code>plugins: ["clear_button"]</code></td>
                          </tr>
                          <tr>
                            <td><code>closeOnSelect: false</code></td>
                            <td><code>closeAfterSelect: false</code></td>
                          </tr>
                          <tr>
                            <td><code>maximumSelectionLength: 3</code></td>
                            <td><code>maxItems: 3</code></td>
                          </tr>
                          <tr>
                            <td><code>minimumInputLength: 2</code></td>
                            <td><code>shouldLoad: (q) =&gt; q.length &gt;= 2</code></td>
                          </tr>
                          <tr>
                            <td><code>ajax: &#123; url, delay: 250 &#125;</code></td>
                            <td>
                              <code>load(query, callback)</code> + <code>loadThrottle: 250</code>
                            </td>
                          </tr>
                          <tr>
                            <td><code>ajax.processResults</code></td>
                            <td>
                              <code>valueField</code> / <code>labelField</code> /
                              <code>searchField</code>
                            </td>
                          </tr>
                          <tr>
                            <td>infinite scroll (<code>pagination.more</code>)</td>
                            <td>
                              <code>plugins: ["virtual_scroll"]</code> + <code>setNextUrl()</code>
                            </td>
                          </tr>
                          <tr>
                            <td><code>templateResult</code> / <code>templateSelection</code></td>
                            <td><code>render: &#123; option, item &#125;</code></td>
                          </tr>
                          <tr>
                            <td><code>dropdownParent: $("#modal")</code></td>
                            <td class="text-body-secondary">
                              Not needed &mdash; the dropdown renders inline
                            </td>
                          </tr>
                          <tr>
                            <td><code>$("#x").val("2").trigger("change")</code></td>
                            <td><code>ts.setValue("2")</code></td>
                          </tr>
                          <tr>
                            <td><code>$("#x").val()</code></td>
                            <td><code>ts.getValue()</code></td>
                          </tr>
                          <tr>
                            <td><code>.on("select2:select", fn)</code></td>
                            <td><code>ts.on("item_add", fn)</code></td>
                          </tr>
                          <tr>
                            <td><code>.on("change", fn)</code></td>
                            <td><code>ts.on("change", fn)</code></td>
                          </tr>
                          <tr>
                            <td><code>$("#x").select2("open")</code></td>
                            <td><code>ts.open()</code></td>
                          </tr>
                          <tr>
                            <td><code>$("#x").prop("disabled", true)</code></td>
                            <td><code>ts.disable()</code></td>
                          </tr>
                          <tr>
                            <td><code>$("#x").select2("destroy")</code></td>
                            <td><code>ts.destroy()</code></td>
                          </tr>
                          <tr>
                            <td><code>$("#x").empty().select2(&#123; data &#125;)</code></td>
                            <td><code>ts.clearOptions(); ts.addOptions(data)</code></td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <div class="card-footer text-body-secondary small">
                    Locked into Select2 for now? AdminLTE ships a styling-only
                    <a
                      href="../docs/integrations.php#select2-compatibility-theme"
                      class="link-body-emphasis"
                      >Select2 compatibility theme</a
                    >
                    that makes it match Bootstrap 5.3 &mdash; including dark mode and dropdowns
                    inside modals. No jQuery is added to AdminLTE itself; you bring the plugin, we
                    style it.
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
      src="https://cdn.jsdelivr.net/npm/tom-select@2.6.1/dist/js/tom-select.complete.min.js"
      integrity="sha384-OgdLxftmuTHirLYFRbvXsKJxgSVqpU4ZdZ07tRiBC+Z4GjtQ5Al0uika0gtMfeQw"
      crossorigin="anonymous"
    ></script>
    <script
      src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"
      integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6"
      crossorigin="anonymous"
    ></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        // Tom Select: single select with search and a clear button
        new TomSelect('#country', {
          plugins: ['clear_button'],
          sortField: { field: 'text', direction: 'asc' },
        });

        // Tom Select: multi-select with removable tags (type to add new ones)
        new TomSelect('#post-tags', {
          plugins: ['remove_button'],
          create: true,
          persist: false,
        });

        // Tom Select: remote data, the way you'd wire a select backed by a table
        // with thousands of rows. Only the matching page is ever loaded.
        const PAGE_SIZE = 12;
        const FIRST_NAMES = [
          'Olivia',
          'Liam',
          'Emma',
          'Noah',
          'Ava',
          'Ethan',
          'Sofia',
          'Lucas',
          'Mia',
          'Mateo',
          'Isla',
          'Arjun',
          'Nora',
          'Hugo',
          'Yuki',
          'Amara',
          'Elena',
          'Omar',
          'Freya',
          'Diego',
          'Leila',
          'Jonas',
          'Priya',
          'Marek',
        ];
        const TEAMS = ['Frontend', 'Backend', 'Design'];
        // Stands in for a database table.
        const REVIEWERS = FIRST_NAMES.flatMap((first, index) =>
          ['Bennett', 'Carter', 'Dawson'].map((last, offset) => ({
            id: index * 3 + offset + 1,
            name: `${first} ${last}`,
            team: TEAMS[(index + offset) % TEAMS.length],
          })),
        );

        // Stand-in for a real endpoint. To go live, replace the whole body with:
        //   return fetch(url, { signal }).then((response) => response.json())
        // The { results, next } response shape is what the loader below expects
        // — and it's the same shape Select2's `ajax.processResults` users return.
        function fetchReviewers(url, signal) {
          const params = new URLSearchParams(url.slice(url.indexOf('?') + 1));
          const query = (params.get('q') || '').toLowerCase();
          const page = Number(params.get('page') || 1);

          return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
              const matches = REVIEWERS.filter((person) =>
                `${person.name} ${person.team}`.toLowerCase().includes(query),
              );
              const start = (page - 1) * PAGE_SIZE;
              const hasMore = start + PAGE_SIZE < matches.length;
              resolve({
                results: matches.slice(start, start + PAGE_SIZE),
                next: hasMore
                  ? `/api/reviewers?q=${encodeURIComponent(query)}&page=${page + 1}`
                  : null,
              });
            }, 400);

            signal.addEventListener('abort', () => {
              clearTimeout(timer);
              reject(new DOMException('Aborted', 'AbortError'));
            });
          });
        }

        let inFlight = null;

        new TomSelect('#reviewer', {
          valueField: 'id',
          labelField: 'name',
          searchField: ['name', 'team'],
          placeholder: 'Type to search 72 people…',
          preload: 'focus',
          // Tom Select debounces `load` for you — no hand-rolled timer needed.
          loadThrottle: 300,
          // Pages in the next batch when the dropdown is scrolled to the bottom.
          plugins: ['virtual_scroll'],
          firstUrl: (query) => `/api/reviewers?q=${encodeURIComponent(query)}&page=1`,
          // Tom Select invokes `load` with the instance as `this`; that's how
          // the virtual_scroll plugin hands over getUrl() and setNextUrl().
          /* eslint-disable unicorn/no-this-outside-of-class */
          async load(query, callback) {
            // Cancel the request already in flight, so a slow response for "an"
            // can't land after the fast one for "anna".
            if (inFlight) {
              inFlight.abort();
            }
            inFlight = new AbortController();

            try {
              const json = await fetchReviewers(this.getUrl(query), inFlight.signal);
              if (json.next) {
                this.setNextUrl(query, json.next);
              }
              callback(json.results);
            } catch (error) {
              // An aborted request was replaced by a newer one — let that one
              // resolve the dropdown instead of clearing it here.
              if (error.name !== 'AbortError') {
                callback();
              }
            }
          },
          /* eslint-enable unicorn/no-this-outside-of-class */
          render: {
            option: (data, escape) =>
              `<div class="d-flex justify-content-between align-items-center">
                 <span>${escape(data.name)}</span>
                 <span class="badge text-bg-secondary">${escape(data.team)}</span>
               </div>`,
            loading_more: () => '<div class="p-2 text-body-secondary small">Loading more…</div>',
            no_more_results: () =>
              '<div class="p-2 text-body-secondary small">End of results</div>',
          },
        });

        // Flatpickr: date picker
        flatpickr('#delivery-date', {
          dateFormat: 'Y-m-d',
        });

        // Flatpickr: date & time picker
        flatpickr('#meeting-start', {
          enableTime: true,
          dateFormat: 'Y-m-d H:i',
        });

        // Flatpickr: range picker
        flatpickr('#report-period', {
          mode: 'range',
          dateFormat: 'Y-m-d',
        });

        // Flatpickr: time-only picker
        flatpickr('#reminder-time', {
          enableTime: true,
          noCalendar: true,
          dateFormat: 'H:i',
          time_24hr: true,
        });
      });
    </script>
  </body>
</html>
