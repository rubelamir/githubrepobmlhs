<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
  <!--begin::Head-->
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>AdminLTE 4 | Gallery</title>

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
    <meta name="title" content="AdminLTE 4 | Gallery" />
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
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
      <!--begin::App Main-->
      <main class="app-main">
        <!--begin::App Content Header-->
        <div class="app-content-header">
          <!--begin::Container-->
          <div class="container-fluid">
            <!--begin::Row-->
            <div class="row">
              <div class="col-sm-6">
                <h1 class="mb-0 fs-3">Gallery</h1>
              </div>
              <div class="col-sm-6">
                <nav aria-label="breadcrumb">
                  <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Gallery</li>
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
            <!--begin::Card-->
            <div class="card">
              <!--begin::Card Header-->
              <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <div class="card-title">Media library</div>
                <div class="card-tools">
                  <div
                    class="btn-group btn-group-sm"
                    role="group"
                    aria-label="Filter by category"
                    id="gallery-filters"
                  >
                    <button
                      type="button"
                      class="btn btn-primary"
                      data-gallery-filter="all"
                      aria-pressed="true"
                    >
                      All</button
                    ><button
                      type="button"
                      class="btn btn-outline-primary"
                      data-gallery-filter="places"
                      aria-pressed="false"
                    >
                      Places</button
                    ><button
                      type="button"
                      class="btn btn-outline-primary"
                      data-gallery-filter="products"
                      aria-pressed="false"
                    >
                      Products</button
                    ><button
                      type="button"
                      class="btn btn-outline-primary"
                      data-gallery-filter="interiors"
                      aria-pressed="false"
                    >
                      Interiors</button
                    ><button
                      type="button"
                      class="btn btn-outline-primary"
                      data-gallery-filter="people"
                      aria-pressed="false"
                    >
                      People
                    </button>
                  </div>
                </div>
              </div>
              <!--end::Card Header-->
              <!--begin::Card Body-->
              <div class="card-body">
                <!--begin::Gallery Grid-->
                <div
                  class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-4 g-3"
                  id="gallery-grid"
                >
                  <div class="col" data-gallery-item="places">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo1.png"
                          alt="Old town at dusk"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Old town at dusk</p>
                          <p class="fs-7 text-secondary mb-0">4.2 MB · 3840×2160</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Old town at dusk"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="products">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/prod-1.jpg"
                          alt="Trail boot, three-quarter"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Trail boot, three-quarter</p>
                          <p class="fs-7 text-secondary mb-0">1.1 MB · 1600×1600</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Trail boot, three-quarter"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="people">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo3.jpg"
                          alt="Reading by lamplight"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Reading by lamplight</p>
                          <p class="fs-7 text-secondary mb-0">3.8 MB · 3200×2133</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Reading by lamplight"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="products">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/prod-2.jpg"
                          alt="Trail boot, profile"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Trail boot, profile</p>
                          <p class="fs-7 text-secondary mb-0">980 KB · 1600×1600</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Trail boot, profile"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="interiors">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo2.png"
                          alt="Dining area, morning"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Dining area, morning</p>
                          <p class="fs-7 text-secondary mb-0">2.6 MB · 2560×1707</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Dining area, morning"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="products">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/prod-3.jpg"
                          alt="Trail boot, outsole"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Trail boot, outsole</p>
                          <p class="fs-7 text-secondary mb-0">1.4 MB · 1600×1600</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Trail boot, outsole"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="places">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo4.jpg"
                          alt="Canal at first light"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Canal at first light</p>
                          <p class="fs-7 text-secondary mb-0">3.1 MB · 3000×2000</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Canal at first light"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="products">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/prod-4.jpg"
                          alt="Trail boot, heel detail"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Trail boot, heel detail</p>
                          <p class="fs-7 text-secondary mb-0">1.2 MB · 1600×1600</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Trail boot, heel detail"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="interiors">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo2.png"
                          alt="Dining area, wide"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Dining area, wide</p>
                          <p class="fs-7 text-secondary mb-0">2.6 MB · 2560×1707</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Dining area, wide"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="products">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/prod-5.jpg"
                          alt="Trail boot, upper"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Trail boot, upper</p>
                          <p class="fs-7 text-secondary mb-0">1.0 MB · 1600×1600</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Trail boot, upper"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="places">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo1.png"
                          alt="Narrow street"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Narrow street</p>
                          <p class="fs-7 text-secondary mb-0">4.2 MB · 3840×2160</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Narrow street"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                  <div class="col" data-gallery-item="places">
                    <figure class="card h-100 mb-0">
                      <div class="ratio ratio-4x3">
                        <img
                          src="../assets/img/photo4.jpg"
                          alt="Canal, long exposure"
                          class="card-img-top object-fit-cover"
                          loading="lazy"
                        />
                      </div>
                      <figcaption class="card-body d-flex align-items-start gap-2 py-2">
                        <div class="flex-grow-1 overflow-hidden">
                          <p class="fw-semibold mb-0 text-truncate">Canal, long exposure</p>
                          <p class="fs-7 text-secondary mb-0">3.1 MB · 3000×2000</p>
                        </div>
                        <div class="dropdown flex-shrink-0">
                          <button
                            class="btn btn-tool"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Actions for Canal, long exposure"
                          >
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a class="dropdown-item" href="#">Download</a>
                            </li>
                            <li>
                              <a class="dropdown-item" href="#">Rename</a>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                              <a class="dropdown-item text-danger" href="#"> Delete </a>
                            </li>
                          </ul>
                        </div>
                      </figcaption>
                    </figure>
                  </div>
                </div>
                <!--end::Gallery Grid-->
                <!--begin::Empty State-->
                <p class="text-secondary text-center my-5" id="gallery-empty" role="status" hidden>
                  Nothing in this category yet.
                </p>
                <!--end::Empty State-->
              </div>
              <!--end::Card Body-->
              <!--begin::Card Footer-->
              <div
                class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2"
              >
                <span class="fs-7 text-body-secondary" id="gallery-count" aria-live="polite">
                  Showing 12 of 12 items
                </span>
                <button type="button" class="btn btn-sm btn-primary">
                  <i class="bi bi-upload me-1" aria-hidden="true"></i> Upload
                </button>
              </div>
              <!--end::Card Footer-->
            </div>
            <!--end::Card-->
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

    <!--begin::Gallery Filter-->
    <script>
      const galleryFilters = document.getElementById('gallery-filters');
      const galleryGrid = document.getElementById('gallery-grid');
      const galleryEmpty = document.getElementById('gallery-empty');
      const galleryCount = document.getElementById('gallery-count');

      if (galleryFilters && galleryGrid) {
        const tiles = galleryGrid.querySelectorAll('[data-gallery-item]');

        galleryFilters.addEventListener('click', (event) => {
          const button = event.target.closest('[data-gallery-filter]');
          if (!button) {
            return;
          }

          const category = button.dataset.galleryFilter;
          let shown = 0;

          tiles.forEach((tile) => {
            const match = category === 'all' || tile.dataset.galleryItem === category;
            tile.hidden = !match;
            if (match) {
              shown++;
            }
          });

          galleryFilters.querySelectorAll('[data-gallery-filter]').forEach((other) => {
            const active = other === button;
            other.classList.toggle('btn-primary', active);
            other.classList.toggle('btn-outline-primary', !active);
            other.setAttribute('aria-pressed', String(active));
          });

          galleryEmpty.hidden = shown > 0;
          galleryCount.textContent = `Showing ${shown} of ${tiles.length} items`;
        });
      }
    </script>
    <!--end::Gallery Filter-->
    <!--end::Script-->
  </body>
  <!--end::Body-->
</html>
