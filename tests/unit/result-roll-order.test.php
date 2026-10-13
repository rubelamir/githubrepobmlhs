<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/academics.php';

$summaries = [
    ['student' => ['id' => 3, 'cls_roll' => '১০']],
    ['student' => ['id' => 2, 'cls_roll' => '2']],
    ['student' => ['id' => 1, 'cls_roll' => '১']],
    ['student' => ['id' => 4, 'cls_roll' => '02']],
];
$sorted = sortResultSummariesByRoll($summaries);
$actual = array_map(
    static fn (array $row): int => (int) asciiDigits((string) $row['student']['cls_roll']),
    $sorted
);
if ($actual !== [1, 2, 2, 10]) {
    fwrite(STDERR, 'FAIL: result rows should be ordered by numeric roll: ' . implode(', ', $actual) . PHP_EOL);
    exit(1);
}
if ((int) $sorted[1]['student']['id'] !== 2 || (int) $sorted[2]['student']['id'] !== 4) {
    fwrite(STDERR, "FAIL: matching roll numbers should use student id as stable tie-breaker\n");
    exit(1);
}

echo "PASS\n";
