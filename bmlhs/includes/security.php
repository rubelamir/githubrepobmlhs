<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/security.php';

startAppSession();

function establishTeacherSession(int $teacherId, string $teacherMobile): void
{
    session_regenerate_id(true);
    $_SESSION['id'] = (string) $teacherId;
    $_SESSION['teacher_mobile'] = $teacherMobile;
}

function establishAdmissionSession(int $studentId): void
{
    session_regenerate_id(true);
    $_SESSION['admission_login_id'] = (string) $studentId;
}

function clearTeacherSession(): void
{
    unset($_SESSION['id'], $_SESSION['teacher_mobile']);
}

function isTeacherSessionActive(): bool
{
    $hasId = !empty($_SESSION['id']) && ctype_digit((string) $_SESSION['id']);
    $hasMobile = !empty($_SESSION['teacher_mobile']) && is_string($_SESSION['teacher_mobile']);

    return $hasId || $hasMobile;
}

function requireTeacherLogin(): void
{
    if (!isTeacherSessionActive()) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

function requireAdmissionLogin(): void
{
    if (empty($_SESSION['admission_login_id']) || !ctype_digit((string) $_SESSION['admission_login_id'])) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

function migratePasswordIfLegacy(mysqli $conn, string $table, int $id, string $password, string $storedPassword): void
{
    if (!isLegacyPlaintextPassword($storedPassword)) {
        return;
    }

    $hash = hashPassword($password);
    $statement = $conn->prepare("UPDATE {$table} SET " . ($table === 'teacher_info' ? 'teacher_password' : 'pass') . " = ? WHERE id = ?");
    $statement->bind_param('si', $hash, $id);
    $statement->execute();
    $statement->close();
}

function preparedUpdateById(mysqli $conn, string $table, array $data, int $id): bool
{
    $allowedTables = [
        'students_info' => ['cls', 'sections', 'session_year', 'name_eng', 'name_ban', 'fname_eng', 'fname_ban', 'father_nid_no', 'mname_eng', 'mname_ban', 'mother_nid_no', 'gender', 'religion', 'birth_date', 'birth_reg_no', 'division', 'district', 'upazilla', 'post_office', 'village', 'house_name', 'last_class', 'exam_roll', 'passing_year', 'scholarship_stipend_info', 'last_school_name', 'mobile', 'student_pic', 'birth_reg_pic'],
        'teacher_info' => ['teacher_name', 'teacher_father_name', 'teacher_mother_name', 'teacher_index_number', 'teacher_mobile', 'teacher_email', 'teacher_subject', 'teacher_designation', 'first_working_school', 'teacher_birth_date', 'first_joining_date', 'teacher_joining_date', 'first_mpo_date', 'teacher_mpo_date', 'teacher_nid_no', 'teacher_tin_no', 'teacher_account_no', 'teacher_salary_code', 'teacher_division', 'teacher_district', 'teacher_upazilla', 'teacher_post_office', 'teacher_village', 'educational_details', 'training_details', 'teacher_password', 'teacher_pic', 'teacher_nid_pic', 'teacher_status'],
    ];
    if (!isset($allowedTables[$table])) {
        throw new InvalidArgumentException('Unsupported update table');
    }

    $columns = array_keys($data);
    if (array_diff($columns, $allowedTables[$table]) !== []) {
        throw new InvalidArgumentException('Unsupported update column');
    }
    $set = implode(', ', array_map(static fn (string $column): string => "`{$column}` = ?", $columns));
    $types = str_repeat('s', count($columns)) . 'i';
    $values = array_values($data);
    $values[] = $id;
    $statement = $conn->prepare("UPDATE `{$table}` SET {$set} WHERE id = ?");
    $references = [];
    foreach ($values as $index => $value) {
        $references[$index] = &$values[$index];
    }
    $statement->bind_param($types, ...$references);
    $result = $statement->execute();
    $statement->close();
    return $result;
}

function preparedInsert(mysqli $conn, string $table, array $data): bool
{
    $allowedTables = [
        'students_info' => ['admission_token', 'cls_roll', 'cls', 'sections', 'session_year', 'name_eng', 'name_ban', 'fname_eng', 'fname_ban', 'father_nid_no', 'mname_eng', 'mname_ban', 'mother_nid_no', 'gender', 'religion', 'birth_date', 'birth_reg_no', 'birth_reg_pic', 'division', 'district', 'upazilla', 'village', 'post_office', 'house_name', 'last_class', 'exam_roll', 'passing_year', 'scholarship_stipend_info', 'last_school_name', 'mobile', 'student_pic', 'pass', 'status'],
        'teacher_info' => ['teacher_name', 'teacher_father_name', 'teacher_mother_name', 'teacher_index_number', 'teacher_mobile', 'teacher_email', 'teacher_subject', 'teacher_designation', 'first_working_school', 'teacher_birth_date', 'first_joining_date', 'teacher_joining_date', 'first_mpo_date', 'teacher_mpo_date', 'teacher_nid_no', 'teacher_tin_no', 'teacher_account_no', 'teacher_salary_code', 'teacher_division', 'teacher_district', 'teacher_upazilla', 'teacher_post_office', 'teacher_village', 'educational_details', 'training_details', 'teacher_password', 'teacher_pic', 'teacher_nid_pic', 'teacher_status'],
    ];
    if (!isset($allowedTables[$table]) || array_diff(array_keys($data), $allowedTables[$table]) !== []) {
        throw new InvalidArgumentException('Unsupported insert table or column');
    }
    $columns = array_keys($data);
    $set = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $values = array_values($data);
    $references = [];
    foreach ($values as $index => $value) {
        $references[$index] = &$values[$index];
    }
    $statement = $conn->prepare("INSERT INTO `{$table}` ({$set}) VALUES ({$placeholders})");
    $statement->bind_param(str_repeat('s', count($values)), ...$references);
    $result = $statement->execute();
    $statement->close();
    return $result;
}
