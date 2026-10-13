<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Projects</title>

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
    <meta name="title" content="AdminLTE 4 | Projects" />
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
                <h1 class="mb-0 fs-3">Projects</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Projects</li>
                  </ol>
                </nav>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <!-- Summary cards -->
            <div class="row g-3 mb-3">
              <div class="col-md-3 col-6">
                <div class="card h-100">
                  <div class="card-body">
                    <p class="text-secondary small mb-1">Active projects</p>
                    <h3 class="mb-0 fw-bold">6</h3>
                  </div>
                </div>
              </div>
              <div class="col-md-3 col-6">
                <div class="card h-100">
                  <div class="card-body">
                    <p class="text-secondary small mb-1">On track</p>
                    <h3 class="mb-0 fw-bold text-success">3</h3>
                  </div>
                </div>
              </div>
              <div class="col-md-3 col-6">
                <div class="card h-100">
                  <div class="card-body">
                    <p class="text-secondary small mb-1">At risk / delayed</p>
                    <h3 class="mb-0 fw-bold text-warning">2</h3>
                  </div>
                </div>
              </div>
              <div class="col-md-3 col-6">
                <div class="card h-100">
                  <div class="card-body">
                    <p class="text-secondary small mb-1">Completed</p>
                    <h3 class="mb-0 fw-bold text-secondary">1</h3>
                  </div>
                </div>
              </div>
            </div>

            <!-- Toolbar -->
            <div class="card">
              <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto">All projects</h3>
                <div class="input-group input-group-sm" style="width: 16rem">
                  <span class="input-group-text">
                    <i class="bi bi-search" aria-hidden="true"></i>
                  </span>
                  <input
                    type="search"
                    class="form-control"
                    placeholder="Search projects&hellip;"
                    aria-label="Search projects"
                  />
                </div>
                <select
                  class="form-select form-select-sm"
                  style="width: 10rem"
                  aria-label="Filter by status"
                >
                  <option value="">All statuses</option>
                  <option>On track</option>
                  <option>At risk</option>
                  <option>Delayed</option>
                  <option>Completed</option>
                </select>
                <button class="btn btn-primary btn-sm" type="button">
                  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                  New project
                </button>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Team</th>
                        <th>Budget</th>
                        <th>Due</th>
                        <th>Priority</th>
                        <th class="text-end">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <a href="#" class="fw-semibold text-decoration-none">
                            AdminLTE 4 Release
                          </a>
                          <div class="small text-secondary">Internal</div>
                        </td>
                        <td>
                          <span class="badge text-bg-success"> On track </span>
                        </td>
                        <td style="min-width: 8rem">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px">
                              <div
                                class="progress-bar bg-primary"
                                role="progressbar"
                                style="width: 78%"
                                aria-valuenow="78"
                                aria-valuemin="0"
                                aria-valuemax="100"
                              ></div>
                            </div>
                            <small class="text-secondary" style="min-width: 2.25rem"> 78% </small>
                          </div>
                        </td>
                        <td>
                          <div class="d-inline-flex">
                            <span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: 0;
                              "
                              title="JD"
                            >
                              JD </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-info-subtle text-info fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="OB"
                            >
                              OB </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-success-subtle text-success fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="DM"
                            >
                              DM
                            </span>
                          </div>
                        </td>
                        <td class="text-nowrap">$24,500</td>
                        <td class="text-nowrap">Jun 14, 2026</td>
                        <td>
                          <span class="badge text-bg-danger"> High </span>
                        </td>
                        <td class="text-end">
                          <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" type="button" title="View">
                              <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="Edit">
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="More">
                              <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <a href="#" class="fw-semibold text-decoration-none">
                            Marketing Site Redesign
                          </a>
                          <div class="small text-secondary">Acme Corp</div>
                        </td>
                        <td>
                          <span class="badge text-bg-warning"> At risk </span>
                        </td>
                        <td style="min-width: 8rem">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px">
                              <div
                                class="progress-bar bg-info"
                                role="progressbar"
                                style="width: 42%"
                                aria-valuenow="42"
                                aria-valuemin="0"
                                aria-valuemax="100"
                              ></div>
                            </div>
                            <small class="text-secondary" style="min-width: 2.25rem"> 42% </small>
                          </div>
                        </td>
                        <td>
                          <div class="d-inline-flex">
                            <span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-info-subtle text-info fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: 0;
                              "
                              title="OB"
                            >
                              OB </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-warning-subtle text-warning fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="MR"
                            >
                              MR
                            </span>
                          </div>
                        </td>
                        <td class="text-nowrap">$48,000</td>
                        <td class="text-nowrap">Jul 02, 2026</td>
                        <td>
                          <span class="badge text-bg-danger"> High </span>
                        </td>
                        <td class="text-end">
                          <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" type="button" title="View">
                              <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="Edit">
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="More">
                              <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <a href="#" class="fw-semibold text-decoration-none"> Mobile App v2 </a>
                          <div class="small text-secondary">Nimbus Labs</div>
                        </td>
                        <td>
                          <span class="badge text-bg-success"> On track </span>
                        </td>
                        <td style="min-width: 8rem">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px">
                              <div
                                class="progress-bar bg-primary"
                                role="progressbar"
                                style="width: 61%"
                                aria-valuenow="61"
                                aria-valuemin="0"
                                aria-valuemax="100"
                              ></div>
                            </div>
                            <small class="text-secondary" style="min-width: 2.25rem"> 61% </small>
                          </div>
                        </td>
                        <td>
                          <div class="d-inline-flex">
                            <span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: 0;
                              "
                              title="LC"
                            >
                              LC </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-subtle text-danger fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="ED"
                            >
                              ED </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-secondary fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="AF"
                            >
                              AF </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-info-subtle text-info fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="SK"
                            >
                              SK
                            </span>
                          </div>
                        </td>
                        <td class="text-nowrap">$92,500</td>
                        <td class="text-nowrap">Aug 18, 2026</td>
                        <td>
                          <span class="badge text-bg-info"> Medium </span>
                        </td>
                        <td class="text-end">
                          <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" type="button" title="View">
                              <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="Edit">
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="More">
                              <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <a href="#" class="fw-semibold text-decoration-none">
                            Analytics Pipeline
                          </a>
                          <div class="small text-secondary">Internal</div>
                        </td>
                        <td>
                          <span class="badge text-bg-danger"> Delayed </span>
                        </td>
                        <td style="min-width: 8rem">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px">
                              <div
                                class="progress-bar bg-warning"
                                role="progressbar"
                                style="width: 22%"
                                aria-valuenow="22"
                                aria-valuemin="0"
                                aria-valuemax="100"
                              ></div>
                            </div>
                            <small class="text-secondary" style="min-width: 2.25rem"> 22% </small>
                          </div>
                        </td>
                        <td>
                          <div class="d-inline-flex">
                            <span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-success-subtle text-success fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: 0;
                              "
                              title="DM"
                            >
                              DM
                            </span>
                          </div>
                        </td>
                        <td class="text-nowrap">$12,000</td>
                        <td class="text-nowrap">May 30, 2026</td>
                        <td>
                          <span class="badge text-bg-info"> Medium </span>
                        </td>
                        <td class="text-end">
                          <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" type="button" title="View">
                              <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="Edit">
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="More">
                              <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <a href="#" class="fw-semibold text-decoration-none">
                            Brand Style Guide
                          </a>
                          <div class="small text-secondary">Riverhaus</div>
                        </td>
                        <td>
                          <span class="badge text-bg-secondary"> Completed </span>
                        </td>
                        <td style="min-width: 8rem">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px">
                              <div
                                class="progress-bar bg-success"
                                role="progressbar"
                                style="width: 100%"
                                aria-valuenow="100"
                                aria-valuemin="0"
                                aria-valuemax="100"
                              ></div>
                            </div>
                            <small class="text-secondary" style="min-width: 2.25rem"> 100% </small>
                          </div>
                        </td>
                        <td>
                          <div class="d-inline-flex">
                            <span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-info-subtle text-info fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: 0;
                              "
                              title="OB"
                            >
                              OB </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-subtle text-danger fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="ED"
                            >
                              ED
                            </span>
                          </div>
                        </td>
                        <td class="text-nowrap">$8,200</td>
                        <td class="text-nowrap">May 09, 2026</td>
                        <td>
                          <span class="badge text-bg-secondary"> Low </span>
                        </td>
                        <td class="text-end">
                          <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" type="button" title="View">
                              <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="Edit">
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="More">
                              <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                      <tr>
                        <td>
                          <a href="#" class="fw-semibold text-decoration-none">
                            Onboarding Email Flow
                          </a>
                          <div class="small text-secondary">Acme Corp</div>
                        </td>
                        <td>
                          <span class="badge text-bg-success"> On track </span>
                        </td>
                        <td style="min-width: 8rem">
                          <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height: 6px">
                              <div
                                class="progress-bar bg-info"
                                role="progressbar"
                                style="width: 55%"
                                aria-valuenow="55"
                                aria-valuemin="0"
                                aria-valuemax="100"
                              ></div>
                            </div>
                            <small class="text-secondary" style="min-width: 2.25rem"> 55% </small>
                          </div>
                        </td>
                        <td>
                          <div class="d-inline-flex">
                            <span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-warning-subtle text-warning fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: 0;
                              "
                              title="MR"
                            >
                              MR </span
                            ><span
                              class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary fw-semibold"
                              style="
                                width: 1.75rem;
                                height: 1.75rem;
                                font-size: 0.7rem;
                                border: 2px solid var(--bs-body-bg);
                                margin-left: -0.5rem;
                              "
                              title="JD"
                            >
                              JD
                            </span>
                          </div>
                        </td>
                        <td class="text-nowrap">$6,800</td>
                        <td class="text-nowrap">Jun 22, 2026</td>
                        <td>
                          <span class="badge text-bg-secondary"> Low </span>
                        </td>
                        <td class="text-end">
                          <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" type="button" title="View">
                              <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="Edit">
                              <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" title="More">
                              <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <div class="card-footer d-flex justify-content-between align-items-center">
                <small class="text-secondary"> Showing 6 of 6 </small>
                <nav aria-label="Pagination">
                  <ul class="pagination pagination-sm mb-0">
                    <li class="page-item disabled">
                      <a class="page-link" href="#">Previous</a>
                    </li>
                    <li class="page-item active">
                      <a class="page-link" href="#">1</a>
                    </li>
                    <li class="page-item disabled">
                      <a class="page-link" href="#">Next</a>
                    </li>
                  </ul>
                </nav>
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
