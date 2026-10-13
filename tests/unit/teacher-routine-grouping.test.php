<?php
declare(strict_types=1);

function tableExists(PDO $db, string $table): bool
{
    return in_array($table, ['class_routine', 'cls', 'sections'], true);
}

function fetchRows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../../shared/academics.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE cls (id INTEGER PRIMARY KEY, class_name_ban TEXT, class_name TEXT)');
$db->exec('CREATE TABLE sections (id INTEGER PRIMARY KEY, section_name_ban TEXT, section_name TEXT)');
$db->exec(
    'CREATE TABLE class_routine (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER NOT NULL,
        section_id INTEGER NOT NULL,
        day_of_week INTEGER NOT NULL,
        period_no INTEGER NOT NULL,
        subject_name TEXT NOT NULL,
        teacher_mobile TEXT,
        room TEXT
    )'
);

$db->exec("INSERT INTO cls (id, class_name_ban, class_name) VALUES (1, '৭ম পদ্মা', '7th Padma')");
$db->exec("INSERT INTO cls (id, class_name_ban, class_name) VALUES (2, '৭ম যমুনা', '7th Jamuna')");
$db->exec("INSERT INTO sections (id, section_name_ban, section_name) VALUES (1, 'ক', 'A')");
$db->exec("INSERT INTO sections (id, section_name_ban, section_name) VALUES (2, 'গ', 'C')");

$db->exec(
    "INSERT INTO class_routine (class_id, section_id, day_of_week, period_no, subject_name, teacher_mobile, room)
     VALUES (1, 1, 0, 6, 'ইসলাম শিক্ষা', '01700000001', '')"
);
$db->exec(
    "INSERT INTO class_routine (class_id, section_id, day_of_week, period_no, subject_name, teacher_mobile, room)
     VALUES (2, 2, 0, 6, 'ইসলাম শিক্ষা', '01700000001', '')"
);
$db->exec(
    "INSERT INTO class_routine (class_id, section_id, day_of_week, period_no, subject_name, teacher_mobile, room)
     VALUES (1, 1, 0, 7, 'বাংলা', '01700000002', '')"
);

$cells = fetchRows($db, 'SELECT * FROM class_routine WHERE day_of_week = 0 AND period_no = 6');
$grouped = groupTeacherRoutineCells($db, $cells);

if (count($grouped) !== 1) {
    fwrite(STDERR, "FAIL: combined-class subject should collapse into a single entry.\n");
    exit(1);
}
if ($grouped[0]['subject_name'] !== 'ইসলাম শিক্ষা') {
    fwrite(STDERR, "FAIL: grouped entry should retain the subject name.\n");
    exit(1);
}
if ($grouped[0]['class_labels'] !== ['৭ম পদ্মা ক', '৭ম যমুনা গ']) {
    fwrite(STDERR, "FAIL: grouped entry should list both class/section labels: " . json_encode($grouped[0]['class_labels'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$otherPeriodCells = fetchRows($db, 'SELECT * FROM class_routine WHERE day_of_week = 0 AND period_no = 7');
$otherGrouped = groupTeacherRoutineCells($db, $otherPeriodCells);
if (count($otherGrouped) !== 1 || $otherGrouped[0]['class_labels'] !== ['৭ম পদ্মা ক']) {
    fwrite(STDERR, "FAIL: single-class period should not be affected by grouping.\n");
    exit(1);
}

echo "PASS\n";
