<?php if (!isset($basePath)) { $basePath = ''; } ?>
<?php
$active = currentNav();
$settings = schoolSettings(getDatabaseConnection());
$brandFull = (string) ($settings['school_name_en'] ?? 'School Admin');
$brand = trim((string) ($settings['short_name_en'] ?? ''));
if ($brand === '') {
    $brand = $brandFull;
}
$brandLogo = schoolFileUrl((string) ($settings['logo'] ?? ''), '', '');
function navActive(string $name, string $active): string
{
    return $active === $name ? ' active' : '';
}
$academicPages = ['students', 'student-form', 'student-profile', 'admissions', 'admission-tokens', 'attendance', 'promotions', 'results', 'result-publication', 'tabulation-sheet', 'routine', 'exam-routine', 'admit-card', 'id-cards', 'seat-level', 'testimonials', 'ssc-batch-data', 'certificates'];
$academicOpen = in_array($active, $academicPages, true);
$structureOpen = in_array($active, ['classes', 'sections', 'subjects', 'exams', 'periods'], true);
$can = static fn (string $module): bool => canAccessModule($module);
$showStructure = $can('classes') || $can('sections') || $can('subjects') || $can('exams') || $can('periods');
$detailsListStandalone = currentPanelRole() === 'teacher';
$showStudents = $can('admissions') || $can('student-form') || $can('student-list') || $can('students') || $can('attendance') || $can('promotions') || $can('results') || $can('result-publication') || $can('tabulation-sheet') || $can('routine') || $can('exam-routine') || $can('admit-card') || $can('id-cards') || $can('seat-level') || $can('testimonials') || $can('certificates');
$canIdCards = $can('id-cards') || $can('certificates');
$canSeatLevel = $can('seat-level') || $can('id-cards') || $can('certificates');
$canTestimonials = $can('testimonials') || $can('certificates') || $can('promotions');
$showWebsite = $can('nav-menus') || $can('notices') || $can('headlines') || $can('events') || $can('carousel') || $can('cms-gallery') || $can('downloads') || $can('history') || $can('messages') || $can('homepage-cards') || $can('homepage-links') || $can('ssc-results') || $can('students');
?>
      <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark" id="admin-sidebar">
        <div class="sidebar-brand">
          <a href="<?= $basePath ?>index.php" class="brand-link" title="<?= h($brandFull) ?>">
            <?php if ($brandLogo !== ''): ?>
              <img src="<?= h($brandLogo) ?>" alt="<?= h($brandFull) ?>" class="brand-image opacity-75 shadow" />
            <?php else: ?>
              <span class="brand-image d-grid place-items-center bg-success text-white fw-semibold"><?= h(mb_substr($brand, 0, 1)) ?></span>
            <?php endif; ?>
            <span class="brand-text fw-semibold nav-school-brand"><?= h($brand) ?></span>
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2" aria-label="Main navigation">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" id="navigation">
              <li class="nav-header">OVERVIEW</li>
              <li class="nav-item">
                <a href="<?= $basePath ?>index.php" class="nav-link<?= navActive('index', $active) ?>">
                  <i class="nav-icon bi bi-speedometer2"></i>
                  <p>Dashboard</p>
                </a>
              </li>
              <?php if ($can('school-settings')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/school-settings.php" class="nav-link<?= navActive('school-settings', $active) ?>">
                  <i class="nav-icon bi bi-gear"></i>
                  <p>School Settings</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (isHeadAdmin() || $can('school-settings') || $can('notices') || $can('admissions')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/contact-inbox.php" class="nav-link<?= navActive('contact-inbox', $active) ?>">
                  <i class="nav-icon bi bi-inbox"></i>
                  <p>Contact inbox</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/bulk-sms.php" class="nav-link<?= navActive('bulk-sms', $active) ?>">
                  <i class="nav-icon bi bi-chat-dots"></i>
                  <p>SMS</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/audit-log.php" class="nav-link<?= navActive('audit-log', $active) ?>">
                  <i class="nav-icon bi bi-journal-text"></i>
                  <p>Audit log</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/database-backup.php" class="nav-link<?= navActive('database-backup', $active) ?>">
                  <i class="nav-icon bi bi-database-down"></i>
                  <p>Database Backup</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (isHeadAdmin()): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/admin-roles.php" class="nav-link<?= navActive('admin-roles', $active) ?>">
                  <i class="nav-icon bi bi-shield-lock"></i>
                  <p>Admin roles</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/class-teachers.php" class="nav-link<?= navActive('class-teachers', $active) ?>">
                  <i class="nav-icon bi bi-person-badge"></i>
                  <p>Class teachers</p>
                </a>
              </li>
              <?php endif; ?>

              <?php if ($showStructure || $showStudents || $can('teachers') || $can('committee') || $can('retirement-teachers')): ?>
              <li class="nav-header">ACADEMICS</li>
              <?php endif; ?>
              <?php if ($showStructure): ?>
              <li class="nav-item<?= $structureOpen ? ' menu-open' : '' ?>">
                <a href="#" class="nav-link<?= $structureOpen ? ' active' : '' ?>">
                  <i class="nav-icon bi bi-diagram-2"></i>
                  <p>Academic structure<i class="nav-arrow bi bi-chevron-right"></i></p>
                </a>
                <ul class="nav nav-treeview">
                  <?php if ($can('classes')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/classes.php" class="nav-link<?= navActive('classes', $active) ?>"><i class="nav-icon bi bi-layers"></i><p>Classes</p></a></li><?php endif; ?>
                  <?php if ($can('sections')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/sections.php" class="nav-link<?= navActive('sections', $active) ?>"><i class="nav-icon bi bi-columns-gap"></i><p>Sections / Groups</p></a></li><?php endif; ?>
                  <?php if ($can('subjects')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/subjects.php" class="nav-link<?= navActive('subjects', $active) ?>"><i class="nav-icon bi bi-journal-text"></i><p>Subjects</p></a></li><?php endif; ?>
                  <?php if ($can('exams')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/exams.php" class="nav-link<?= navActive('exams', $active) ?>"><i class="nav-icon bi bi-clipboard-check"></i><p>Exams</p></a></li><?php endif; ?>
                  <?php if ($can('periods')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/periods.php" class="nav-link<?= navActive('periods', $active) ?>"><i class="nav-icon bi bi-clock"></i><p>Periods</p></a></li><?php endif; ?>
                </ul>
              </li>
              <?php endif; ?>
              <?php if ($showStudents): ?>
              <li class="nav-item<?= $academicOpen ? ' menu-open' : '' ?>">
                <a href="#" class="nav-link<?= $academicOpen ? ' active' : '' ?>">
                  <i class="nav-icon bi bi-mortarboard"></i>
                  <p><?= $can('results') && !$showStructure && currentPanelRole() === 'teacher' ? 'My duties' : 'Student management' ?><i class="nav-arrow bi bi-chevron-right"></i></p>
                </a>
                <ul class="nav nav-treeview">
                  <?php if ($can('admissions')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/admissions.php" class="nav-link<?= navActive('admissions', $active) ?>"><i class="nav-icon bi bi-person-plus"></i><p>Admissions</p></a></li><?php endif; ?>
                  <?php if ($can('admissions')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/admission-tokens.php" class="nav-link<?= navActive('admission-tokens', $active) ?>"><i class="nav-icon bi bi-key"></i><p>Admission tokens</p></a></li><?php endif; ?>
                  <?php if ($can('student-form')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/student-form.php" class="nav-link<?= navActive('student-form', $active) ?>"><i class="nav-icon bi bi-person-vcard"></i><p>New admission</p></a></li><?php endif; ?>
                  <?php if ($can('student-list')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/students.php" class="nav-link<?= in_array($active, ['students', 'student-profile'], true) ? ' active' : '' ?>"><i class="nav-icon bi bi-people"></i><p>Student list</p></a></li><?php endif; ?>
                  <?php if (!$detailsListStandalone): ?><li class="nav-item"><a href="<?= $basePath ?>pages/student-details-list.php" class="nav-link<?= navActive('student-details-list', $active) ?>"><i class="nav-icon bi bi-card-list"></i><p>Student Details List</p></a></li><?php endif; ?>
                  <?php if (!$detailsListStandalone): ?><li class="nav-item"><a href="<?= $basePath ?>pages/student-profiles.php" class="nav-link<?= in_array($active, ['student-profiles', 'student-profile'], true) ? ' active' : '' ?>"><i class="nav-icon bi bi-person-lines-fill"></i><p>Student Profile</p></a></li><?php endif; ?>
                  <?php if ($can('attendance')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/attendance.php" class="nav-link<?= navActive('attendance', $active) ?>"><i class="nav-icon bi bi-calendar-check"></i><p>Attendance</p></a></li><?php endif; ?>
                  <?php if ($can('promotions')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/promotions.php" class="nav-link<?= navActive('promotions', $active) ?>"><i class="nav-icon bi bi-arrow-up-right-circle"></i><p>Class promotion</p></a></li><?php endif; ?>
                  <?php if ($can('results')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/results.php" class="nav-link<?= navActive('results', $active) ?>"><i class="nav-icon bi bi-clipboard-data"></i><p>Mark Entry</p></a></li><?php endif; ?>
                  <?php if ($can('result-publication')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/result-publication.php" class="nav-link<?= navActive('result-publication', $active) ?>"><i class="nav-icon bi bi-megaphone"></i><p>Public result publication</p></a></li><?php endif; ?>
                  <?php if ($can('tabulation-sheet')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/tabulation-sheet.php" class="nav-link<?= navActive('tabulation-sheet', $active) ?>"><i class="nav-icon bi bi-table"></i><p>Tabulation sheet</p></a></li><?php endif; ?>
                  <?php if ($can('routine')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/routine.php" class="nav-link<?= navActive('routine', $active) ?>"><i class="nav-icon bi bi-calendar3"></i><p>Class routine</p></a></li><?php endif; ?>
                  <?php if ($can('exam-routine')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/exam-routine.php" class="nav-link<?= navActive('exam-routine', $active) ?>"><i class="nav-icon bi bi-calendar2-week"></i><p>Exam routine</p></a></li><?php endif; ?>
                  <?php if ($can('admit-card')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/admit-card.php" class="nav-link<?= navActive('admit-card', $active) ?>"><i class="nav-icon bi bi-postcard"></i><p>Admit card</p></a></li><?php endif; ?>
                  <?php if ($canIdCards): ?><li class="nav-item"><a href="<?= $basePath ?>pages/id-cards.php" class="nav-link<?= navActive('id-cards', $active) ?>"><i class="nav-icon bi bi-person-badge"></i><p>ID Card</p></a></li><?php endif; ?>
                  <?php if ($canSeatLevel): ?><li class="nav-item"><a href="<?= $basePath ?>pages/seat-level.php" class="nav-link<?= navActive('seat-level', $active) ?>"><i class="nav-icon bi bi-grid-3x2-gap"></i><p>Seat Label</p></a></li><?php endif; ?>
                  <?php if ($canTestimonials): ?><li class="nav-item"><a href="<?= $basePath ?>pages/testimonials.php" class="nav-link<?= in_array($active, ['testimonials', 'ssc-batch-data'], true) ? ' active' : '' ?>"><i class="nav-icon bi bi-file-earmark-text"></i><p>Testimonial</p></a></li><?php endif; ?>
                  <?php if ($can('certificates')): ?><li class="nav-item"><a href="<?= $basePath ?>pages/certificates.php" class="nav-link<?= navActive('certificates', $active) ?>"><i class="nav-icon bi bi-award"></i><p>Certificates</p></a></li><?php endif; ?>
                </ul>
              </li>
              <?php endif; ?>
              <?php if ($detailsListStandalone): ?>
              <li class="nav-item"><a href="<?= $basePath ?>pages/student-details-list.php" class="nav-link<?= navActive('student-details-list', $active) ?>"><i class="nav-icon bi bi-card-list"></i><p>Student Details List</p></a></li>
              <li class="nav-item"><a href="<?= $basePath ?>pages/student-profiles.php" class="nav-link<?= in_array($active, ['student-profiles', 'student-profile'], true) ? ' active' : '' ?>"><i class="nav-icon bi bi-person-lines-fill"></i><p>Student Profile</p></a></li>
              <?php endif; ?>
              <?php if ($can('teachers')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/view_all_teacherlist.php" class="nav-link<?= in_array($active, ['view_all_teacherlist', 'add_teacher', 'profile'], true) ? ' active' : '' ?>">
                  <i class="nav-icon bi bi-people"></i>
                  <p>Teachers &amp; Staff</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('committee')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/committee.php" class="nav-link<?= navActive('committee', $active) ?>">
                  <i class="nav-icon bi bi-diagram-3"></i>
                  <p>Managing Committee</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('retirement-teachers')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/retirement-teachers.php" class="nav-link<?= navActive('retirement-teachers', $active) ?>">
                  <i class="nav-icon bi bi-person-hearts"></i>
                  <p>Former Teachers</p>
                </a>
              </li>
              <?php endif; ?>

              <?php if ($showWebsite): ?>
              <li class="nav-header">WEBSITE CONTENT</li>
              <?php if ($can('nav-menus')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/nav-menus.php" class="nav-link<?= navActive('nav-menus', $active) ?>">
                  <i class="nav-icon bi bi-list-ul"></i>
                  <p>Navigation Menus</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('notices')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/notices.php" class="nav-link<?= navActive('notices', $active) ?>">
                  <i class="nav-icon bi bi-megaphone"></i>
                  <p>Notices</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('headlines')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/headlines.php" class="nav-link<?= navActive('headlines', $active) ?>">
                  <i class="nav-icon bi bi-type"></i>
                  <p>Notice Headlines</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('events')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/events.php" class="nav-link<?= navActive('events', $active) ?>">
                  <i class="nav-icon bi bi-calendar-event"></i>
                  <p>Events</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/academic-calendar.php" class="nav-link<?= navActive('academic-calendar', $active) ?>">
                  <i class="nav-icon bi bi-calendar3"></i>
                  <p>Academic calendar</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('carousel')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/carousel.php" class="nav-link<?= navActive('carousel', $active) ?>">
                  <i class="nav-icon bi bi-images"></i>
                  <p>Homepage Slider</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('cms-gallery')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/cms-gallery.php" class="nav-link<?= navActive('cms-gallery', $active) ?>">
                  <i class="nav-icon bi bi-camera"></i>
                  <p>Photo Gallery</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('students')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/meritorious-students.php" class="nav-link<?= navActive('meritorious-students', $active) ?>">
                  <i class="nav-icon bi bi-award"></i>
                  <p>Meritorious Students</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('downloads')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/downloads.php" class="nav-link<?= navActive('downloads', $active) ?>">
                  <i class="nav-icon bi bi-download"></i>
                  <p>Downloads</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/syllabus.php" class="nav-link<?= navActive('syllabus', $active) ?>">
                  <i class="nav-icon bi bi-journal-richtext"></i>
                  <p>Syllabus</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('history')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/history.php" class="nav-link<?= navActive('history', $active) ?>">
                  <i class="nav-icon bi bi-book"></i>
                  <p>School History</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('messages')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/messages.php" class="nav-link<?= navActive('messages', $active) ?>">
                  <i class="nav-icon bi bi-chat-quote"></i>
                  <p>President & HT Messages</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('homepage-cards')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/homepage-cards.php" class="nav-link<?= navActive('homepage-cards', $active) ?>">
                  <i class="nav-icon bi bi-grid"></i>
                  <p>Homepage Cards</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('homepage-links')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/homepage-links.php" class="nav-link<?= navActive('homepage-links', $active) ?>">
                  <i class="nav-icon bi bi-link-45deg"></i>
                  <p>Homepage Links</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if ($can('ssc-results')): ?>
              <li class="nav-item">
                <a href="<?= $basePath ?>pages/ssc-results.php" class="nav-link<?= navActive('ssc-results', $active) ?>">
                  <i class="nav-icon bi bi-bar-chart"></i>
                  <p>SSC Results Chart</p>
                </a>
              </li>
              <?php endif; ?>
              <?php endif; ?>

              <li class="nav-header">PUBLIC SITE</li>
              <li class="nav-item">
                <a href="<?= h(publicSiteUrl()) ?>" class="nav-link" target="_blank" rel="noopener">
                  <i class="nav-icon bi bi-box-arrow-up-right"></i>
                  <p>Open Website</p>
                </a>
              </li>
            </ul>
          </nav>
        </div>
      </aside>
