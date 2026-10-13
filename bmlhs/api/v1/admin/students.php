<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function mobileAdminStudentsRequiredModule(string $method, string $action): ?string
{
    return match (strtoupper($method) . ':' . $action) {
        'GET:list', 'GET:detail', 'GET:admission_applications', 'GET:admission_options',
        'GET:create_options', 'GET:roll_check', 'POST:create', 'POST:update', 'POST:reset_password',
        'POST:approve_admission', 'POST:reject_admission', 'POST:restore_admission' => 'students',
        'GET:promotion_candidates', 'GET:promotion_options', 'POST:promote' => 'promotions',
        default => null,
    };
}

function mobileAdminStudentsPositiveId(mixed $value): ?int
{
    if (!is_int($value) && !is_string($value)) {
        return null;
    }
    $text = (string) $value;
    if (preg_match('/^[1-9][0-9]{0,9}$/', $text) !== 1) {
        return null;
    }
    $id = filter_var($text, FILTER_VALIDATE_INT);

    return is_int($id) && $id <= 2147483647 ? $id : null;
}

function mobileAdminStudentsValidSession(string $session): bool
{
    return preg_match('/^(?:19|20|21)[0-9]{2}$/', $session) === 1
        && (int) $session >= 2000
        && (int) $session <= 2199;
}

function mobileAdminStudentsValidRoll(string $roll): bool
{
    if (preg_match('/^[1-9][0-9]{0,9}$/', $roll) !== 1) {
        return false;
    }

    return (int) $roll <= 2147483647;
}

function mobileAdminStudentsPasswordHash(mixed $password): string
{
    if (!is_string($password) || strlen($password) < 8 || strlen($password) > 1024) {
        throw new InvalidArgumentException('Password must be between 8 and 1024 bytes.');
    }

    return hashPassword($password);
}

function mobileAdminStudentsEditableFields(): array
{
    return [
        'name_ban', 'name_eng', 'fname_ban', 'fname_eng', 'father_nid_no',
        'mname_ban', 'mname_eng', 'mother_nid_no', 'gender', 'religion',
        'birth_date', 'birth_reg_no', 'mobile', 'division', 'district',
        'upazilla', 'post_office', 'post_office_en', 'village', 'village_en',
        'house_name', 'last_class', 'exam_roll', 'passing_year',
        'scholarship_stipend_info', 'last_school_name',
    ];
}

function mobileAdminStudentsValidateClass(PDO $db, int $classId): array
{
    if ($classId < 1) {
        throw new InvalidArgumentException('A valid class is required.');
    }
    $classColumns = columnExists($db, 'cls', 'class_name_ban')
        ? 'id, class_name, class_name_ban'
        : 'id, class_name';
    $sql = 'SELECT ' . $classColumns . ' FROM cls WHERE id = :id';
    if (columnExists($db, 'cls', 'is_active')) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $rows = fetchRows($db, $sql, ['id' => $classId]);
    if ($rows === []) {
        throw new InvalidArgumentException('The selected class is unavailable.');
    }

    return $rows[0];
}

function mobileAdminStudentsValidateSection(PDO $db, int $classId, string $sectionId): void
{
    $parsedSection = mobileAdminStudentsPositiveId($sectionId);
    if ($parsedSection === null) {
        throw new InvalidArgumentException('A valid section is required.');
    }
    $sql = 'SELECT id FROM sections WHERE id = :id AND class_id = :class_id';
    if (columnExists($db, 'sections', 'is_active')) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';
    if (fetchRows($db, $sql, ['id' => $parsedSection, 'class_id' => $classId]) === []) {
        throw new InvalidArgumentException('The selected section does not belong to this class.');
    }
}

function mobileAdminStudentsValidatePlacement(PDO $db, array $student): array
{
    if ((int) ($student['status'] ?? -1) !== studentStatusAdmitted()) {
        throw new LogicException('Only admitted students can be updated or promoted from an active class.');
    }
    $classId = mobileAdminStudentsPositiveId($student['cls'] ?? null);
    $section = trim((string) ($student['sections'] ?? ''));
    $session = trim((string) ($student['session_year'] ?? ''));
    $roll = trim((string) ($student['cls_roll'] ?? ''));
    if ($classId === null || !mobileAdminStudentsValidSession($session) || !mobileAdminStudentsValidRoll($roll)) {
        throw new InvalidArgumentException('The student has an invalid class, session or roll and cannot be changed safely.');
    }
    mobileAdminStudentsValidateClass($db, $classId);
    mobileAdminStudentsValidateSection($db, $classId, $section);

    return [
        'student_id' => (int) $student['id'],
        'cls' => $classId,
        'sections' => $section,
        'session_year' => $session,
        'cls_roll' => $roll,
    ];
}

function mobileAdminStudentsOrderedClassIds(PDO $db): array
{
    $classes = schoolClasses($db);
    $knownRanks = [
        'play' => 1, 'nursery' => 2, 'kg' => 3,
        'one' => 4, 'two' => 5, 'three' => 6, 'four' => 7,
        'five' => 8, 'six' => 9, 'seven' => 10, 'eight' => 11,
        'nine' => 12, 'ten' => 13, 'eleven' => 14, 'twelve' => 15,
    ];
    $aliases = [];
    $words = [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        11 => 'eleven', 12 => 'twelve',
    ];
    foreach ($words as $number => $word) {
        foreach ([(string) $number, 'class' . $number, 'grade' . $number, 'class' . $word, 'grade' . $word] as $alias) {
            $aliases[$alias] = $word;
        }
    }
    $ranked = [];
    foreach ($classes as $class) {
        $name = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) ($class['class_name'] ?? '')));
        $key = $aliases[$name] ?? $name;
        if (isset($knownRanks[$key])) {
            $ranked[] = ['id' => (int) $class['id'], 'rank' => $knownRanks[$key]];
        }
    }
    usort($ranked, static fn (array $left, array $right): int => $left['rank'] <=> $right['rank'] ?: $left['id'] <=> $right['id']);

    return array_column($ranked, 'id');
}

function mobileAdminStudentsIsClassTen(array $class): bool
{
    $name = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) ($class['class_name'] ?? '')));

    return in_array($name, ['ten', '10', 'class10', 'grade10', 'classten', 'gradeten'], true);
}

function mobileAdminStudentsPromotionOptions(PDO $db, int $studentId): array
{
    $students = fetchRows(
        $db,
        'SELECT id, cls, sections, session_year, cls_roll, status, ssc_batch_year
         FROM students_info WHERE id = :id LIMIT 1',
        ['id' => $studentId]
    );
    if ($students === []) {
        throw new OutOfBoundsException('Student not found.');
    }

    $student = $students[0];
    $status = (int) $student['status'];
    $classId = mobileAdminStudentsPositiveId($student['cls'] ?? null);
    if ($classId === null) {
        throw new InvalidArgumentException('The student has an invalid source class.');
    }
    $class = mobileAdminStudentsValidateClass($db, $classId);
    $sections = static function (int $forClass) use ($db): array {
        $sql = 'SELECT id, section_name, section_name_ban FROM sections WHERE class_id = :class_id';
        if (columnExists($db, 'sections', 'is_active')) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, id';
        if (!columnExists($db, 'sections', 'sort_order')) {
            $sql = 'SELECT id, section_name, section_name_ban FROM sections WHERE class_id = :class_id'
                . (columnExists($db, 'sections', 'is_active') ? ' AND is_active = 1' : '')
                . ' ORDER BY id';
        }
        $rows = fetchRows($db, $sql, ['class_id' => $forClass]);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return $rows;
    };
    $classOption = static fn (array $row, array $classSections): array => [
        'id' => (int) $row['id'],
        'name' => (string) ($row['class_name'] ?? ''),
        'name_ban' => (string) ($row['class_name_ban'] ?? ''),
        'sections' => $classSections,
    ];
    $modes = [];
    $destinations = [];
    $orderedIds = mobileAdminStudentsOrderedClassIds($db);

    if ($status === studentStatusAdmitted()) {
        $placement = mobileAdminStudentsValidatePlacement($db, $student);
        $position = array_search($placement['cls'], $orderedIds, true);
        if ($position !== false && isset($orderedIds[$position + 1])) {
            $nextId = (int) $orderedIds[$position + 1];
            $nextClass = mobileAdminStudentsValidateClass($db, $nextId);
            $nextClass['id'] = $nextId;
            $destinations[] = $classOption($nextClass, $sections($nextId));
            $modes[] = 'class';
        }
        if (mobileAdminStudentsIsClassTen($class)) {
            $modes[] = 'ssc';
        }
    } elseif ($status === studentStatusSscCandidate()) {
        mobileAdminStudentsValidateSection($db, $classId, (string) ($student['sections'] ?? ''));
        if (mobileAdminStudentsIsClassTen($class)) {
            $class['id'] = $classId;
            $destinations[] = $classOption($class, $sections($classId));
            $modes[] = 'ssc_return';
        }
    } else {
        throw new LogicException('This student is not eligible for promotion.');
    }

    return [
        'student_id' => $studentId,
        'status' => $status,
        'source_class' => (int) $classId,
        'source_section' => (string) ($student['sections'] ?? ''),
        'source_session' => trim((string) ($student['session_year'] ?? '')),
        'ssc_batch_year' => trim((string) ($student['ssc_batch_year'] ?? '')),
        'modes' => $modes,
        'destinations' => $destinations,
    ];
}

function mobileAdminStudentsAdmissionOptions(PDO $db, int $studentId): array
{
    $rows = fetchRows(
        $db,
        'SELECT id, cls, session_year, status, admission_token
         FROM students_info WHERE id = :id LIMIT 1',
        ['id' => $studentId]
    );
    if ($rows === []) {
        throw new OutOfBoundsException('Admission application not found.');
    }
    $application = $rows[0];
    if ((int) $application['status'] !== 0 || trim((string) ($application['admission_token'] ?? '')) === '') {
        throw new LogicException('Only pending online admission applications can be approved.');
    }
    $classId = mobileAdminStudentsPositiveId($application['cls'] ?? null);
    if ($classId === null) {
        throw new InvalidArgumentException('The application has no valid requested class.');
    }
    $class = mobileAdminStudentsValidateClass($db, $classId);
    $hasTokenTable = tableExists($db, 'token');
    if (!$hasTokenTable || fetchRows(
        $db,
        'SELECT token FROM token WHERE token = :token AND token_status = 1 LIMIT 1',
        ['token' => (string) $application['admission_token']]
    ) === []) {
        throw new LogicException('The application does not have a verified, consumed admission token.');
    }

    $sectionSql = 'SELECT id, section_name, section_name_ban FROM sections WHERE class_id = :class_id';
    if (columnExists($db, 'sections', 'is_active')) {
        $sectionSql .= ' AND is_active = 1';
    }
    $sectionSql .= columnExists($db, 'sections', 'sort_order') ? ' ORDER BY sort_order, id' : ' ORDER BY id';
    $sections = fetchRows($db, $sectionSql, ['class_id' => $classId]);
    foreach ($sections as &$section) {
        $section['id'] = (int) $section['id'];
    }
    unset($section);

    return [
        'student_id' => $studentId,
        'requested_class' => [
            'id' => $classId,
            'name' => (string) ($class['class_name'] ?? ''),
            'name_ban' => (string) ($class['class_name_ban'] ?? ''),
        ],
        'requested_session' => trim((string) ($application['session_year'] ?? '')),
        'sections' => $sections,
    ];
}

function mobileAdminStudentsCreateOptions(PDO $db): array
{
    $classes = [];
    foreach (schoolClasses($db, true) as $class) {
        $classId = (int) $class['id'];
        $sectionSql = 'SELECT id, section_name, section_name_ban FROM sections WHERE class_id = :class_id';
        if (columnExists($db, 'sections', 'is_active')) {
            $sectionSql .= ' AND is_active = 1';
        }
        $sectionSql .= columnExists($db, 'sections', 'sort_order') ? ' ORDER BY sort_order, id' : ' ORDER BY id';
        $sections = fetchRows($db, $sectionSql, ['class_id' => $classId]);
        foreach ($sections as &$section) {
            $section['id'] = (int) $section['id'];
        }
        unset($section);
        $classes[] = [
            'id' => $classId,
            'name' => (string) ($class['class_name'] ?? ''),
            'name_ban' => (string) ($class['class_name_ban'] ?? ''),
            'sections' => $sections,
        ];
    }

    return ['classes' => $classes];
}

function mobileAdminStudentsCheckRoll(PDO $db, array $query): array
{
    foreach (['class_id', 'section_id', 'session_year', 'roll'] as $field) {
        if (!is_string($query[$field] ?? null)) {
            throw new InvalidArgumentException('Class, section, session and roll are required for availability checks.');
        }
    }
    $classId = mobileAdminStudentsPositiveId($query['class_id']);
    $sectionId = mobileAdminStudentsPositiveId($query['section_id']);
    $session = trim($query['session_year']);
    $roll = trim($query['roll']);
    if (
        $classId === null || $sectionId === null
        || !mobileAdminStudentsValidSession($session)
        || !mobileAdminStudentsValidRoll($roll)
    ) {
        throw new InvalidArgumentException('Provide a valid class, section, session year and roll.');
    }

    mobileAdminStudentsValidateClass($db, $classId);
    mobileAdminStudentsValidateSection($db, $classId, (string) $sectionId);

    return [
        'available' => !activeStudentRollTaken($db, $classId, $session, (string) $sectionId, $roll),
    ];
}

function mobileAdminStudentsCreate(PDO $db, array $payload): array
{
    $classId = mobileAdminStudentsPositiveId($payload['class_id'] ?? null);
    $sectionId = mobileAdminStudentsPositiveId($payload['section_id'] ?? null);
    foreach (['session_year', 'roll', 'mobile', 'password', 'gender'] as $field) {
        if (!is_string($payload[$field] ?? null)) {
            throw new InvalidArgumentException('Session, roll, mobile, password and gender must be text values.');
        }
    }
    $session = trim($payload['session_year']);
    $roll = trim($payload['roll']);
    $passwordHash = mobileAdminStudentsPasswordHash($payload['password']);
    $mobile = trim($payload['mobile']);
    $mobileCore = mobileApiNormalizeMobile($mobile);
    $textFields = [
        'name_ban' => 255,
        'name_eng' => 255,
        'fname_ban' => 255,
        'fname_eng' => 255,
        'mname_ban' => 255,
        'mname_eng' => 255,
        'birth_date' => 10,
        'birth_reg_no' => 255,
        'religion' => 255,
        'village' => 255,
        'village_en' => 255,
        'post_office' => 255,
        'post_office_en' => 255,
        'house_name' => 255,
        'last_class' => 255,
        'exam_roll' => 255,
        'passing_year' => 255,
        'scholarship_stipend_info' => 255,
        'last_school_name' => 255,
    ];
    $values = [];
    foreach ($textFields as $field => $maxLength) {
        $value = $payload[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('Student details must be text values.');
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException('Student details exceed the allowed length.');
        }
        $values[$field] = $value;
    }
    $gender = $payload['gender'];
    if (
        $classId === null || $sectionId === null
        || !mobileAdminStudentsValidSession($session)
        || !mobileAdminStudentsValidRoll($roll)
        || $mobile === '' || strlen($mobile) > 40 || strlen($mobileCore) < 6
        || !in_array($gender, ['male', 'female'], true)
        || $values['name_ban'] === '' || $values['name_eng'] === ''
        || $values['fname_ban'] === '' || $values['fname_eng'] === ''
    ) {
        throw new InvalidArgumentException('Provide valid class, section, session, roll, names, gender, mobile and a password of at least 8 characters.');
    }
    if ($values['religion'] !== '' && !in_array($values['religion'], ['Islam', 'Hindu', 'Buddhist', 'Christian'], true)) {
        throw new InvalidArgumentException('Choose a supported religion.');
    }
    if ($values['birth_date'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['birth_date']);
        if (!$date || $date->format('Y-m-d') !== $values['birth_date']) {
            throw new InvalidArgumentException('Birth date must use YYYY-MM-DD format.');
        }
    }

    $values = array_merge($values, [
        'admission_token' => '',
        'cls_roll' => $roll,
        'cls' => $classId,
        'sections' => (string) $sectionId,
        'session_year' => $session,
        'gender' => $gender,
        'fprofession' => '',
        'father_nid_no' => '',
        'mprofession' => '',
        'mother_nid_no' => '',
        'guardian_name' => '',
        'guardian_relation' => '',
        'guardian_mobile' => '',
        'division' => 0,
        'district' => 0,
        'upazilla' => 0,
        'unions' => 0,
        'last_exam' => '',
        'exam_result' => '',
        'mobile' => $mobile,
        'pass' => $passwordHash,
        'student_pic' => '',
        'birth_reg_pic' => '',
        'status' => studentStatusAdmitted(),
    ]);

    $db->beginTransaction();
    try {
        mobileAdminStudentsValidateClass($db, $classId);
        mobileAdminStudentsValidateSection($db, $classId, (string) $sectionId);
        $classLock = $db->prepare('SELECT id FROM cls WHERE id = :id FOR UPDATE');
        $classLock->execute(['id' => $classId]);
        assertActiveStudentRollAvailable($db, $classId, $session, (string) $sectionId, $roll);

        $columns = $db->query('SHOW COLUMNS FROM students_info')->fetchAll(PDO::FETCH_ASSOC);
        $knownColumns = [];
        foreach ($columns as $column) {
            $name = (string) $column['Field'];
            $knownColumns[$name] = true;
            if (
                (string) $column['Null'] === 'NO'
                && $column['Default'] === null
                && stripos((string) $column['Extra'], 'auto_increment') === false
                && !array_key_exists($name, $values)
            ) {
                throw new LogicException('The installed student schema requires additional fields before direct admissions can be used.');
            }
        }
        foreach (array_keys($values) as $column) {
            if (!isset($knownColumns[$column])) {
                unset($values[$column]);
            }
        }
        if (!isset($knownColumns['status'], $knownColumns['pass'])) {
            throw new LogicException('Student admission status or password fields are unavailable in the installed schema.');
        }

        $insertColumns = array_keys($values);
        $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $insertColumns);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $insertColumns);
        $statement = $db->prepare(
            'INSERT INTO students_info (' . implode(', ', $quotedColumns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($values);
        $studentId = (int) $db->lastInsertId();
        upsertStudentEnrollment($db, [
            'student_id' => $studentId,
            'session_year' => $session,
            'class_id' => $classId,
            'section_id' => $sectionId,
            'cls_roll' => $roll,
            'enrollment_status' => 'active',
            'source' => 'admin_admission',
        ]);
        $student = mobileAdminStudentsDetail($db, $studentId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return $student;
}

function mobileAdminStudentsResetPassword(PDO $db, int $studentId, mixed $password): void
{
    $passwordHash = mobileAdminStudentsPasswordHash($password);
    $db->beginTransaction();
    try {
        $rows = fetchRows(
            $db,
            'SELECT id FROM students_info WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $studentId]
        );
        if ($rows === []) {
            throw new OutOfBoundsException('Student not found.');
        }
        if (!columnExists($db, 'students_info', 'pass')) {
            throw new LogicException('Student passwords are unavailable in the installed schema.');
        }
        $db->prepare('UPDATE students_info SET pass = :password WHERE id = :id')
            ->execute(['password' => $passwordHash, 'id' => $studentId]);
        if (tableExists($db, 'mobile_api_tokens')) {
            $db->prepare(
                'UPDATE mobile_api_tokens
                 SET revoked_at = UTC_TIMESTAMP()
                 WHERE account_type = \'student\' AND account_id = :id AND revoked_at IS NULL'
            )->execute(['id' => $studentId]);
        }
        if (tableExists($db, 'mobile_api_refresh_tokens')) {
            $db->prepare(
                'UPDATE mobile_api_refresh_tokens
                 SET revoked_at = UTC_TIMESTAMP()
                 WHERE account_type = \'student\' AND account_id = :id AND revoked_at IS NULL'
            )->execute(['id' => $studentId]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function mobileAdminStudentsApproveAdmission(PDO $db, int $studentId, array $payload): array
{
    $sectionId = mobileAdminStudentsPositiveId($payload['section_id'] ?? null);
    $session = trim((string) ($payload['session_year'] ?? ''));
    $roll = trim((string) ($payload['roll'] ?? ''));
    if ($sectionId === null || !mobileAdminStudentsValidSession($session) || !mobileAdminStudentsValidRoll($roll)) {
        throw new InvalidArgumentException('Choose a valid section, session year and roll to approve this application.');
    }

    $db->beginTransaction();
    try {
        $rows = fetchRows(
            $db,
            'SELECT id, cls, sections, session_year, cls_roll, status, admission_token
             FROM students_info WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $studentId]
        );
        if ($rows === []) {
            throw new OutOfBoundsException('Admission application not found.');
        }
        $application = $rows[0];
        if ((int) $application['status'] !== 0 || trim((string) ($application['admission_token'] ?? '')) === '') {
            throw new LogicException('Only pending online admission applications can be approved.');
        }
        $classId = mobileAdminStudentsPositiveId($application['cls'] ?? null);
        if ($classId === null) {
            throw new InvalidArgumentException('The application has no valid requested class.');
        }
        $class = mobileAdminStudentsValidateClass($db, $classId);
        if (!tableExists($db, 'token') || fetchRows(
            $db,
            'SELECT token FROM token WHERE token = :token AND token_status = 1 LIMIT 1',
            ['token' => (string) $application['admission_token']]
        ) === []) {
            throw new LogicException('The application does not have a verified, consumed admission token.');
        }
        mobileAdminStudentsValidateSection($db, $classId, (string) $sectionId);
        $classLock = $db->prepare('SELECT id FROM cls WHERE id = :id FOR UPDATE');
        $classLock->execute(['id' => $classId]);
        assertActiveStudentRollAvailable($db, $classId, $session, (string) $sectionId, $roll, $studentId);

        $update = $db->prepare(
            'UPDATE students_info
             SET sections = :sections, session_year = :session_year, cls_roll = :roll, status = :status
             WHERE id = :id AND status = 0'
        );
        $update->execute([
            'sections' => (string) $sectionId,
            'session_year' => $session,
            'roll' => (int) $roll,
            'status' => studentStatusAdmitted(),
            'id' => $studentId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The admission application could not be approved.');
        }
        $approved = mobileAdminStudentsDetail($db, $studentId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return $approved;
}

function mobileAdminStudentsSetAdmissionStatus(PDO $db, int $studentId, int $fromStatus, int $toStatus): void
{
    $db->beginTransaction();
    try {
        $rows = fetchRows(
            $db,
            'SELECT id, status, admission_token
             FROM students_info WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $studentId]
        );
        if ($rows === []) {
            throw new OutOfBoundsException('Admission application not found.');
        }
        $application = $rows[0];
        if ((int) $application['status'] !== $fromStatus || trim((string) ($application['admission_token'] ?? '')) === '') {
            throw new LogicException(
                $fromStatus === 0
                    ? 'Only pending online admission applications can be rejected.'
                    : 'Only rejected online admission applications can be restored.'
            );
        }
        if (!tableExists($db, 'token') || fetchRows(
            $db,
            'SELECT token FROM token WHERE token = :token AND token_status = 1 LIMIT 1',
            ['token' => (string) $application['admission_token']]
        ) === []) {
            throw new LogicException('The application does not have a verified, consumed admission token.');
        }

        $update = $db->prepare(
            'UPDATE students_info SET status = :to_status WHERE id = :id AND status = :from_status'
        );
        $update->execute([
            'to_status' => $toStatus,
            'id' => $studentId,
            'from_status' => $fromStatus,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The admission status could not be changed.');
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function mobileAdminStudentsDetail(PDO $db, int $studentId): array
{
    $columns = array_merge(
        ['id', 'cls', 'sections', 'cls_roll', 'session_year', 'status', 'ssc_batch_year', 'ssc_passing_year'],
        mobileAdminStudentsEditableFields(),
        ['student_pic']
    );
    $select = [];
    foreach (array_unique($columns) as $column) {
        if (columnExists($db, 'students_info', $column)) {
            $select[] = 's.`' . $column . '`';
        }
    }
    $rows = fetchRows(
        $db,
        'SELECT ' . implode(', ', $select) . ',
                c.class_name, c.class_name_ban,
                sec.section_name, sec.section_name_ban
         FROM students_info s
         LEFT JOIN cls c ON c.id = s.cls
         LEFT JOIN sections sec ON CAST(sec.id AS CHAR) = s.sections
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $studentId]
    );
    if ($rows === []) {
        throw new OutOfBoundsException('Student not found.');
    }

    $student = $rows[0];
    $student['id'] = (int) $student['id'];
    $student['cls'] = (int) $student['cls'];
    $student['status'] = (int) $student['status'];
    $photo = trim((string) ($student['student_pic'] ?? ''));
    unset($student['student_pic']);
    $student['photo_url'] = $photo !== '' ? studentPhotoUrl($photo) : null;

    return $student;
}

function mobileAdminStudentsList(
    PDO $db,
    array $query,
    bool $promotionCandidates = false,
    bool $admissionApplications = false
): array
{
    $limit = mobileAdminStudentsPositiveId($query['limit'] ?? '50');
    $limit = $limit === null ? 50 : min($limit, 100);
    $offsetText = (string) ($query['offset'] ?? '0');
    if (preg_match('/^(?:0|[1-9][0-9]{0,5})$/', $offsetText) !== 1 || (int) $offsetText > 100000) {
        throw new InvalidArgumentException('Offset must be between 0 and 100000.');
    }
    $offset = (int) $offsetText;
    $where = [];
    $params = [];

    if ($admissionApplications) {
        if (!columnExists($db, 'students_info', 'admission_token')) {
            throw new LogicException('Online admission applications are unavailable in the installed schema.');
        }
        if (!tableExists($db, 'token')) {
            throw new LogicException('Online admission tokens are unavailable in the installed schema.');
        }
        $where[] = 's.status = 0 AND s.admission_token IS NOT NULL AND s.admission_token <> \'\'';
        $where[] = 'EXISTS (
            SELECT 1 FROM token admission_token
            WHERE admission_token.token = s.admission_token AND admission_token.token_status = 1
        )';
    } elseif ($promotionCandidates) {
        $where[] = 's.status IN (:admitted, :ssc)';
        $params['admitted'] = studentStatusAdmitted();
        $params['ssc'] = studentStatusSscCandidate();
    } elseif (isset($query['status']) && (string) $query['status'] !== '' && (string) $query['status'] !== 'all') {
        $status = filter_var($query['status'], FILTER_VALIDATE_INT);
        if (!is_int($status) || !in_array($status, [0, 1, 2, 3, 4], true)) {
            throw new InvalidArgumentException('Status must be all or one of 0–4.');
        }
        $where[] = 's.status = :status';
        $params['status'] = $status;
    } else {
        $where[] = 's.status = :status';
        $params['status'] = studentStatusAdmitted();
    }

    $classInput = $query['class_id'] ?? $query['cls'] ?? null;
    $classId = $classInput === null || (string) $classInput === '' ? null : mobileAdminStudentsPositiveId($classInput);
    if ($classInput !== null && (string) $classInput !== '' && $classId === null) {
        throw new InvalidArgumentException('Class ID must be a positive integer.');
    }
    if ($classId !== null) {
        mobileAdminStudentsValidateClass($db, $classId);
        $where[] = 's.cls = :cls';
        $params['cls'] = $classId;
    }

    $sectionInput = $query['section_id'] ?? $query['sections'] ?? null;
    $sectionId = $sectionInput === null || (string) $sectionInput === '' ? null : mobileAdminStudentsPositiveId($sectionInput);
    if ($sectionInput !== null && (string) $sectionInput !== '' && $sectionId === null) {
        throw new InvalidArgumentException('Section ID must be a positive integer.');
    }
    if ($sectionId !== null) {
        $sectionRows = fetchRows($db, 'SELECT class_id FROM sections WHERE id = :id LIMIT 1', ['id' => $sectionId]);
        if ($sectionRows === []) {
            throw new InvalidArgumentException('The selected section is unavailable.');
        }
        $sectionClassId = (int) $sectionRows[0]['class_id'];
        if ($classId !== null && $classId !== $sectionClassId) {
            throw new InvalidArgumentException('The section does not belong to the selected class.');
        }
        $where[] = 's.sections = :sections';
        $params['sections'] = (string) $sectionId;
    }

    $session = trim((string) ($query['session_year'] ?? ''));
    if ($session !== '') {
        if (!mobileAdminStudentsValidSession($session)) {
            throw new InvalidArgumentException('Session year must be a four-digit year.');
        }
        $where[] = 's.session_year = :session_year';
        $params['session_year'] = $session;
    }

    $search = trim((string) ($query['q'] ?? ''));
    if (mb_strlen($search, 'UTF-8') > 80) {
        throw new InvalidArgumentException('Search text must be at most 80 characters.');
    }
    if ($search !== '') {
        $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $where[] = '(s.name_eng LIKE :q_eng ESCAPE \'!\' OR s.name_ban LIKE :q_ban ESCAPE \'!\' OR CAST(s.cls_roll AS CHAR) LIKE :q_roll ESCAPE \'!\' OR s.mobile LIKE :q_mobile ESCAPE \'!\')';
        $like = '%' . $escapedSearch . '%';
        $params['q_eng'] = $like;
        $params['q_ban'] = $like;
        $params['q_roll'] = $like;
        $params['q_mobile'] = $like;
    }

    $sql = 'SELECT s.id, s.name_eng, s.name_ban, s.fname_eng, s.fname_ban,
                   s.mname_eng, s.mname_ban, s.mobile, s.cls, s.sections,
                   s.cls_roll, s.session_year, s.status,
                   c.class_name, c.class_name_ban,
                   sec.section_name, sec.section_name_ban
            FROM students_info s
            LEFT JOIN cls c ON c.id = s.cls
            LEFT JOIN sections sec ON CAST(sec.id AS CHAR) = s.sections
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.cls ASC, CAST(s.sections AS UNSIGNED) ASC,
                     CAST(s.cls_roll AS UNSIGNED) ASC, s.id ASC
            LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
    $students = fetchRows($db, $sql, $params);
    $hasMore = count($students) > $limit;
    if ($hasMore) {
        array_pop($students);
    }
    foreach ($students as &$student) {
        $student['id'] = (int) $student['id'];
        $student['cls'] = (int) $student['cls'];
        $student['status'] = (int) $student['status'];
    }
    unset($student);

    return [
        'students' => $students,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $limit : null,
        ],
    ];
}

function mobileAdminStudentsUpdate(PDO $db, int $studentId, array $fields): array
{
    $editable = mobileAdminStudentsEditableFields();
    if ($fields === []) {
        throw new InvalidArgumentException('At least one editable field is required.');
    }
    foreach ($fields as $field => $value) {
        if (!is_string($field) || !in_array($field, $editable, true)) {
            throw new InvalidArgumentException('One or more fields are not editable through this API.');
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Field values must be strings or integers.');
        }
        $value = trim((string) $value);
        if (mb_strlen($value, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Field values must be at most 255 characters.');
        }
        if (in_array($field, ['division', 'district', 'upazilla'], true)) {
            if ($value !== '' && mobileAdminStudentsPositiveId($value) === null && $value !== '0') {
                throw new InvalidArgumentException('Address lookup IDs must be positive integers.');
            }
        } elseif ($field === 'gender' && !in_array($value, ['male', 'female'], true)) {
            throw new InvalidArgumentException('Gender must be male or female.');
        } elseif ($field === 'religion' && $value !== '' && !in_array($value, ['Islam', 'Hindu', 'Buddhist', 'Christian'], true)) {
            throw new InvalidArgumentException('Choose a supported religion.');
        } elseif ($field === 'birth_date' && $value !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Birth date must use YYYY-MM-DD format.');
            }
        }
        $fields[$field] = $value;
    }

    $db->beginTransaction();
    try {
        $rows = fetchRows(
            $db,
            'SELECT id, status, cls, sections, session_year, cls_roll, name_eng, name_ban, gender
             FROM students_info WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $studentId]
        );
        if ($rows === []) {
            throw new OutOfBoundsException('Student not found.');
        }
        $current = $rows[0];
        mobileAdminStudentsValidatePlacement($db, $current);
        foreach ($fields as $field => $value) {
            if (!columnExists($db, 'students_info', $field)) {
                throw new InvalidArgumentException('This field is not available in the installed student schema.');
            }
        }
        $merged = array_merge($current, $fields);
        if (trim((string) ($merged['name_eng'] ?? '')) === '' || trim((string) ($merged['name_ban'] ?? '')) === '') {
            throw new InvalidArgumentException('Both Bangla and English student names are required.');
        }

        $sets = [];
        $params = ['id' => $studentId];
        foreach ($fields as $field => $value) {
            $sets[] = '`' . $field . '` = :' . $field;
            $params[$field] = in_array($field, ['division', 'district', 'upazilla'], true)
                ? (int) ($value === '' ? 0 : $value)
                : $value;
        }
        $db->prepare('UPDATE students_info SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        $updatedStudent = mobileAdminStudentsDetail($db, $studentId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return $updatedStudent;
}

function mobileAdminStudentsPromote(PDO $db, int $studentId, array $payload, array $staff): array
{
    $mode = (string) ($payload['mode'] ?? 'class');
    if (!in_array($mode, ['class', 'ssc', 'ssc_return'], true)) {
        throw new InvalidArgumentException('Mode must be class, ssc or ssc_return.');
    }
    $toClass = mobileAdminStudentsPositiveId($payload['to_class'] ?? null);
    $toSection = mobileAdminStudentsPositiveId($payload['to_section'] ?? null);
    $toSession = trim((string) ($payload['to_session'] ?? ''));
    $toRoll = trim((string) ($payload['to_roll'] ?? ''));
    $batchYear = trim((string) ($payload['ssc_batch_year'] ?? ''));
    $remarks = trim((string) ($payload['remarks'] ?? ''));
    if (mb_strlen($remarks, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Remarks must be at most 255 characters.');
    }
    if ($remarks === '') {
        $remarks = match ($mode) {
            'ssc' => 'Moved to SSC candidate batch',
            'ssc_return' => 'Returned to Class 10 from SSC batch',
            default => 'Promoted',
        };
    }

    $db->beginTransaction();
    try {
        $rows = fetchRows(
            $db,
            'SELECT id, cls, sections, session_year, cls_roll, status, ssc_batch_year
             FROM students_info WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $studentId]
        );
        if ($rows === []) {
            throw new OutOfBoundsException('Student not found.');
        }
        $student = $rows[0];
        $status = (int) $student['status'];
        $from = [
            'student_id' => (int) $student['id'],
            'cls' => (int) $student['cls'],
            'sections' => (string) $student['sections'],
            'session_year' => trim((string) $student['session_year']),
            'cls_roll' => trim((string) $student['cls_roll']),
        ];
        $destinationClass = null;
        $fromSession = $from['session_year'];
        $newStatus = $status;
        $to = [];

        if ($mode === 'ssc_return') {
            if ($status !== studentStatusSscCandidate()) {
                throw new LogicException('Only SSC candidates can be returned to class.');
            }
            $sourceClass = mobileAdminStudentsValidateClass($db, (int) $student['cls']);
            $sourceClassId = mobileAdminStudentsPositiveId($student['cls'] ?? null);
            $sourceSection = trim((string) ($student['sections'] ?? ''));
            $sourceSession = trim((string) ($student['session_year'] ?? ''));
            $sourceRoll = trim((string) ($student['cls_roll'] ?? ''));
            if (
                $sourceClassId === null
                || !mobileAdminStudentsValidSession($sourceSession)
                || !mobileAdminStudentsValidRoll($sourceRoll)
            ) {
                throw new InvalidArgumentException('The SSC candidate has an invalid source class, session or roll.');
            }
            mobileAdminStudentsValidateSection($db, $sourceClassId, $sourceSection);
            $destinationClass = mobileAdminStudentsValidateClass($db, $toClass ?? 0);
            if (!mobileAdminStudentsIsClassTen($sourceClass) || !mobileAdminStudentsIsClassTen($destinationClass)) {
                throw new LogicException('SSC candidates may only return to Class 10.');
            }
            $fromSession = trim((string) ($student['ssc_batch_year'] ?? '')) ?: $fromSession;
            if (
                !mobileAdminStudentsValidSession($fromSession)
                || !mobileAdminStudentsValidSession($toSession)
                || (int) $toSession <= (int) $fromSession
                || $toSection === null
                || !mobileAdminStudentsValidRoll($toRoll)
            ) {
                throw new InvalidArgumentException('Return requires a later session, Class 10 section and valid roll.');
            }
            mobileAdminStudentsValidateSection($db, $toClass, (string) $toSection);
            $newStatus = studentStatusAdmitted();
        } elseif ($mode === 'ssc') {
            $activePlacement = mobileAdminStudentsValidatePlacement($db, $student);
            $sourceClass = mobileAdminStudentsValidateClass($db, $activePlacement['cls']);
            if (!mobileAdminStudentsIsClassTen($sourceClass)) {
                throw new LogicException('Only admitted Class 10 students can enter the SSC candidate batch.');
            }
            if (!mobileAdminStudentsValidSession($batchYear) || (int) $batchYear < (int) $activePlacement['session_year']) {
                throw new InvalidArgumentException('SSC batch year must be a valid year no earlier than the current session.');
            }
            if (!columnExists($db, 'students_info', 'ssc_batch_year') || !columnExists($db, 'students_info', 'ssc_passing_year')) {
                throw new LogicException('SSC candidate fields are unavailable in the installed schema.');
            }
            $from = $activePlacement;
            $fromSession = $activePlacement['session_year'];
            $newStatus = studentStatusSscCandidate();
            $destinationClass = $sourceClass;
            $to = [
                'session_year' => $batchYear,
                'cls' => $activePlacement['cls'],
                'sections' => $activePlacement['sections'],
                'cls_roll' => $activePlacement['cls_roll'],
            ];
        } else {
            $activePlacement = mobileAdminStudentsValidatePlacement($db, $student);
            $sourceClass = mobileAdminStudentsValidateClass($db, $activePlacement['cls']);
            $destinationClass = mobileAdminStudentsValidateClass($db, $toClass ?? 0);
            $orderedClasses = mobileAdminStudentsOrderedClassIds($db);
            $sourcePosition = array_search($activePlacement['cls'], $orderedClasses, true);
            if ($sourcePosition === false || !isset($orderedClasses[$sourcePosition + 1]) || $orderedClasses[$sourcePosition + 1] !== $toClass) {
                throw new LogicException('A class promotion must move to the next class in the academic sequence.');
            }
            if (
                !mobileAdminStudentsValidSession($toSession)
                || (int) $toSession <= (int) $activePlacement['session_year']
                || $toSection === null
                || !mobileAdminStudentsValidRoll($toRoll)
            ) {
                throw new InvalidArgumentException('Class promotion requires a later session, valid destination section and roll.');
            }
            mobileAdminStudentsValidateSection($db, $toClass, (string) $toSection);
            $from = $activePlacement;
            $newStatus = studentStatusAdmitted();
            $to = [
                'session_year' => $toSession,
                'cls' => $toClass,
                'sections' => (string) $toSection,
                'cls_roll' => $toRoll,
            ];
        }

        if ($mode !== 'ssc') {
            $destinationClassId = (int) $to['cls'];
            $lock = $db->prepare('SELECT id FROM cls WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $destinationClassId]);
            assertActiveStudentRollAvailable(
                $db,
                $destinationClassId,
                (string) $to['session_year'],
                (string) $to['sections'],
                (string) $to['cls_roll'],
                $studentId
            );
        }

        $batchId = date('YmdHis') . '-' . bin2hex(random_bytes(6));
        if ($mode === 'class' || $mode === 'ssc_return') {
            $db->prepare(
                'UPDATE students_info
                 SET status = :status, cls = :cls, sections = :sections,
                     session_year = :session_year, cls_roll = :roll
                 WHERE id = :id AND status = :old_status'
            )->execute([
                'status' => $newStatus,
                'cls' => (int) $to['cls'],
                'sections' => (string) $to['sections'],
                'session_year' => (string) $to['session_year'],
                'roll' => (int) $to['cls_roll'],
                'id' => $studentId,
                'old_status' => $status,
            ]);
        } else {
            $db->prepare(
                'UPDATE students_info
                 SET status = :status, ssc_batch_year = :batch, ssc_passing_year = :passing
                 WHERE id = :id AND status = :old_status'
            )->execute([
                'status' => $newStatus,
                'batch' => $batchYear,
                'passing' => $batchYear,
                'id' => $studentId,
                'old_status' => $status,
            ]);
        }

        $db->prepare(
            'INSERT INTO student_promotions
                (student_id, from_class, from_section, from_session, from_roll,
                 to_class, to_section, to_session, to_roll, remarks, promoted_by, promotion_batch)
             VALUES
                (:student_id, :from_class, :from_section, :from_session, :from_roll,
                 :to_class, :to_section, :to_session, :to_roll, :remarks, :promoted_by, :batch)'
        )->execute([
            'student_id' => $studentId,
            'from_class' => $from['cls'],
            'from_section' => $from['sections'],
            'from_session' => $fromSession,
            'from_roll' => $from['cls_roll'],
            'to_class' => (int) ($to['cls'] ?? $from['cls']),
            'to_section' => (string) ($to['sections'] ?? $from['sections']),
            'to_session' => (string) ($to['session_year'] ?? $batchYear),
            'to_roll' => (int) ($to['cls_roll'] ?? $from['cls_roll']),
            'remarks' => $remarks,
            'promoted_by' => (string) ($staff['teacher_mobile'] ?? ''),
            'batch' => $batchId,
        ]);
        recordStudentClassPromotionEnrollments($db, $from, $to, $mode);
        $promotedStudent = mobileAdminStudentsDetail($db, $studentId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return $promotedStudent;
}

if (!defined('MOBILE_ADMIN_STUDENTS_TESTING')) {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
    }

    try {
        $db = getDatabaseConnection();
        $identity = mobileApiRequireIdentity($db);
        $payload = $method === 'POST' ? mobileApiReadJson(65536) : [];
        $action = $method === 'GET'
            ? (string) ($_GET['action'] ?? 'list')
            : (string) ($payload['action'] ?? '');
        $module = mobileAdminStudentsRequiredModule($method, $action);
        if ($module === null) {
            mobileApiJson([
                'error' => [
                    'code' => 'invalid_action',
                    'message' => 'Choose list, detail, create_options, roll_check, create, admission_applications, admission_options, approve_admission, reject_admission, restore_admission, promotion_candidates, promotion_options, reset_password, update or promote for this method.',
                ],
            ], 422);
        }
        if ($identity['account_type'] !== 'staff' || !canAccessModule($module, $identity['account'])) {
            mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission for this student action.']], 403);
        }
        if ($method === 'POST') {
            mobileApiBeginIdempotentWrite($db, $identity);
        }
        ensureSchoolSchema($db);

        if ($method === 'GET' && $action === 'create_options') {
            mobileApiJson(['data' => mobileAdminStudentsCreateOptions($db)]);
        }
        if ($method === 'GET' && $action === 'roll_check') {
            mobileApiJson(['data' => mobileAdminStudentsCheckRoll($db, $_GET)]);
        }
        if ($method === 'GET' && in_array($action, ['list', 'promotion_candidates', 'admission_applications'], true)) {
            if ($action === 'admission_applications') {
                $_GET['status'] = '0';
            }
            mobileApiJson([
                'data' => mobileAdminStudentsList(
                    $db,
                    $_GET,
                    $action === 'promotion_candidates',
                    $action === 'admission_applications'
                ),
            ]);
        }
        if ($method === 'GET' && $action === 'detail') {
            $studentId = mobileAdminStudentsPositiveId($_GET['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            mobileApiJson(['data' => ['student' => mobileAdminStudentsDetail($db, $studentId)]]);
        }
        if ($method === 'GET' && $action === 'promotion_options') {
            $studentId = mobileAdminStudentsPositiveId($_GET['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            mobileApiJson(['data' => ['promotion_options' => mobileAdminStudentsPromotionOptions($db, $studentId)]]);
        }
        if ($method === 'GET' && $action === 'admission_options') {
            $studentId = mobileAdminStudentsPositiveId($_GET['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            mobileApiJson(['data' => ['admission_options' => mobileAdminStudentsAdmissionOptions($db, $studentId)]]);
        }
        if ($method === 'POST' && $action === 'update') {
            $studentId = mobileAdminStudentsPositiveId($payload['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            $fields = $payload['fields'] ?? null;
            if (!is_array($fields)) {
                mobileApiJson(['error' => ['code' => 'invalid_fields', 'message' => 'Provide editable student fields as a JSON object.']], 422);
            }
            mobileApiJson(['data' => ['student' => mobileAdminStudentsUpdate($db, $studentId, $fields)]]);
        }
        if ($method === 'POST' && $action === 'reset_password') {
            $studentId = mobileAdminStudentsPositiveId($payload['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            mobileAdminStudentsResetPassword($db, $studentId, $payload['password'] ?? null);
            mobileApiJson(['data' => ['student_id' => $studentId, 'status' => 'password_reset']]);
        }
        if ($method === 'POST' && $action === 'create') {
            mobileApiJson([
                'data' => [
                    'student' => mobileAdminStudentsCreate($db, $payload),
                    'status' => 'created',
                ],
            ], 201);
        }
        if ($method === 'POST' && $action === 'promote') {
            $studentId = mobileAdminStudentsPositiveId($payload['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            mobileApiJson([
                'data' => [
                    'student' => mobileAdminStudentsPromote($db, $studentId, $payload, $identity['account']),
                    'status' => 'promoted',
                ],
            ]);
        }
        if ($method === 'POST' && $action === 'approve_admission') {
            $studentId = mobileAdminStudentsPositiveId($payload['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            mobileApiJson([
                'data' => [
                    'student' => mobileAdminStudentsApproveAdmission($db, $studentId, $payload),
                    'status' => 'approved',
                ],
            ]);
        }
        if ($method === 'POST' && in_array($action, ['reject_admission', 'restore_admission'], true)) {
            $studentId = mobileAdminStudentsPositiveId($payload['id'] ?? null);
            if ($studentId === null) {
                mobileApiJson(['error' => ['code' => 'invalid_student', 'message' => 'A positive student ID is required.']], 422);
            }
            $rejecting = $action === 'reject_admission';
            mobileAdminStudentsSetAdmissionStatus(
                $db,
                $studentId,
                $rejecting ? 0 : 2,
                $rejecting ? 2 : 0
            );
            mobileApiJson([
                'data' => [
                    'student_id' => $studentId,
                    'status' => $rejecting ? 'rejected' : 'restored',
                ],
            ]);
        }
    } catch (InvalidArgumentException $exception) {
        mobileApiJson(['error' => ['code' => 'invalid_request', 'message' => $exception->getMessage()]], 422);
    } catch (OutOfBoundsException $exception) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => $exception->getMessage()]], 404);
    } catch (LogicException $exception) {
        mobileApiJson(['error' => ['code' => 'invalid_transition', 'message' => $exception->getMessage()]], 409);
    } catch (PDOException $exception) {
        mobileApiDatabaseFailure($exception, 'admin students');
    } catch (RuntimeException $exception) {
        mobileApiJson(['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]], 409);
    } catch (Throwable $exception) {
        error_log('Mobile API admin students failed: ' . $exception->getMessage());
        mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The student request could not be completed.']], 500);
    }
}
