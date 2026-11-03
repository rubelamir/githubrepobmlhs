<?php
declare(strict_types=1);

/**
 * Shared building blocks for the admin dashboard (index.php).
 */

function dashboardStatCard(
    string $href,
    string $label,
    string $value,
    string $icon,
    string $color,
    string $columnClass,
    string $valueClass = 'fs-2',
    string $detail = ''
): string {
    ob_start();
    ?>
              <div class="<?= h($columnClass) ?>">
                <a href="<?= h($href) ?>" class="text-decoration-none">
                  <div class="card stat-card h-100">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="text-secondary small"><?= h($label) ?></div>
                          <div class="<?= h($valueClass) ?> fw-semibold"><?= h($value) ?></div>
                          <?php if ($detail !== ''): ?>
                          <div class="small text-secondary"><?= h($detail) ?></div>
                          <?php endif; ?>
                        </div>
                        <div class="icon-wrap bg-<?= h($color) ?> bg-opacity-10 text-<?= h($color) ?>">
                          <i class="bi <?= h($icon) ?>"></i>
                        </div>
                      </div>
                    </div>
                  </div>
                </a>
              </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * @return array<int, array{href:string,label:string,icon:string,color:string}>
 */
function dashboardQuickActions(): array
{
    $candidates = [
        ['module' => 'attendance', 'href' => 'pages/attendance.php', 'label' => 'হাজিরা নিন', 'icon' => 'bi-calendar-check', 'color' => 'info'],
        ['module' => 'notices', 'href' => 'pages/notices.php', 'label' => 'নোটিশ দিন', 'icon' => 'bi-megaphone', 'color' => 'warning'],
        ['module' => 'result-publication', 'href' => 'pages/result-publication.php', 'label' => 'ফল প্রকাশ', 'icon' => 'bi-clipboard-check', 'color' => 'success'],
        ['module' => 'results', 'href' => 'pages/results.php', 'label' => 'নম্বর এন্ট্রি', 'icon' => 'bi-pencil-square', 'color' => 'primary'],
    ];

    $actions = [];
    foreach ($candidates as $candidate) {
        if (canAccessModule($candidate['module'])) {
            unset($candidate['module']);
            $actions[] = $candidate;
        }
    }

    return $actions;
}

/**
 * @return array<int, string> class id => label
 */
function dashboardClassLabels(PDO $db): array
{
    if (!tableExists($db, 'cls')) {
        return [];
    }
    $labels = [];
    foreach (fetchRows($db, 'SELECT id, class_name_ban, class_name FROM cls') as $row) {
        $labels[(int) $row['id']] = (string) (($row['class_name_ban'] ?? '') !== '' ? $row['class_name_ban'] : $row['class_name']);
    }

    return $labels;
}

/**
 * @return array<int, string> section id => label
 */
function dashboardSectionLabels(PDO $db): array
{
    if (!tableExists($db, 'sections')) {
        return [];
    }
    $labels = [];
    foreach (fetchRows($db, 'SELECT id, section_name_ban, section_name FROM sections') as $row) {
        $labels[(int) $row['id']] = (string) (($row['section_name_ban'] ?? '') !== '' ? $row['section_name_ban'] : $row['section_name']);
    }

    return $labels;
}

/**
 * Attendance totals for the last seven days (oldest first).
 *
 * @param array<int, string> $rosterFilters SQL clauses on alias "s"; empty means the whole school
 * @return array{labels:array<int,string>,present:array<int,int>,absent:array<int,int>}
 */
function dashboardAttendanceTrend(PDO $db, array $rosterFilters, array $rosterParams): array
{
    $days = [];
    for ($offset = 6; $offset >= 0; $offset--) {
        $days[date('Y-m-d', strtotime("-{$offset} day"))] = ['present' => 0, 'absent' => 0];
    }
    $trend = ['labels' => [], 'present' => [], 'absent' => []];

    if (tableExists($db, 'student_attendance')) {
        $join = '';
        $where = '';
        if ($rosterFilters !== []) {
            $join = ' INNER JOIN students_info s ON s.id = a.student_id';
            $where = ' AND s.status = 1 AND (' . implode(' OR ', $rosterFilters) . ')';
        }
        $rows = fetchRows(
            $db,
            'SELECT a.attendance_date AS day, a.status, COUNT(*) AS total
             FROM student_attendance a' . $join . '
             WHERE a.attendance_date BETWEEN :from AND :to' . $where . '
             GROUP BY a.attendance_date, a.status',
            $rosterParams + ['from' => (string) array_key_first($days), 'to' => (string) array_key_last($days)]
        );
        foreach ($rows as $row) {
            $day = (string) $row['day'];
            $status = (string) $row['status'];
            if (isset($days[$day][$status])) {
                $days[$day][$status] = (int) $row['total'];
            }
        }
    }

    foreach ($days as $day => $counts) {
        $trend['labels'][] = bnDigits(date('d/m', strtotime($day)));
        $trend['present'][] = $counts['present'];
        $trend['absent'][] = $counts['absent'];
    }

    return $trend;
}

/**
 * Today's attendance grouped by class.
 *
 * @param array<int, string> $rosterFilters
 * @param array<int, string> $classLabels
 * @return array{labels:array<int,string>,present:array<int,int>,absent:array<int,int>}
 */
function dashboardClassAttendanceToday(PDO $db, array $rosterFilters, array $rosterParams, array $classLabels): array
{
    $result = ['labels' => [], 'present' => [], 'absent' => []];
    if (!tableExists($db, 'student_attendance') || !tableExists($db, 'students_info')) {
        return $result;
    }

    $where = $rosterFilters !== [] ? ' AND (' . implode(' OR ', $rosterFilters) . ')' : '';
    $rows = fetchRows(
        $db,
        'SELECT s.cls AS class_id, a.status, COUNT(*) AS total
         FROM student_attendance a
         INNER JOIN students_info s ON s.id = a.student_id
         WHERE a.attendance_date = CURDATE() AND s.status = 1' . $where . '
         GROUP BY s.cls, a.status
         ORDER BY s.cls',
        $rosterParams
    );

    $byClass = [];
    foreach ($rows as $row) {
        $classId = (int) $row['class_id'];
        $byClass[$classId] ??= ['present' => 0, 'absent' => 0];
        if (isset($byClass[$classId][(string) $row['status']])) {
            $byClass[$classId][(string) $row['status']] = (int) $row['total'];
        }
    }
    ksort($byClass);

    foreach ($byClass as $classId => $counts) {
        $result['labels'][] = $classLabels[$classId] ?? (string) $classId;
        $result['present'][] = $counts['present'];
        $result['absent'][] = $counts['absent'];
    }

    return $result;
}
