<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAuthentication('examples/login-v2.php');

$db = getDatabaseConnection();
$settings = schoolSettings($db);
$teacher = getAuthenticatedTeacher();
$basePath = '';
$pageTitle = 'Dashboard | ' . $settings['school_name_en'];
$role = currentPanelRole($teacher);
$assignedSubjects = teacherAssignedSubjects($db, (string) ($teacher['teacher_mobile'] ?? ''));
$classTeacherSlots = classTeacherAssignments($db, (string) ($teacher['teacher_mobile'] ?? ''), (string) date('Y'));

$showClassTeacherAttendance = $role === 'teacher' && $classTeacherSlots !== [];
$classRosterFilters = [];
$classRosterParams = [];
if ($showClassTeacherAttendance) {
    foreach ($classTeacherSlots as $index => $slot) {
        $slotClassId = (int) ($slot['class_id'] ?? 0);
        if ($slotClassId < 1) {
            continue;
        }
        $clause = 's.cls = :cls_' . $index;
        $classRosterParams['cls_' . $index] = $slotClassId;
        $slotSectionId = (int) ($slot['section_id'] ?? 0);
        if ($slotSectionId > 0) {
            $clause .= ' AND s.sections = :sec_' . $index;
            $classRosterParams['sec_' . $index] = (string) $slotSectionId;
        }
        $slotYear = trim((string) ($slot['session_year'] ?? ''));
        if ($slotYear === '') {
            $slotYear = (string) date('Y');
        }
        $clause .= ' AND s.session_year = :year_' . $index;
        $classRosterParams['year_' . $index] = $slotYear;
        $classRosterFilters[] = '(' . $clause . ')';
    }
}
$studentCount = tableCount($db, 'students_info');
if ($classRosterFilters !== [] && tableExists($db, 'students_info')) {
    $classStudentRows = fetchRows(
        $db,
        'SELECT COUNT(*) AS total FROM students_info s WHERE s.status = 1 AND (' . implode(' OR ', $classRosterFilters) . ')',
        $classRosterParams
    );
    $studentCount = (int) ($classStudentRows[0]['total'] ?? 0);
}

$stats = [];
if (canAccessModule('teachers')) {
    $stats[] = ['label' => 'Teachers & Staff', 'count' => tableCount($db, 'teacher_info'), 'icon' => 'bi-people', 'color' => 'primary', 'href' => 'pages/view_all_teacherlist.php'];
}
$stats[] = ['label' => 'Students', 'count' => $studentCount, 'icon' => 'bi-mortarboard', 'color' => 'success', 'href' => canAccessModule('students') ? 'pages/students.php' : 'index.php'];
if ($role !== 'teacher') {
    $stats[] = ['label' => 'Notices', 'count' => tableCount($db, 'main_notice'), 'icon' => 'bi-megaphone', 'color' => 'warning', 'href' => canAccessModule('notices') ? 'pages/notices.php' : 'index.php'];
    $stats[] = ['label' => 'Events', 'count' => tableCount($db, 'events'), 'icon' => 'bi-calendar-event', 'color' => 'info', 'href' => canAccessModule('events') ? 'pages/events.php' : 'index.php'];
}
if (canAccessModule('committee')) {
    $stats[] = ['label' => 'Committee', 'count' => count(publicCommitteeMembers($db, false)), 'icon' => 'bi-diagram-3', 'color' => 'danger', 'href' => 'pages/committee.php'];
}
if (canAccessModule('cms-gallery')) {
    $stats[] = ['label' => 'Gallery Photos', 'count' => tableCount($db, 'image_gallery'), 'icon' => 'bi-camera', 'color' => 'secondary', 'href' => 'pages/cms-gallery.php'];
}
$stats[] = ['label' => 'My subjects', 'count' => count($assignedSubjects), 'icon' => 'bi-journal-check', 'color' => 'success', 'href' => 'pages/results.php'];
$classTeacherAttendanceHref = 'pages/attendance.php';
if (count($classTeacherSlots) === 1) {
    $classTeacherSlot = $classTeacherSlots[0];
    $classTeacherAttendanceHref = 'pages/attendance.php?' . http_build_query([
        'cls' => (int) ($classTeacherSlot['class_id'] ?? 0),
        'sections' => (int) ($classTeacherSlot['section_id'] ?? 0) > 0 ? (int) $classTeacherSlot['section_id'] : '',
        'session_year' => (string) (($classTeacherSlot['session_year'] ?? '') !== '' ? $classTeacherSlot['session_year'] : date('Y')),
        'date' => date('Y-m-d'),
    ]);
}

$notices = tableExists($db, 'main_notice')
    ? fetchRows($db, 'SELECT * FROM main_notice ORDER BY main_notice_creation_time DESC LIMIT 6')
    : [];
$events = tableExists($db, 'events')
    ? fetchRows($db, 'SELECT * FROM events ORDER BY ' . eventListOrderSql($db) . ' LIMIT 5')
    : [];
$teachers = tableExists($db, 'teacher_info')
    ? fetchRows($db, 'SELECT teacher_name, teacher_designation, teacher_pic FROM teacher_info ORDER BY ' . teacherListOrderSql($db) . ' LIMIT 6')
    : [];
$todayPresent = tableExists($db, 'student_attendance')
    ? tableCount($db, 'student_attendance', "attendance_date = CURDATE() AND status = 'present'")
    : 0;
$todayAbsent = tableExists($db, 'student_attendance')
    ? tableCount($db, 'student_attendance', "attendance_date = CURDATE() AND status = 'absent'")
    : 0;
$todayLate = tableExists($db, 'student_attendance')
    ? tableCount($db, 'student_attendance', "attendance_date = CURDATE() AND status = 'late'")
    : 0;
$classTodayPresent = 0;
$classTodayAbsent = 0;
$classTodayLate = 0;
if ($showClassTeacherAttendance && $classRosterFilters !== [] && tableExists($db, 'students_info') && tableExists($db, 'student_attendance')) {
        $classAttendance = fetchRows(
            $db,
            'SELECT a.status, COUNT(*) AS total
             FROM student_attendance a
             INNER JOIN students_info s ON s.id = a.student_id
             WHERE a.attendance_date = CURDATE() AND s.status = 1 AND (' . implode(' OR ', $classRosterFilters) . ')
             GROUP BY a.status',
            $classRosterParams
        );
        foreach ($classAttendance as $row) {
            $total = (int) ($row['total'] ?? 0);
            if ((string) ($row['status'] ?? '') === 'absent') {
                $classTodayAbsent = $total;
            } elseif ((string) ($row['status'] ?? '') === 'late') {
                $classTodayLate = $total;
            } elseif ((string) ($row['status'] ?? '') === 'present') {
                $classTodayPresent = $total;
            }
        }
}
$unpublishedExams = tableExists($db, 'exam_details') && columnExists($db, 'exam_details', 'public_results_enabled')
    ? tableCount($db, 'exam_details', 'public_results_enabled = 0')
    : 0;
$newApplications = tableExists($db, 'students_info') && columnExists($db, 'students_info', 'status')
    ? tableCount($db, 'students_info', 'status = 0')
    : 0;
$unreadMessages = tableExists($db, 'contact_messages')
    ? tableCount($db, 'contact_messages', 'is_read = 0')
    : 0;

include __DIR__ . '/includes/layout-head.php';
?>
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="row">
              <div class="col-sm-8">
                <h3 class="mb-0">Dashboard</h3>
                <p class="text-secondary mb-0"><?= h($settings['school_name_bn']) ?> — <?= h((string) ($teacher['teacher_name'] ?? 'Admin')) ?> · <?= h(panelRoleOptions()[$role] ?? 'Teacher') ?></p>
              </div>
              <div class="col-sm-4 text-sm-end">
                <a class="btn btn-outline-primary" href="<?= h(publicSiteUrl()) ?>" target="_blank" rel="noopener">
                  <i class="bi bi-globe2 me-1"></i>View Website
                </a>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/includes/flash.php'; ?>
            <?php if ($role !== 'teacher' || !$showClassTeacherAttendance): ?>
            <div class="row g-3 mb-4">
              <?php if (!$showClassTeacherAttendance): ?>
              <div class="col-sm-6 col-xl-3">
                <a href="pages/attendance.php" class="text-decoration-none">
                  <div class="card stat-card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="text-secondary small">Today's attendance</div>
                          <div class="fs-5 fw-semibold"><?= (int) $todayPresent ?> present</div>
                          <div class="small text-secondary"><?= (int) $todayAbsent ?> absent · <?= (int) $todayLate ?> late</div>
                        </div>
                        <div class="icon-wrap bg-info bg-opacity-10 text-info">
                          <i class="bi bi-calendar-check"></i>
                        </div>
                      </div>
                    </div>
                  </div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($role !== 'teacher'): ?>
              <div class="col-sm-6 col-xl-3">
                <a href="pages/result-publication.php" class="text-decoration-none">
                  <div class="card stat-card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="text-secondary small">Unpublished results</div>
                          <div class="fs-2 fw-semibold"><?= (int) $unpublishedExams ?></div>
                        </div>
                        <div class="icon-wrap bg-warning bg-opacity-10 text-warning">
                          <i class="bi bi-clipboard-x"></i>
                        </div>
                      </div>
                    </div>
                  </div>
                </a>
              </div>
              <div class="col-sm-6 col-xl-3">
                <a href="pages/admissions.php" class="text-decoration-none">
                  <div class="card stat-card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="text-secondary small">New admission applications</div>
                          <div class="fs-2 fw-semibold"><?= (int) $newApplications ?></div>
                        </div>
                        <div class="icon-wrap bg-primary bg-opacity-10 text-primary">
                          <i class="bi bi-person-plus"></i>
                        </div>
                      </div>
                    </div>
                  </div>
                </a>
              </div>
              <div class="col-sm-6 col-xl-3">
                <a href="pages/contact-inbox.php" class="text-decoration-none">
                  <div class="card stat-card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="text-secondary small">Unread messages</div>
                          <div class="fs-2 fw-semibold"><?= (int) $unreadMessages ?></div>
                        </div>
                        <div class="icon-wrap bg-danger bg-opacity-10 text-danger">
                          <i class="bi bi-envelope"></i>
                        </div>
                      </div>
                    </div>
                  </div>
                </a>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="row g-3 mb-4">
              <?php foreach ($stats as $stat): ?>
                <div class="col-sm-6 col-xl-2">
                  <a href="<?= h($stat['href']) ?>" class="text-decoration-none">
                    <div class="card stat-card h-100">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                          <div>
                            <div class="text-secondary small"><?= h($stat['label']) ?></div>
                            <div class="fs-2 fw-semibold"><?= (int) $stat['count'] ?></div>
                          </div>
                          <div class="icon-wrap bg-<?= h($stat['color']) ?> bg-opacity-10 text-<?= h($stat['color']) ?>">
                            <i class="bi <?= h($stat['icon']) ?>"></i>
                          </div>
                        </div>
                      </div>
                    </div>
                  </a>
                </div>
              <?php endforeach; ?>
              <?php if ($showClassTeacherAttendance): ?>
                <div class="col-sm-6 col-xl-3">
                  <a href="<?= h($classTeacherAttendanceHref) ?>" class="text-decoration-none">
                    <div class="card stat-card h-100">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                          <div>
                            <div class="text-secondary small">Today's attendance</div>
                            <div class="fs-5 fw-semibold"><?= (int) $classTodayPresent ?> present</div>
                            <div class="small text-secondary"><?= (int) $classTodayAbsent ?> absent · <?= (int) $classTodayLate ?> late</div>
                          </div>
                          <div class="icon-wrap bg-info bg-opacity-10 text-info">
                            <i class="bi bi-calendar-check"></i>
                          </div>
                        </div>
                      </div>
                    </div>
                  </a>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($assignedSubjects !== [] || $role === 'teacher'): ?>
            <div class="card stat-card mb-4">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Assigned subjects</h3>
                <?php if (canAccessModule('results')): ?>
                <a href="pages/results.php" class="btn btn-sm btn-success">Mark Entry</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-striped mb-0 align-middle">
                    <thead><tr><th>Class</th><th>Section</th><th>Subject</th><th></th></tr></thead>
                    <tbody>
                      <?php if ($assignedSubjects === []): ?>
                        <tr><td colspan="4" class="text-secondary text-center py-4">No subjects are assigned to you yet. The Head Teacher can set this under Subjects.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($assignedSubjects as $item): ?>
                        <?php
                          $q = http_build_query([
                              'cls' => (int) $item['class_id'],
                              'sections' => (int) $item['section_id'],
                              'subject_id' => (int) $item['id'],
                              'session_year' => date('Y'),
                          ]);
                        ?>
                        <tr>
                          <td><?= h(classLabel($db, (int) $item['class_id'])) ?></td>
                          <td><?= (int) $item['section_id'] > 0 ? h(sectionLabel($db, (int) $item['section_id'])) : 'Entire class' ?></td>
                          <td><?= h((string) ($item['subject_name_ban'] ?: $item['subject_name'])) ?></td>
                          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="pages/results.php?<?= h($q) ?>">Enter marks</a></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($classTeacherSlots !== [] || ($role === 'teacher' && canAccessModule('attendance'))): ?>
            <div class="card stat-card mb-4">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Class teacher</h3>
                <?php if (canAccessModule('attendance')): ?>
                <a href="pages/attendance.php" class="btn btn-sm btn-success">Take attendance</a>
                <?php endif; ?>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-striped mb-0 align-middle">
                    <thead><tr><th>Class</th><th>Section</th><th></th></tr></thead>
                    <tbody>
                      <?php if ($classTeacherSlots === []): ?>
                        <tr><td colspan="3" class="text-secondary text-center py-4">You are not a class teacher this session.</td></tr>
                      <?php endif; ?>
                      <?php foreach ($classTeacherSlots as $item): ?>
                        <?php
                          $q = http_build_query([
                              'cls' => (int) $item['class_id'],
                              'sections' => (int) $item['section_id'] > 0 ? (int) $item['section_id'] : '',
                              'session_year' => (string) ($item['session_year'] ?: date('Y')),
                              'date' => date('Y-m-d'),
                          ]);
                        ?>
                        <tr>
                          <td><?= h(classLabel($db, (int) $item['class_id'])) ?></td>
                          <td><?= (int) $item['section_id'] > 0 ? h(sectionLabel($db, (int) $item['section_id'])) : 'Entire class' ?></td>
                          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="pages/attendance.php?<?= h($q) ?>">Attendance</a></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($role !== 'teacher'): ?>
            <div class="row g-3">
              <div class="col-lg-8">
                <div class="card stat-card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Latest Notices</h3>
                    <?php if (canAccessModule('notices')): ?>
                    <a href="pages/notices.php" class="btn btn-sm btn-primary">Manage</a>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-striped mb-0 align-middle">
                        <thead>
                          <tr>
                            <th>Notice</th>
                            <th>Status</th>
                            <th>Date</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if ($notices === []): ?>
                            <tr><td colspan="3" class="text-center text-secondary py-4">No notices yet. Add one from Website Content.</td></tr>
                          <?php endif; ?>
                          <?php foreach ($notices as $notice): ?>
                            <tr>
                              <td><?= h(schoolSnippet((string) $notice['main_notice_text'], 90)) ?></td>
                              <td>
                                <?php if ((int) $notice['main_notice_status'] === 1): ?>
                                  <span class="badge text-bg-success">Published</span>
                                <?php else: ?>
                                  <span class="badge text-bg-secondary">Hidden</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-nowrap"><?php
                                $noticeStamp = strtotime((string) ($notice['main_notice_creation_time'] ?? ''));
                                echo $noticeStamp
                                    ? h('at ' . date('g:i A', $noticeStamp) . ' in ' . date('d-m-Y', $noticeStamp))
                                    : '—';
                              ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div class="card stat-card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">Recent Events</h3>
                    <?php if (canAccessModule('events')): ?>
                    <a href="pages/events.php" class="btn btn-sm btn-outline-primary">Manage</a>
                    <?php endif; ?>
                  </div>
                  <div class="list-group list-group-flush">
                    <?php if ($events === []): ?>
                      <div class="list-group-item text-secondary">No events published yet.</div>
                    <?php endif; ?>
                    <?php foreach ($events as $event): ?>
                      <div class="list-group-item">
                        <div class="fw-semibold"><?= h((string) $event['events_headlines']) ?></div>
                        <div class="small text-secondary"><?= h(schoolSnippet((string) ($event['events_details'] ?? ''), 120)) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <div class="col-lg-4">
                <div class="card stat-card mb-3">
                  <div class="card-body">
                    <div class="text-secondary">Today's attendance</div>
                    <div class="display-6 fw-semibold"><?= (int) $todayPresent ?></div>
                    <div class="small text-secondary mb-3">students marked present</div>
                    <?php if (canAccessModule('attendance')): ?>
                    <a href="pages/attendance.php" class="btn btn-success w-100">Take Attendance</a>
                    <?php elseif (canAccessModule('results')): ?>
                    <a href="pages/results.php" class="btn btn-success w-100">Enter marks</a>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card stat-card">
                  <div class="card-header"><h3 class="card-title mb-0">Staff snapshot</h3></div>
                  <div class="card-body">
                    <?php foreach ($teachers as $item): ?>
                      <?php $staffPhoto = schoolFileUrl((string) ($item['teacher_pic'] ?? ''), 'teacher_pic'); ?>
                      <div class="d-flex align-items-center mb-3">
                        <?php if ($staffPhoto !== ''): ?>
                        <img src="<?= h($staffPhoto) ?>" alt="" class="rounded-circle me-2" width="40" height="40" style="object-fit:cover">
                        <?php else: ?>
                        <span class="rounded-circle me-2 d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-secondary" style="width:40px;height:40px"><?= h(strtoupper(substr((string) ($item['teacher_name'] ?? '?'), 0, 1))) ?></span>
                        <?php endif; ?>
                        <div>
                          <div class="fw-semibold"><?= h((string) $item['teacher_name']) ?></div>
                          <div class="small text-secondary"><?= h(getDesignationLabel((string) $item['teacher_designation'])) ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                    <?php if (canAccessModule('teachers')): ?>
                    <a href="pages/add_teacher.php" class="btn btn-outline-secondary w-100">Add Teacher</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/includes/layout-end.php'; ?>
