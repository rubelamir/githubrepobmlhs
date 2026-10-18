<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/school.php';
require_once dirname(__DIR__, 2) . '/shared/academics.php';
require_once dirname(__DIR__, 2) . '/shared/schema.php';
require_once dirname(__DIR__, 2) . '/shared/public-features.php';
require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/deployment-config.php';
require_once dirname(__DIR__, 2) . '/shared/sms.php';
require_once dirname(__DIR__, 2) . '/shared/audit.php';

startAppSession();

function getDatabaseConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databaseConfig = deploymentDatabaseSettings();
    $host = $databaseConfig['host'];
    $database = $databaseConfig['name'];
    $username = $databaseConfig['username'];
    $password = $databaseConfig['password'];

    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function isTeacherActiveStatus(mixed $status): bool
{
    return (int) $status === 1;
}

function establishAdminTeacherSession(int $teacherId, string $teacherMobile): void
{
    session_regenerate_id(true);
    $_SESSION['id'] = (string) $teacherId;
    $_SESSION['teacher_mobile'] = $teacherMobile;
}

function clearAdminTeacherSession(): void
{
    unset($_SESSION['id'], $_SESSION['teacher_mobile']);
}

function isAuthenticated(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $hasId = !empty($_SESSION['id']) && ctype_digit((string) $_SESSION['id']);
    $hasMobile = !empty($_SESSION['teacher_mobile']) && is_string($_SESSION['teacher_mobile']);
    if (!$hasId && !$hasMobile) {
        return false;
    }

    $teacher = getAuthenticatedTeacher();
    if ($teacher === null || !isTeacherActiveStatus($teacher['teacher_status'] ?? 0)) {
        clearAdminTeacherSession();
        return false;
    }

    // Keep both session keys in sync for dist + bmlhs interoperability.
    $_SESSION['id'] = (string) $teacher['id'];
    $_SESSION['teacher_mobile'] = (string) $teacher['teacher_mobile'];

    return true;
}

function getAuthenticatedTeacher(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    try {
        $db = getDatabaseConnection();
        if (!empty($_SESSION['id']) && ctype_digit((string) $_SESSION['id'])) {
            $statement = $db->prepare(
                'SELECT *
                 FROM teacher_info
                 WHERE id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => (int) $_SESSION['id']]);
            $teacher = $statement->fetch();
            if ($teacher) {
                return $teacher;
            }
        }

        if (!empty($_SESSION['teacher_mobile']) && is_string($_SESSION['teacher_mobile'])) {
            $statement = $db->prepare(
                'SELECT *
                 FROM teacher_info
                 WHERE teacher_mobile = :mobile
                 LIMIT 1'
            );
            $statement->execute(['mobile' => $_SESSION['teacher_mobile']]);
            $teacher = $statement->fetch();

            return $teacher ?: null;
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
    }

    return null;
}

function getDesignationOptions(): array
{
    return [
        'head_teacher' => 'Head Teacher',
        'assistant_head_teacher' => 'Asst. Head Teacher',
        'senior_teacher' => 'Senior Teacher',
        'assistant_teacher' => 'Assistant Teacher',
        'office_assistant_cum_computer_perator' => 'Office Asst. Cum. Computer Operator',
        'computer_lab_operator' => 'Computer Lab Operator',
        'office_assistant' => 'Office Assistant',
        'security_guard' => 'Security Guard',
        'night_guard' => 'Night Guard',
        'cleaner' => 'Cleaner',
        'nurse_maid' => 'Nurse Maid',
    ];
}

function getDesignationLabel(string $designation): string
{
    return getDesignationOptions()[$designation] ?? $designation;
}

function panelRoleOptions(): array
{
    return [
        'head' => 'Head Teacher (full admin)',
        'admin' => 'Admin (selected modules)',
        'teacher' => 'Regular teacher',
    ];
}

function panelModuleGroups(): array
{
    return [
        'Academic structure' => [
            'classes' => 'Classes',
            'sections' => 'Sections / Groups',
            'subjects' => 'Subjects',
            'exams' => 'Exams',
            'periods' => 'Periods',
        ],
        'Student management' => [
            'admissions' => 'Admissions',
            'students' => 'Student list',
            'attendance' => 'Attendance',
            'promotions' => 'Class promotion',
            'results' => 'Mark Entry',
            'result-publication' => 'Public result publication',
            'tabulation-sheet' => 'Tabulation sheet',
            'routine' => 'Class routine',
            'exam-routine' => 'Exam routine',
            'admit-card' => 'Admit card',
            'id-cards' => 'ID Card',
            'seat-level' => 'Seat Label',
            'testimonials' => 'Testimonial',
            'certificates' => 'Certificates',
        ],
        'Staff' => [
            'teachers' => 'Teachers & Staff',
            'committee' => 'Managing Committee',
            'retirement-teachers' => 'Former Teachers',
        ],
        'Website content' => [
            'school-settings' => 'School Settings',
            'nav-menus' => 'Navigation Menus',
            'notices' => 'Notices',
            'headlines' => 'Notice Headlines',
            'events' => 'Events',
            'carousel' => 'Homepage Slider',
            'cms-gallery' => 'Photo Gallery',
            'downloads' => 'Downloads',
            'history' => 'School History',
            'messages' => 'President & HT Messages',
            'homepage-cards' => 'Homepage Cards',
            'homepage-links' => 'Homepage Links',
            'ssc-results' => 'SSC Results Chart',
        ],
    ];
}

function allAssignablePanelModules(): array
{
    $modules = [];
    foreach (panelModuleGroups() as $group) {
        foreach ($group as $key => $label) {
            $modules[$key] = $label;
        }
    }

    return $modules;
}

function currentPanelRole(?array $teacher = null): string
{
    $teacher ??= getAuthenticatedTeacher();
    $role = strtolower(trim((string) ($teacher['panel_role'] ?? '')));
    if (in_array($role, ['head', 'admin', 'teacher'], true)) {
        return $role;
    }

    if (($teacher['teacher_designation'] ?? '') === 'head_teacher') {
        return 'head';
    }

    return 'teacher';
}

function isHeadAdmin(?array $teacher = null): bool
{
    return currentPanelRole($teacher) === 'head';
}

function isHeadTeacher(?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }

    return ($teacher['teacher_designation'] ?? '') === 'head_teacher';
}

function canAccessStudentDetailPages(?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }

    return isHeadTeacher($teacher) || isAssignedClassTeacher($teacher);
}

function decodedAdminModules(?array $teacher = null): array
{
    $teacher ??= getAuthenticatedTeacher();
    $raw = trim((string) ($teacher['admin_modules'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static fn ($item): bool => is_string($item) && $item !== ''));
}

function canAccessModule(string $module, ?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }

    $module = trim($module);
    if ($module === '' || $module === 'dashboard' || $module === 'profile' || $module === 'change-password' || $module === 'exam-routine') {
        return true;
    }

    if (in_array($module, ['student-details-list', 'student-profiles', 'student-profile'], true)) {
        return canAccessStudentDetailPages($teacher);
    }

    if ($module === 'student-list') {
        return canManageModule('students', $teacher) || isAssignedClassTeacher($teacher, (string) date('Y'));
    }

    if ($module === 'student-form') {
        return canManageModule('admissions', $teacher) || isAssignedClassTeacher($teacher, (string) date('Y'));
    }

    if ($module === 'admin-roles' || $module === 'class-teachers') {
        return isHeadAdmin($teacher);
    }

    $role = currentPanelRole($teacher);
    if ($role === 'head') {
        return true;
    }

    if ($role === 'admin') {
        $assigned = decodedAdminModules($teacher);
        if (in_array('*', $assigned, true) || in_array($module, $assigned, true)) {
            return true;
        }
    }

    if ($role === 'admin') {
        return false;
    }

    if ($role === 'teacher' && $module === 'results') {
        return true;
    }

    return in_array($module, ['attendance', 'admit-card', 'id-cards', 'seat-level'], true) && isAssignedClassTeacher($teacher);
}

function canManageModule(string $module, ?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }

    $role = currentPanelRole($teacher);
    if ($role === 'head') {
        return true;
    }
    if ($role !== 'admin') {
        return false;
    }
    $assigned = decodedAdminModules($teacher);

    return in_array('*', $assigned, true) || in_array($module, $assigned, true);
}

/** Permanent student delete: head/admin with students or admissions — not class teachers. */
function canDeleteStudentRecords(?array $teacher = null): bool
{
    return canManageModule('students', $teacher) || canManageModule('admissions', $teacher);
}

function canViewAllMarksheets(?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    $role = currentPanelRole($teacher);

    return $role === 'head' || ($role === 'admin' && canAccessModule('results', $teacher));
}

function canViewAllAttendance(?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    $role = currentPanelRole($teacher);
    if ($role === 'head') {
        return true;
    }
    if ($role !== 'admin') {
        return false;
    }
    $assigned = decodedAdminModules($teacher);

    return in_array('*', $assigned, true) || in_array('attendance', $assigned, true);
}

function isAssignedClassTeacher(?array $teacher = null, string $sessionYear = ''): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }

    try {
        return classTeacherAssignments(
            getDatabaseConnection(),
            (string) ($teacher['teacher_mobile'] ?? ''),
            $sessionYear !== '' ? $sessionYear : (string) date('Y')
        ) !== [];
    } catch (PDOException $exception) {
        error_log($exception->getMessage());

        return false;
    }
}

function canTakeAttendanceForClass(
    PDO $db,
    int $classId,
    int $sectionId,
    string $sessionYear,
    ?array $teacher = null
): bool {
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }
    if (canViewAllAttendance($teacher)) {
        return true;
    }
    if ($classId < 1) {
        return false;
    }

    foreach (classTeacherAssignments($db, (string) ($teacher['teacher_mobile'] ?? ''), $sessionYear) as $row) {
        if ((int) $row['class_id'] !== $classId) {
            continue;
        }
        $assignedSection = (int) $row['section_id'];
        if ($assignedSection === 0 || $assignedSection === $sectionId) {
            return true;
        }
    }

    return false;
}

function classTeacherScope(PDO $db, ?array $teacher, string $sessionYear): array
{
    $scope = ['entire' => [], 'sections' => []];
    foreach (classTeacherAssignments($db, (string) ($teacher['teacher_mobile'] ?? ''), $sessionYear) as $row) {
        $classId = (int) $row['class_id'];
        $sectionId = (int) $row['section_id'];
        if ($sectionId === 0) {
            $scope['entire'][$classId] = true;
        } else {
            $scope['sections'][$classId][$sectionId] = true;
        }
    }

    return $scope;
}

function classTeacherScopeAllows(array $scope, int $classId, int $sectionId): bool
{
    return isset($scope['entire'][$classId]) || ($sectionId > 0 && isset($scope['sections'][$classId][$sectionId]));
}

function panelSeesAllStudents(?array $teacher = null): bool
{
    return canManageModule('students', $teacher);
}

/** Full class/section catalog via academic-api (not class-teacher / subject-only staff). */
function panelSeesFullClassCatalog(?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }
    if (isHeadAdmin($teacher)) {
        return true;
    }

    return canManageModule('students', $teacher)
        || canManageModule('admissions', $teacher)
        || canManageModule('classes', $teacher)
        || canManageModule('sections', $teacher)
        || canManageModule('subjects', $teacher)
        || canManageModule('routine', $teacher)
        || canManageModule('exam-routine', $teacher)
        || canManageModule('promotions', $teacher)
        || canManageModule('tabulation-sheet', $teacher)
        || canManageModule('admit-card', $teacher)
        || canManageModule('id-cards', $teacher)
        || canManageModule('certificates', $teacher)
        || canManageModule('school-settings', $teacher)
        || canViewAllAttendance($teacher)
        || canViewAllMarksheets($teacher);
}

/**
 * @return array<int, true>
 */
function teacherVisibleClassIdMap(PDO $db, ?array $teacher, string $sessionYear): array
{
    $teacher ??= getAuthenticatedTeacher();
    $ids = [];
    $mobile = (string) ($teacher['teacher_mobile'] ?? '');
    foreach (classTeacherAssignments($db, $mobile, $sessionYear) as $row) {
        $classId = (int) ($row['class_id'] ?? 0);
        if ($classId > 0) {
            $ids[$classId] = true;
        }
    }
    foreach (teacherAssignedSubjects($db, $mobile) as $subject) {
        $classId = (int) ($subject['class_id'] ?? 0);
        if ($classId > 0) {
            $ids[$classId] = true;
        }
    }

    return $ids;
}

function classesVisibleToPanel(PDO $db, ?array $teacher, string $sessionYear, bool $admissionOnly = false): array
{
    $teacher ??= getAuthenticatedTeacher();
    $all = $admissionOnly ? admissionClasses($db) : schoolClasses($db, true);
    if (panelSeesFullClassCatalog($teacher)) {
        return $all;
    }
    $allowed = teacherVisibleClassIdMap($db, $teacher, $sessionYear);

    return array_values(array_filter(
        $all,
        static fn (array $class): bool => isset($allowed[(int) $class['id']])
    ));
}

function sectionsVisibleToPanel(
    PDO $db,
    int $classId,
    ?array $teacher,
    string $sessionYear,
    bool $activeOnly = false,
    bool $forAttendance = false
): array {
    $teacher ??= getAuthenticatedTeacher();
    if ($forAttendance) {
        return sectionsForAttendance($db, $classId, $teacher, $sessionYear);
    }

    $all = $classId > 0 ? schoolSections($db, $classId, $activeOnly) : [];
    if (panelSeesFullClassCatalog($teacher) || $classId < 1) {
        return $all;
    }

    $scope = classTeacherScope($db, $teacher, $sessionYear);
    if (isset($scope['entire'][$classId])) {
        return $all;
    }

    $allowedSections = $scope['sections'][$classId] ?? [];
    $mobile = (string) ($teacher['teacher_mobile'] ?? '');
    foreach (teacherAssignedSubjects($db, $mobile) as $subject) {
        if ((int) ($subject['class_id'] ?? 0) !== $classId) {
            continue;
        }
        $sectionId = (int) ($subject['section_id'] ?? 0);
        if ($sectionId === 0) {
            return $all;
        }
        $allowedSections[$sectionId] = true;
    }

    if ($allowedSections === []) {
        return [];
    }

    return array_values(array_filter(
        $all,
        static fn (array $section): bool => isset($allowedSections[(int) $section['id']])
    ));
}

/**
 * @param list<string> $where
 */
function applyAssignedClassStudentScope(PDO $db, array &$where, string $sessionYear, int $classId, int $sectionId, ?array $teacher = null): void
{
    $teacher ??= getAuthenticatedTeacher();
    if (panelSeesAllStudents($teacher)) {
        return;
    }

    $scope = classTeacherScope($db, $teacher, $sessionYear);
    if ($classId < 1 || (!isset($scope['entire'][$classId]) && !isset($scope['sections'][$classId]))) {
        $where[] = '1 = 0';

        return;
    }

    if ($sectionId < 1 && !isset($scope['entire'][$classId])) {
        $allowedSections = array_map('intval', array_keys($scope['sections'][$classId] ?? []));
        $where[] = 's.sections IN (' . ($allowedSections === [] ? '0' : implode(', ', $allowedSections)) . ')';
    }
}

function panelMayViewStudentRecord(PDO $db, ?array $student, ?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if (panelSeesAllStudents($teacher)) {
        return true;
    }
    if (!is_array($student)) {
        return false;
    }

    $scope = classTeacherScope($db, $teacher, (string) ($student['session_year'] ?? ''));

    return classTeacherScopeAllows($scope, (int) ($student['cls'] ?? 0), (int) ($student['sections'] ?? 0));
}

function canEditClassSubject(array $subject, ?array $teacher = null): bool
{
    $teacher ??= getAuthenticatedTeacher();
    if ($teacher === null) {
        return false;
    }

    // Head/admin may view every marksheet, but only the assigned subject teacher may write marks.
    return subjectAssignedToTeacher($subject, (string) ($teacher['teacher_mobile'] ?? ''));
}

function currentScriptPanelModule(): ?string
{
    $name = pathinfo(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME);
    $map = [
        'index' => 'dashboard',
        'add_teacher' => 'teachers',
        'view_all_teacherlist' => 'teachers',
        'student-form' => 'student-form',
        'students' => 'student-list',
        'admission-tokens' => 'admissions',
        'admin-roles' => 'admin-roles',
        'class-teachers' => 'class-teachers',
        'academic-api' => null,
        'private-media' => null,
        'audit-log' => null,
        'database-backup' => null,
        'bulk-sms' => null,
        'contact-inbox' => null,
        'academic-calendar' => 'events',
        'meritorious-students' => 'students',
        'syllabus' => 'downloads',
        'profile' => 'profile',
        'change-password' => 'change-password',
        // Own staff ID card is allowed for every logged-in teacher; page enforces own-or-teachers access.
        'teacher-id-card' => null,
    ];
    if (array_key_exists($name, $map)) {
        return $map[$name];
    }

    return $name !== '' ? $name : null;
}

function denyPanelAccess(string $message = 'You do not have permission to use that part of the admin panel.'): void
{
    redirectWithMessage(adminUrl('index.php'), 'danger', $message);
}

function requirePanelModule(string $module): void
{
    if (!canAccessModule($module)) {
        denyPanelAccess();
    }
}

function headAdminCount(PDO $db): int
{
    if (!tableExists($db, 'teacher_info') || !columnExists($db, 'teacher_info', 'panel_role')) {
        return 0;
    }

    return (int) $db->query("SELECT COUNT(*) FROM teacher_info WHERE panel_role = 'head'")->fetchColumn();
}

function sanitizeAdminModules(array $modules): array
{
    $allowed = array_keys(allAssignablePanelModules());
    $clean = [];
    foreach ($modules as $module) {
        if (!is_string($module)) {
            continue;
        }
        if ($module === '*') {
            return ['*'];
        }
        if (in_array($module, $allowed, true)) {
            $clean[] = $module;
        }
    }

    return array_values(array_unique($clean));
}

function saveTeacherPanelRole(PDO $db, int $teacherId, string $role, array $modules, int $actorId): void
{
    if (!in_array($role, ['head', 'admin', 'teacher'], true)) {
        throw new RuntimeException('Invalid admin role.');
    }
    if ($role !== 'admin') {
        $modules = [];
    } else {
        $modules = sanitizeAdminModules($modules);
    }

    if ($role !== 'head' && $teacherId === $actorId && isHeadAdmin() && headAdminCount($db) <= 1) {
        throw new RuntimeException('Assign another Head Teacher before changing your own role.');
    }

    $payload = [
        'role' => $role,
        'id' => $teacherId,
    ];
    $sql = 'UPDATE teacher_info SET panel_role = :role';
    if (columnExists($db, 'teacher_info', 'admin_modules')) {
        $sql .= ', admin_modules = :modules';
        $payload['modules'] = $modules === [] ? null : json_encode(array_values($modules), JSON_UNESCAPED_UNICODE);
    }
    $sql .= ' WHERE id = :id LIMIT 1';
    $db->prepare($sql)->execute($payload);
}

function requireAuthentication(string $loginPath): void
{
    if (!isAuthenticated()) {
        header('Location: ' . $loginPath, true, 302);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        requireValidCsrf();
    }

    try {
        ensureSchoolSchema(getDatabaseConnection());
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
    }

    $module = currentScriptPanelModule();
    if ($module !== null && $module !== 'dashboard' && $module !== 'profile' && $module !== 'change-password' && $module !== 'exam-routine') {
        requirePanelModule($module);
    }
}
