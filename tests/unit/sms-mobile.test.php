<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/sms.php';

$cases = [
    ['01712345678', '8801712345678'],
    ['8801712345678', '8801712345678'],
    ['1712345678', '8801712345678'],
    ['০১৭১২৩৪৫৬৭৮', '8801712345678'],
    ['01712', ''],
    ['', ''],
];

foreach ($cases as [$input, $expected]) {
    $actual = normalizeBdMobile($input);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $input . ' => ' . $actual . ' (expected ' . $expected . ')' . PHP_EOL);
        exit(1);
    }
}

echo "PASS\n";
