<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | File Manager</title>

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
    <meta name="title" content="AdminLTE 4 | File Manager" />
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
                <h1 class="mb-0 fs-3">File Manager</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Files</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="row g-3">
              <!-- Folder tree -->
              <div class="col-lg-3">
                <div class="d-grid gap-2 mb-3">
                  <button class="btn btn-primary" type="button">
                    <i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>
                    Upload files
                  </button>
                  <button class="btn btn-outline-secondary" type="button">
                    <i class="bi bi-folder-plus me-1" aria-hidden="true"></i>
                    New folder
                  </button>
                </div>
                <div class="card">
                  <div class="list-group list-group-flush">
                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center active"
                    >
                      <span>
                        <i class="bi bi-house me-2" aria-hidden="true"></i>
                        My Drive
                      </span>
                      <small class="opacity-75">24</small>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ps-4"
                    >
                      <span>
                        <i class="bi bi-folder me-2" aria-hidden="true"></i>
                        Documents
                      </span>
                      <small class="opacity-75">12</small>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ps-4 active"
                    >
                      <span>
                        <i class="bi bi-folder me-2" aria-hidden="true"></i>
                        Design
                      </span>
                      <small class="opacity-75">8</small>
                    </a>
                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ps-5"
                    >
                      <span>
                        <i class="bi bi-folder me-2" aria-hidden="true"></i>
                        v2.4 candidates
                      </span>
                      <small class="opacity-75">4</small> </a
                    ><a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ps-5"
                    >
                      <span>
                        <i class="bi bi-folder me-2" aria-hidden="true"></i>
                        Archive
                      </span>
                      <small class="opacity-75">28</small>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ps-4"
                    >
                      <span>
                        <i class="bi bi-folder me-2" aria-hidden="true"></i>
                        Invoices
                      </span>
                      <small class="opacity-75">41</small>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                    >
                      <span>
                        <i class="bi bi-people me-2" aria-hidden="true"></i>
                        Shared with me
                      </span>
                      <small class="opacity-75">9</small>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                    >
                      <span>
                        <i class="bi bi-star me-2" aria-hidden="true"></i>
                        Starred
                      </span>
                      <small class="opacity-75">6</small>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                    >
                      <span>
                        <i class="bi bi-clock-history me-2" aria-hidden="true"></i>
                        Recent
                      </span>
                    </a>

                    <a
                      href="#"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                    >
                      <span>
                        <i class="bi bi-trash me-2" aria-hidden="true"></i>
                        Trash
                      </span>
                      <small class="opacity-75">3</small>
                    </a>
                  </div>
                </div>
                <div class="card mt-3">
                  <div class="card-body">
                    <p class="fw-semibold mb-2 small">
                      <i class="bi bi-cloud me-1" aria-hidden="true"></i>
                      Storage
                    </p>
                    <div class="progress mb-2" style="height: 8px">
                      <div
                        class="progress-bar"
                        role="progressbar"
                        style="width: 62%"
                        aria-valuenow="62"
                        aria-valuemin="0"
                        aria-valuemax="100"
                      ></div>
                    </div>
                    <small class="text-secondary"> 6.2 GB of 10 GB used </small>
                  </div>
                </div>
              </div>

              <!-- File browser -->
              <div class="col-lg-9">
                <div class="card">
                  <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                    <nav aria-label="breadcrumb" class="flex-grow-1">
                      <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                          <a href="#">
                            <i class="bi bi-house" aria-hidden="true"></i>
                          </a>
                        </li>
                        <li class="breadcrumb-item">
                          <a href="#">My Drive</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">Design</li>
                      </ol>
                    </nav>
                    <div class="input-group input-group-sm" style="width: 14rem">
                      <span class="input-group-text">
                        <i class="bi bi-search" aria-hidden="true"></i>
                      </span>
                      <input
                        type="search"
                        class="form-control"
                        placeholder="Search files&hellip;"
                        aria-label="Search files"
                      />
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="View">
                      <input type="radio" class="btn-check" name="view" id="view-grid" checked />
                      <label class="btn btn-outline-secondary" for="view-grid">
                        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
                      </label>
                      <input type="radio" class="btn-check" name="view" id="view-list" />
                      <label class="btn btn-outline-secondary" for="view-list">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                      </label>
                    </div>
                  </div>
                  <div class="card-body">
                    <!-- Grid view -->
                    <div id="grid-view" class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-folder-fill text-warning display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">
                              Customer interviews
                            </p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="—">
                                <i class="bi bi-folder me-1" aria-hidden="true"></i>
                                Folder
                              </span>
                              <span class="text-truncate" title="Today"> Today </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <span class="badge text-bg-info position-absolute top-0 end-0 m-2">
                            <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                            Shared
                          </span>
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-folder-fill text-warning display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">Q2 planning</p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="—">
                                <i class="bi bi-folder me-1" aria-hidden="true"></i>
                                Folder
                              </span>
                              <span class="text-truncate" title="Yesterday"> Yesterday </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-pdf-fill text-danger display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">
                              design-review.pdf
                            </p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="1.4 MB"> 1.4 MB </span>
                              <span class="text-truncate" title="10:42 AM"> 10:42 AM </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-image-fill text-primary display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">
                              focus-ring-dark.png
                            </p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="320 KB"> 320 KB </span>
                              <span class="text-truncate" title="10:38 AM"> 10:38 AM </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-pdf-fill text-danger display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">
                              INV-2026-00428.pdf
                            </p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="184 KB"> 184 KB </span>
                              <span class="text-truncate" title="Yesterday"> Yesterday </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <span class="badge text-bg-info position-absolute top-0 end-0 m-2">
                            <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                            Shared
                          </span>
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-word-fill text-info display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">roadmap.docx</p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="47 KB"> 47 KB </span>
                              <span class="text-truncate" title="Yesterday"> Yesterday </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-spreadsheet-fill text-success display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">
                              analytics-may.xlsx
                            </p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="92 KB"> 92 KB </span>
                              <span class="text-truncate" title="May 16"> May 16 </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-zip-fill text-secondary display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">
                              site-export-2026-05.zip
                            </p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="12.3 MB"> 12.3 MB </span>
                              <span class="text-truncate" title="May 14"> May 14 </span>
                            </div>
                          </div>
                        </a>
                      </div>
                      <div class="col">
                        <a
                          href="#"
                          class="card text-center text-decoration-none text-body h-100 position-relative"
                        >
                          <div class="card-body d-flex flex-column justify-content-center pb-2">
                            <i
                              class="bi bi-file-earmark-code-fill text-primary display-5 mb-3"
                              aria-hidden="true"
                            ></i>
                            <p class="card-title fw-medium small text-break mb-0">main.tsx</p>
                          </div>
                          <div class="card-footer bg-transparent small text-secondary py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                              <span class="text-truncate" title="8 KB"> 8 KB </span>
                              <span class="text-truncate" title="May 12"> May 12 </span>
                            </div>
                          </div>
                        </a>
                      </div>
                    </div>
                    <!-- List view -->
                    <div id="list-view" class="d-none">
                      <div class="table-responsive">
                        <table class="table align-middle mb-0">
                          <thead>
                            <tr>
                              <th>Name</th>
                              <th>Size</th>
                              <th>Modified</th>
                              <th class="text-end">Actions</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-folder-fill text-warning me-2"
                                  aria-hidden="true"
                                ></i>
                                Customer interviews
                              </td>
                              <td>—</td>
                              <td>Today</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-folder-fill text-warning me-2"
                                  aria-hidden="true"
                                ></i>
                                Q2 planning
                                <span class="badge text-bg-info ms-2"> Shared </span>
                              </td>
                              <td>—</td>
                              <td>Yesterday</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-pdf-fill text-danger me-2"
                                  aria-hidden="true"
                                ></i>
                                design-review.pdf
                              </td>
                              <td>1.4 MB</td>
                              <td>10:42 AM</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-image-fill text-primary me-2"
                                  aria-hidden="true"
                                ></i>
                                focus-ring-dark.png
                              </td>
                              <td>320 KB</td>
                              <td>10:38 AM</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-pdf-fill text-danger me-2"
                                  aria-hidden="true"
                                ></i>
                                INV-2026-00428.pdf
                              </td>
                              <td>184 KB</td>
                              <td>Yesterday</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-word-fill text-info me-2"
                                  aria-hidden="true"
                                ></i>
                                roadmap.docx
                                <span class="badge text-bg-info ms-2"> Shared </span>
                              </td>
                              <td>47 KB</td>
                              <td>Yesterday</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-spreadsheet-fill text-success me-2"
                                  aria-hidden="true"
                                ></i>
                                analytics-may.xlsx
                              </td>
                              <td>92 KB</td>
                              <td>May 16</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-zip-fill text-secondary me-2"
                                  aria-hidden="true"
                                ></i>
                                site-export-2026-05.zip
                              </td>
                              <td>12.3 MB</td>
                              <td>May 14</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                            <tr>
                              <td>
                                <i
                                  class="bi bi-file-earmark-code-fill text-primary me-2"
                                  aria-hidden="true"
                                ></i>
                                main.tsx
                              </td>
                              <td>8 KB</td>
                              <td>May 12</td>
                              <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Download"
                                  >
                                    <i class="bi bi-download" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Share"
                                  >
                                    <i class="bi bi-share" aria-hidden="true"></i>
                                  </button>
                                  <button
                                    class="btn btn-outline-danger"
                                    type="button"
                                    title="Delete"
                                  >
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
                                </div>
                              </td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                  <div class="card-footer text-secondary small">9 items</div>
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

    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const grid = document.getElementById('view-grid');
        const list = document.getElementById('view-list');
        const gridView = document.getElementById('grid-view');
        const listView = document.getElementById('list-view');
        grid.addEventListener('change', () => {
          gridView.classList.remove('d-none');
          listView.classList.add('d-none');
        });
        list.addEventListener('change', () => {
          listView.classList.remove('d-none');
          gridView.classList.add('d-none');
        });
      });
    </script>
  </body>
</html>
