<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';

$cases = [
    ['12.00', '12'],
    ['12.50', '12.5'],
    ['12.05', '12.05'],
    ['0.00', '0'],
    ['12', '12'],
    ['', ''],
    [null, ''],
];

foreach ($cases as [$input, $expected]) {
    $actual = formatMarkValue($input);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . var_export($input, true) . ' => ' . $actual . ' (expected ' . $expected . ')' . PHP_EOL);
        exit(1);
    }
}

echo "PASS\n";
