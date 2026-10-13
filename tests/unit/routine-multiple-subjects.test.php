<?php
declare(strict_types=1);

function tableExists(PDO $db, string $table): bool
{
    return $table === 'class_routine';
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
$db->exec(
    'CREATE TABLE class_routine (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_id INTEGER NOT NULL,
        section_id INTEGER NOT NULL,
        day_of_week INTEGER NOT NULL,
        period_no INTEGER NOT NULL,
        period_time TEXT,
        subject_name TEXT NOT NULL,
        teacher_mobile TEXT,
        room TEXT
    )'
);

$periods = [1 => '10:00–10:45'];
$conflicts = saveClassRoutineDay(
    $db,
    1,
    1,
    1,
    $periods,
    [1 => [0 => 'ইসলাম শিক্ষা', 1 => 'হিন্দু শিক্ষা']],
    [1 => [0 => '01700000001', 1 => '01700000002']],
    [1 => [0 => '', 1 => '']]
);
$grid = classRoutineGrid($db, 1, 1);
$stats = classRoutineStats($db, 1, 1, [1 => 'সোমবার'], $periods);
if ($conflicts !== [] || count($grid[1][1] ?? []) !== 2) {
    fwrite(STDERR, "FAIL: two subjects and teachers should share one class period.\n");
    exit(1);
}
if ($stats['filled'] !== 1 || $stats['empty'] !== 0 || $stats['teachers'] !== 2) {
    fwrite(STDERR, "FAIL: period statistics should count one occupied period and both teachers.\n");
    exit(1);
}

saveClassRoutineDay($db, 1, 1, 1, $periods, [1 => [0 => 'ইসলাম শিক্ষা']], [1 => [0 => '01700000001']], [1 => [0 => '']]);
$updated = classRoutineGrid($db, 1, 1);
if (count($updated[1][1] ?? []) !== 1 || $updated[1][1][0]['subject_name'] !== 'ইসলাম শিক্ষা') {
    fwrite(STDERR, "FAIL: saving an edited day should remove obsolete parallel entries.\n");
    exit(1);
}

echo "PASS\n";
