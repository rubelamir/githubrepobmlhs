<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/school.php';
require_once __DIR__ . '/../../shared/Accounting/bootstrap.php';

use School\Accounting\Billing\StudentDirectory;

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$student = ['name_ban' => 'সুবেহতারা', 'name_eng' => 'Subehtara', 'cls_roll' => '১২'];
$check(StudentDirectory::matchesQuery($student, 'সুবে'), 'Bengali name contains');
$check(StudentDirectory::matchesQuery($student, 'subeh'), 'English name contains');
$check(StudentDirectory::matchesQuery($student, '১২'), 'Bengali roll matches exactly');
$check(StudentDirectory::matchesQuery($student, '12'), 'ASCII roll matches a Bengali roll');
$check(!StudentDirectory::matchesQuery($student, '1'), 'a shorter roll does not match');
$check(!StudentDirectory::matchesQuery($student, 'স'), 'one letter is not enough');
$check(!StudentDirectory::matchesQuery($student, ''), 'empty query matches nobody');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
