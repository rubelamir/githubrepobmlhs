<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/subject-roster.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
};

$islam = ['id' => 20, 'audience_religion' => 'Islam', 'choice_group' => ''];
$agriculture = ['id' => 21, 'audience_religion' => '', 'choice_group' => 'কৃষি-গার্হস্থ্য'];
$home = ['id' => 22, 'audience_religion' => '', 'choice_group' => '  কৃষি-গার্হস্থ্য  '];
$bangla = ['id' => 10, 'audience_religion' => '', 'choice_group' => ''];

$check(subjectAppliesToStudent($islam, 'Islam', null), 'an Islam paper includes a Muslim student');
$check(!subjectAppliesToStudent($islam, 'Hindu', null), 'an Islam paper leaves out a Hindu student');
$check(subjectAppliesToStudent($bangla, 'Hindu', null), 'a compulsory paper includes every student');
$check(subjectAppliesToStudent($agriculture, 'Islam', 21), 'the chosen agriculture paper includes that student');
$check(!subjectAppliesToStudent($home, 'Islam', 21), 'home science stays off the agriculture student');
$check(!subjectAppliesToStudent($agriculture, 'Islam', null), 'an elective with no choice includes nobody');
$check(subjectChoiceGroup('  কৃষি-গার্হস্থ্য  ') === 'কৃষি-গার্হস্থ্য', 'choice group names ignore extra spaces');
$check(subjectAudienceReligion('Buddhist') === 'Buddhist' && subjectAudienceReligion('other') === '', 'only the four religions are recognized');
$check(str_contains(subjectRosterNotice($islam), 'ইসলাম'), 'the roster notice names the religion');
$schema = file_get_contents(__DIR__ . '/../../shared/schema.php');
$storageCall = strpos($schema, 'ensureSubjectRosterStorage($db)');
$versionGate = strpos($schema, 'if (schemaVersionIsCurrent($db))');
$check(
    $storageCall !== false && $versionGate !== false && $storageCall < $versionGate,
    'roster columns are created before the current-schema shortcut'
);

if ($failures > 0) {
    fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
    exit(1);
}

echo "PASS\n";
