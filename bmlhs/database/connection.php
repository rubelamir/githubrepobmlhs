<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/deployment-config.php';

function schoolMysqli(): mysqli
{
    static $mysqli = null;
    if ($mysqli instanceof mysqli) {
        return $mysqli;
    }

    $databaseConfig = deploymentDatabaseSettings();
    $host = $databaseConfig['host'];
    $user = $databaseConfig['username'];
    $password = $databaseConfig['password'];
    $database = $databaseConfig['name'];

    $mysqli = mysqli_connect($host, $user, $password, $database);
    if (!$mysqli) {
        error_log('Database connection failed: ' . mysqli_connect_error());
        http_response_code(500);
        exit('Database connection unavailable.');
    }
    mysqli_set_charset($mysqli, 'utf8mb4');
    $GLOBALS['conn'] = $mysqli;

    return $mysqli;
}

function schoolScriptUsesMysqli(): bool
{
    $name = pathinfo(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME);

    return in_array($name, [
        'admission',
        'admission_form_edit',
        'admission_forget_password',
        'admission_login',
        'admission_reset_password',
        'ajax_request',
        'ajax_request_teacher_login',
        'about',
        'download_preview',
        'notice',
        'notice_single',
        'selectmenu',
        'stipend_scholarship_students_list',
        'students',
        'teacher_staff',
        'teacher_staff_single',
        'view_admission',
        'view_admission_form',
    ], true);
}

$conn = null;
if (schoolScriptUsesMysqli()) {
    $conn = schoolMysqli();
}
