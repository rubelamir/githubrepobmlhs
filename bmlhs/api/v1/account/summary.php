<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    $account = $identity['account'];

    if ($identity['account_type'] === 'student') {
        $studentId = (int) $account['id'];
        $studentRows = fetchRows(
            $db,
            'SELECT s.id, s.name_ban, s.name_eng, s.cls_roll, s.cls, s.sections, s.session_year,
                    c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
             FROM students_info s
             LEFT JOIN cls c ON s.cls = c.id
             LEFT JOIN sections sec ON CAST(s.sections AS CHAR) = CAST(sec.id AS CHAR)
             WHERE s.id = :id LIMIT 1',
            ['id' => $studentId]
        );
        $student = $studentRows[0] ?? null;
        if (!is_array($student)) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Student profile was not found.']], 404);
        }
        $attendanceRows = fetchRows(
            $db,
            'SELECT
                COUNT(*) AS total,
                SUM(status = \'present\') AS present,
                SUM(status = \'absent\') AS absent,
                SUM(status = \'late\') AS late
             FROM student_attendance WHERE student_id = :student_id',
            ['student_id' => $studentId]
        );
        $recentAttendance = fetchRows(
            $db,
            'SELECT attendance_date, status, remarks
             FROM student_attendance
             WHERE student_id = :student_id
             ORDER BY attendance_date DESC, id DESC
             LIMIT 60',
            ['student_id' => $studentId]
        );
        $attendance = $attendanceRows[0] ?? ['total' => 0, 'present' => 0, 'absent' => 0, 'late' => 0];
        $publishedResults = [];
        foreach (schoolPublishedExams($db) as $exam) {
            $sheet = studentResultSheet(
                $db,
                (string) ($student['cls_roll'] ?? ''),
                (int) ($student['cls'] ?? 0),
                (int) ($student['sections'] ?? 0),
                (string) ($student['session_year'] ?? ''),
                (int) $exam['id']
            );
            if (($sheet['rows'] ?? []) === []) {
                continue;
            }
            $publishedResults[] = [
                'exam_id' => (int) $exam['id'],
                'exam_name' => trim((string) ($exam['exam_name_bangla'] ?? ''))
                    ?: (string) ($exam['exam_name_english'] ?? ''),
                'total' => (float) ($sheet['total'] ?? 0),
                'gpa' => (float) ($sheet['gpa'] ?? 0),
                'grade' => (string) ($sheet['letter'] ?? ''),
            ];
        }
        $profile = [
            'id' => $studentId,
            'name' => trim((string) ($student['name_ban'] ?? '')) ?: (string) ($student['name_eng'] ?? ''),
            'roll' => (string) ($student['cls_roll'] ?? ''),
            'class_id' => (int) ($student['cls'] ?? 0),
            'class_name' => trim((string) ($student['class_name_ban'] ?? '')) ?: (string) ($student['class_name'] ?? ''),
            'section_id' => (int) ($student['sections'] ?? 0),
            'section_name' => trim((string) ($student['section_name_ban'] ?? '')) ?: (string) ($student['section_name'] ?? ''),
            'session_year' => (string) ($student['session_year'] ?? ''),
        ];
        mobileApiJson([
            'data' => [
                'account_type' => 'student',
                'profile' => $profile,
                'attendance_summary' => [
                    'total' => (int) ($attendance['total'] ?? 0),
                    'present' => (int) ($attendance['present'] ?? 0),
                    'absent' => (int) ($attendance['absent'] ?? 0),
                    'late' => (int) ($attendance['late'] ?? 0),
                ],
                'recent_attendance' => $recentAttendance,
                'published_results' => $publishedResults,
            ],
        ]);
    }

    $role = currentPanelRole($account);
    $sessionYear = trim((string) ($_GET['session_year'] ?? date('Y')));
    if (preg_match('/^\d{4}$/', $sessionYear) !== 1) {
        mobileApiJson(['error' => ['code' => 'invalid_filter', 'message' => 'session_year must be a four-digit year.']], 422);
    }
    $canViewAllAttendance = canViewAllAttendance($account);
    $assigned = $canViewAllAttendance
        ? array_map(
            static fn (array $class): array => [
                'class_id' => (int) $class['id'],
                'section_id' => 0,
                'session_year' => $sessionYear,
                'teacher_mobile' => (string) ($account['teacher_mobile'] ?? ''),
            ],
            schoolClasses($db, true)
        )
        : classTeacherAssignments($db, (string) ($account['teacher_mobile'] ?? ''), $sessionYear);

    $classes = [];
    foreach ($assigned as $assignment) {
        $classId = (int) ($assignment['class_id'] ?? 0);
        if ($classId < 1) {
            continue;
        }
        $sectionId = (int) ($assignment['section_id'] ?? 0);
        $key = $classId . ':' . $sectionId;
        $classes[$key] = [
            'class_id' => $classId,
            'class_name' => classLabel($db, $classId),
            'section_id' => $sectionId,
            'section_name' => $sectionId > 0 ? sectionLabel($db, $sectionId) : '',
            'session_year' => (string) ($assignment['session_year'] ?? $sessionYear),
        ];
    }
    $assignedSubjects = [];
    foreach (teacherAssignedSubjects($db, (string) ($account['teacher_mobile'] ?? '')) as $subject) {
        $classId = (int) ($subject['class_id'] ?? 0);
        $sectionId = (int) ($subject['section_id'] ?? 0);
        if ($classId < 1) {
            continue;
        }
        $assignedSubjects[] = [
            'class_id' => $classId,
            'class_name' => classLabel($db, $classId),
            'section_id' => $sectionId,
            'section_name' => $sectionId > 0 ? sectionLabel($db, $sectionId) : '',
        ];
    }
    mobileApiJson([
        'data' => [
            'account_type' => 'staff',
            'role' => $role,
            'profile' => [
                'id' => (int) ($account['id'] ?? 0),
                'name' => (string) ($account['teacher_name'] ?? ''),
                'designation' => (string) ($account['teacher_designation'] ?? ''),
            ],
            'permissions' => [
                'modules' => isHeadAdmin($account) ? ['*'] : decodedAdminModules($account),
                'can_view_all_attendance' => $canViewAllAttendance,
                'can_view_all_marksheets' => canViewAllMarksheets($account),
                'can_manage_notices' => canAccessModule('notices', $account),
                'can_manage_calendar' => canAccessModule('events', $account),
                'can_manage_headlines' => canAccessModule('headlines', $account),
            ],
            'session_year' => $sessionYear,
            'assigned_classes' => array_values($classes),
            'assigned_subject_classes' => $assignedSubjects,
        ],
    ]);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'account summary');
}
