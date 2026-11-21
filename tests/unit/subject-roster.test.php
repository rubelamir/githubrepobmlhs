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

$religionPapers = assignReligionSubjects([
    ['id' => 1, 'subject_name_ban' => 'ইসলাম শিক্ষা', 'subject_name' => 'Islam', 'audience_religion' => 'Hindu', 'choice_group' => ''],
    ['id' => 2, 'subject_name_ban' => 'হিন্দু ধর্ম শিক্ষা', 'subject_name' => '', 'audience_religion' => '', 'choice_group' => ''],
    ['id' => 3, 'subject_name_ban' => 'খ্রিস্টান ধর্ম শিক্ষা', 'subject_name' => '', 'audience_religion' => '', 'choice_group' => ''],
    ['id' => 4, 'subject_name_ban' => 'বৌদ্ধ ধর্ম শিক্ষা', 'subject_name' => '', 'audience_religion' => '', 'choice_group' => ''],
    ['id' => 5, 'subject_name_ban' => 'বাংলা', 'subject_name' => 'Bangla', 'audience_religion' => '', 'choice_group' => ''],
    ['id' => 6, 'subject_name_ban' => 'ইসলামের ইতিহাস', 'subject_name' => 'History', 'audience_religion' => '', 'choice_group' => ''],
    ['id' => 7, 'subject_name_ban' => 'খ্রিষ্টধর্ম ও নৈতিক শিক্ষা', 'subject_name' => '', 'audience_religion' => '', 'choice_group' => ''],
]);
$check(subjectAudienceReligion($religionPapers[0]['audience_religion']) === 'Islam', 'Islam education follows Muslim students');
$check(subjectAudienceReligion($religionPapers[1]['audience_religion']) === 'Hindu', 'Hindu religion education follows Hindu students');
$check(subjectAudienceReligion($religionPapers[2]['audience_religion']) === 'Christian', 'Christian religion education follows Christian students');
$check(subjectAudienceReligion($religionPapers[3]['audience_religion']) === 'Buddhist', 'Buddhist religion education follows Buddhist students');
$check(subjectAudienceReligion($religionPapers[4]['audience_religion']) === '', 'Bangla stays open to every student');
$check(subjectAudienceReligion($religionPapers[5]['audience_religion']) === '', 'a history paper keeps the whole class');
$check(subjectAudienceReligion($religionPapers[6]['audience_religion']) === 'Christian', 'the moral-education spelling still matches Christian students');
$check(subjectAppliesToStudent($religionPapers[0], 'Islam', null) && !subjectAppliesToStudent($religionPapers[0], 'Hindu', null), 'only a Muslim student sits the Islam paper');
$check(subjectAppliesToStudent($religionPapers[1], 'Hindu', null) && !subjectAppliesToStudent($religionPapers[1], 'Islam', null), 'only a Hindu student sits the Hindu paper');
$check(subjectAppliesToStudent($religionPapers[2], 'Christian', null) && subjectAppliesToStudent($religionPapers[6], 'Christian', null), 'a Christian student sits both Christian papers');
$check(!subjectAppliesToStudent($religionPapers[3], 'Christian', null) && subjectAppliesToStudent($religionPapers[3], 'Buddhist', null), 'only a Buddhist student sits the Buddhist paper');
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
