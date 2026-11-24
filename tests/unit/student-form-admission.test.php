<?php
declare(strict_types=1);

/**
 * New admissions must send reg_no (or have a DEFAULT) because students_info.reg_no is NOT NULL.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/shared/school.php';
require_once $root . '/shared/schema.php';

$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        $failed++;
    }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE students_info (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admission_token TEXT NOT NULL,
        cls_roll INTEGER,
        cls INTEGER,
        sections TEXT NOT NULL,
        session_year TEXT NOT NULL,
        name_eng TEXT NOT NULL,
        name_ban TEXT NOT NULL,
        fname_eng TEXT NOT NULL DEFAULT \'\',
        fname_ban TEXT NOT NULL DEFAULT \'\',
        father_nid_no TEXT NOT NULL DEFAULT \'\',
        mname_eng TEXT NOT NULL DEFAULT \'\',
        mname_ban TEXT NOT NULL DEFAULT \'\',
        mother_nid_no TEXT NOT NULL DEFAULT \'\',
        gender TEXT NOT NULL,
        religion TEXT NOT NULL DEFAULT \'\',
        birth_date TEXT NOT NULL DEFAULT \'\',
        birth_reg_no TEXT NOT NULL DEFAULT \'\',
        division INTEGER NOT NULL DEFAULT 0,
        district INTEGER NOT NULL DEFAULT 0,
        upazilla INTEGER NOT NULL DEFAULT 0,
        post_office TEXT NOT NULL DEFAULT \'\',
        village TEXT NOT NULL DEFAULT \'\',
        house_name TEXT NOT NULL DEFAULT \'\',
        reg_no TEXT NOT NULL,
        exam_roll TEXT NOT NULL DEFAULT \'\',
        passing_year TEXT NOT NULL DEFAULT \'\',
        scholarship_stipend_info TEXT NOT NULL DEFAULT \'not_applicable\',
        last_school_name TEXT NOT NULL DEFAULT \'\',
        mobile TEXT NOT NULL DEFAULT \'\',
        pass TEXT NOT NULL DEFAULT \'\',
        student_pic TEXT NOT NULL DEFAULT \'\',
        birth_reg_pic TEXT NOT NULL DEFAULT \'\',
        status INTEGER NOT NULL DEFAULT 1,
        last_class TEXT NOT NULL DEFAULT \'\',
        village_en TEXT NOT NULL DEFAULT \'\',
        post_office_en TEXT NOT NULL DEFAULT \'\'
    )'
);

$payloadWithoutReg = [
    'admission_token' => 'tok-1',
    'cls_roll' => 1,
    'cls' => 9,
    'sections' => '1',
    'session_year' => '2026',
    'name_eng' => 'Test Student',
    'name_ban' => 'টেস্ট',
    'gender' => 'male',
    'religion' => 'Islam',
    'scholarship_stipend_info' => 'not_applicable',
    'status' => 1,
    'pass' => 'hash',
    'student_pic' => 'x.jpeg',
    'birth_reg_pic' => '',
    'exam_roll' => '',
];
$omitFailed = false;
try {
    $columns = array_keys($payloadWithoutReg);
    $db->prepare(
        'INSERT INTO students_info (`' . implode('`, `', $columns) . '`) VALUES (' .
        implode(', ', array_map(static fn ($c) => ':' . $c, $columns)) . ')'
    )->execute($payloadWithoutReg);
} catch (Throwable $exception) {
    $omitFailed = true;
}
$check($omitFailed, 'insert without reg_no fails when the column is NOT NULL without a default');

$payload = $payloadWithoutReg;
$payload['reg_no'] = '';
$payload['admission_token'] = 'tok-2';
$columns = array_keys($payload);
$db->prepare(
    'INSERT INTO students_info (`' . implode('`, `', $columns) . '`) VALUES (' .
    implode(', ', array_map(static fn ($c) => ':' . $c, $columns)) . ')'
)->execute($payload);
$row = $db->query("SELECT reg_no, name_ban FROM students_info WHERE admission_token = 'tok-2'")->fetch(PDO::FETCH_ASSOC);
$check(is_array($row) && ($row['reg_no'] ?? null) === '' && ($row['name_ban'] ?? '') === 'টেস্ট', 'insert with empty reg_no succeeds');

$formSource = (string) file_get_contents($root . '/dist/pages/student-form.php');
$check(
    !str_contains($formSource, 'if (!$hasRegNo || $id === 0)'),
    'the form no longer drops reg_no on every new admission'
);
$check(
    str_contains($formSource, 'if (!$hasRegNo)') && str_contains($formSource, "\$payload['reg_no'] = trim"),
    'new admissions keep an empty reg_no when the column exists'
);
$check(
    str_contains($formSource, 'if ($hasRegNo):'),
    'registration number is offered on create as well as edit'
);

$onlineAdmission = (string) file_get_contents($root . '/bmlhs/admission.php');
$check(
    str_contains($onlineAdmission, "'reg_no' => ''")
    && str_contains($onlineAdmission, 'preparedInsert($conn, \'students_info\''),
    'the public online admission insert sends an empty reg_no'
);
$check(
    !str_contains($onlineAdmission, "\$_SERVER['PHP_SELF']")
    && preg_match('/id="admissionForm"[^>]*\saction="\/admission\.php"/', $onlineAdmission) === 1,
    'online admission posts to /admission.php so /bmlhs 301 redirects cannot drop the POST body'
);
$security = (string) file_get_contents($root . '/bmlhs/includes/security.php');
$check(
    preg_match("/'students_info'\\s*=>\\s*\\[[^\\]]*\\breg_no\\b/s", $security) === 1,
    'preparedInsert allows reg_no for students_info'
);

if ($failed > 0) {
    fwrite(STDERR, $failed . " check(s) failed\n");
    exit(1);
}
echo "ok\n";
