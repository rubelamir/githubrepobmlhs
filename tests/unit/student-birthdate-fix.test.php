<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/shared/student-import.php';
require_once $root . '/shared/student-birthdate-fix.php';

$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        $failed++;
    }
};

$check(studentImportNormalizeBirthDate('41054') === '2012-05-25', 'Excel serial dates convert to Y-m-d');
$check(studentImportNormalizeBirthDate('2011-09-11') === '2011-09-11', 'ISO dates stay ISO');
$check(str_contains(studentBirthdateFixTemplateCsv(), 'registration_no'), 'template includes registration_no');
$check(str_contains(studentBirthdateFixTemplateCsv(), 'birth_date'), 'template includes birth_date');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE students_info (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reg_no TEXT NOT NULL DEFAULT \'\',
        birth_date TEXT NOT NULL DEFAULT \'\',
        name_ban TEXT NOT NULL DEFAULT \'\',
        name_eng TEXT NOT NULL DEFAULT \'\',
        cls_roll INTEGER
    )'
);
$db->exec("INSERT INTO students_info (id, reg_no, birth_date, name_ban, cls_roll) VALUES (7, '2511861724', '2010-01-01', 'করিম', 1)");

$updated = studentBirthdateFixApply($db, [
    [
        'student_id' => 7,
        'new_birth_date' => '2012-05-25',
        'errors' => [],
        'unchanged' => false,
    ],
    [
        'student_id' => 8,
        'new_birth_date' => '2011-09-11',
        'errors' => ['ডাটাবেজে এই রেজিস্ট্রেশন নম্বর নেই।'],
        'unchanged' => false,
    ],
]);
$check($updated === 1, 'only ready rows are updated');
$stored = $db->query('SELECT birth_date FROM students_info WHERE id = 7')->fetchColumn();
$check($stored === '2012-05-25', 'matched registration gets the corrected birth date');

$page = (string) file_get_contents($root . '/dist/pages/student-birthdate-fix.php');
$check(str_contains($page, 'studentBirthdateFixBuildPreview'), 'admin page previews spreadsheet rows');
$check(str_contains($page, 'studentBirthdateFixApply'), 'admin page can save corrections');

if ($failed > 0) {
    fwrite(STDERR, $failed . " check(s) failed\n");
    exit(1);
}
echo "ok\n";
