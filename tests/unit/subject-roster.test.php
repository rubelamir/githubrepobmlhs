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

$paired = pairAgricultureHomeScienceSubjects([
    ['id' => 1, 'subject_name_ban' => 'বাংলা', 'subject_name' => 'Bangla', 'choice_group' => ''],
    ['id' => 2, 'subject_name_ban' => 'কৃষি শিক্ষা', 'subject_name' => 'Agriculture', 'choice_group' => ''],
    ['id' => 3, 'subject_name_ban' => 'গার্হস্থ্য বিজ্ঞান', 'subject_name' => 'Home Science', 'choice_group' => ''],
]);
$check(subjectChoiceGroup($paired[0]['choice_group']) === '', 'Bangla stays open to the whole class');
$check(subjectChoiceGroup($paired[1]['choice_group']) === 'কৃষি-গার্হস্থ্য', 'agriculture joins the pair on its own');
$check(subjectChoiceGroup($paired[2]['choice_group']) === 'কৃষি-গার্হস্থ্য', 'home science joins the same pair');
$check(subjectAppliesToStudent($paired[1], 'Islam', 2), 'the student who picked agriculture stays on that paper');
$check(!subjectAppliesToStudent($paired[2], 'Islam', 2), 'that student stays off home science');

$custom = pairAgricultureHomeScienceSubjects([
    ['subject_name_ban' => 'কৃষি', 'subject_name' => '', 'choice_group' => 'বিকল্প'],
    ['subject_name_ban' => 'গার্হস্থ্য', 'subject_name' => '', 'choice_group' => ''],
]);
$check(subjectChoiceGroup($custom[0]['choice_group']) === 'বিকল্প' && subjectChoiceGroup($custom[1]['choice_group']) === 'বিকল্প', 'an existing group name is reused');

$apart = pairAgricultureHomeScienceSubjects([
    ['subject_name_ban' => 'কৃষি', 'subject_name' => '', 'choice_group' => 'ক'],
    ['subject_name_ban' => 'গার্হস্থ্য', 'subject_name' => '', 'choice_group' => 'খ'],
]);
$check(subjectChoiceGroup($apart[0]['choice_group']) === 'ক' && subjectChoiceGroup($apart[1]['choice_group']) === 'খ', 'two different groups stay apart');

$alone = pairAgricultureHomeScienceSubjects([
    ['subject_name_ban' => 'কৃষি শিক্ষা', 'subject_name' => '', 'choice_group' => ''],
]);
$check(subjectChoiceGroup($alone[0]['choice_group']) === '', 'agriculture on its own stays open to the class');
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
