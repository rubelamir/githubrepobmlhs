<?php
if (!isset($basePath)) {
    $basePath = '';
}

require_once __DIR__ . '/auth.php';

$teacher = getAuthenticatedTeacher();
$teacherName = htmlspecialchars((string) ($teacher['teacher_name'] ?? 'Teacher'), ENT_QUOTES, 'UTF-8');
$teacherDesignation = htmlspecialchars(getDesignationLabel((string) ($teacher['teacher_designation'] ?? '')), ENT_QUOTES, 'UTF-8');
$teacherRoleLabel = htmlspecialchars((string) (panelRoleOptions()[currentPanelRole($teacher)] ?? ''), ENT_QUOTES, 'UTF-8');
$teacherMobile = htmlspecialchars((string) ($teacher['teacher_mobile'] ?? ''), ENT_QUOTES, 'UTF-8');
$teacherPicture = schoolFileUrl((string) ($teacher['teacher_pic'] ?? ''), 'teacher_pic');
$teacherPictureAttr = htmlspecialchars($teacherPicture, ENT_QUOTES, 'UTF-8');
?>
<!--begin::Header-->
      <nav class="app-header navbar navbar-expand bg-body">
        <!--begin::Container-->
        <div class="container-fluid">
          <!--begin::Start Navbar Links-->
          <ul class="navbar-nav">
            <li class="nav-item">
              <a
                class="nav-link"
                data-bmlhs-sidebar-toggle
                href="#"
                role="button"
                aria-label="Toggle sidebar"
                aria-controls="admin-sidebar"
                aria-expanded="false"
              >
                <i class="bi bi-list sidebar-toggle-icon" aria-hidden="true"></i>
              </a>
            </li>
          </ul>
          <!--end::Start Navbar Links-->

          <!--begin::End Navbar Links-->
          <ul class="navbar-nav ms-auto">
            <li class="nav-item">
              <a class="nav-link" href="<?= htmlspecialchars(publicSiteUrl(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="View website">
                <i class="bi bi-globe2"></i>
              </a>
            </li>
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
              <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                <?php if ($teacherPicture !== ''): ?>
                <img
                  src="<?= $teacherPictureAttr ?>"
                  class="user-image rounded-circle shadow"
                  alt="<?= $teacherName ?>"
                />
                <?php else: ?>
                <span class="user-image rounded-circle shadow d-inline-flex align-items-center justify-content-center bg-secondary text-white" style="width:1.6rem;height:1.6rem;font-size:.7rem"><?= htmlspecialchars(strtoupper(substr((string) ($teacher['teacher_name'] ?? 'T'), 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <span class="d-none d-md-inline"><?= $teacherName ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <!--begin::User Image-->
                <li class="user-header text-bg-primary">
                  <?php if ($teacherPicture !== ''): ?>
                  <img
                    src="<?= $teacherPictureAttr ?>"
                    class="rounded-circle shadow"
                    alt="<?= $teacherName ?>"
                  />
                  <?php endif; ?>
                  <p>
                    <?= $teacherName ?>
                    <small><?= $teacherDesignation ?><?php if ($teacherRoleLabel !== ''): ?> · <?= $teacherRoleLabel ?><?php endif; ?></small>
                  </p>
                </li>
                <!--end::User Image-->
                <!--begin::Menu Body-->
                <li class="user-body">
                  <div class="row text-center fw-bolder justify-content-center">
                    <p><?= $teacherMobile ?></p>
                  </div>
                </li>
                <!--end::Menu Body-->
                <!--begin::Menu Footer-->
                <li class="user-footer">
                  <a href="<?= $basePath ?>pages/profile.php" class="btn btn-outline-secondary btn-sm">Profile</a>
                  <a href="<?= $basePath ?>pages/change-password.php" class="btn btn-outline-secondary btn-sm ms-1" title="Change password">
                    <i class="bi bi-key"></i>
                  </a>
                  <form method="post" action="<?= $basePath ?>logout.php" class="d-inline float-end">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm">Sign out</button>
                  </form>
                </li>
                <!--end::Menu Footer-->
              </ul>
            </li>
            <!--end::User Menu Dropdown-->
          </ul>
          <!--end::End Navbar Links-->
        </div>
        <!--end::Container-->
      </nav>
      <!--end::Header-->
