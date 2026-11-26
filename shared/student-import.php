<?php
declare(strict_types=1);

/**
 * Class-list import for any school class: a spreadsheet plus photos named by
 * registration number. Photos are center-cropped to the same 108×137 JPEG the
 * admission form stores.
 */

function studentImportMatchToken(string $value): string
{
    $value = str_replace(["\u{FEFF}", "\u{200B}", "\u{200C}", "\u{200D}"], '', $value);
    $value = strtr($value, [
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
        '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        "\u{09DF}" => "\u{09AF}",
        "\u{09DC}" => "\u{09A1}",
        "\u{09DD}" => "\u{09A2}",
        "\u{09CE}" => "\u{09A4}",
    ]);
    $value = mb_strtolower(trim($value));
    $value = str_replace(
        ["'", '"', '`', '’', '‘', ':', 'ঃ', '.', ',', '/', '\\', '-', '_', '(', ')', '[', ']', "\t", "\n", "\r"],
        '',
        $value
    );
    $value = preg_replace('/\s+/u', '', $value) ?? $value;

    return $value;
}

function studentImportCanonicalHeader(string $header): string
{
    $map = [
        'registrationno' => 'reg_no',
        'registrationnumber' => 'reg_no',
        'regno' => 'reg_no',
        'regn' => 'reg_no',
        'boardregno' => 'reg_no',
        'boardregistrationno' => 'reg_no',
        'রেজিস্ট্রেশন' => 'reg_no',
        'রেজিস্ট্রেশননং' => 'reg_no',
        'রেজিস্ট্রেশননম্বর' => 'reg_no',
        'রেজিনং' => 'reg_no',
        'রেজিনম্বর' => 'reg_no',
        'নিবন্ধননং' => 'reg_no',
        'nameban' => 'name_ban',
        'namebangla' => 'name_ban',
        'namebn' => 'name_ban',
        'banglaname' => 'name_ban',
        'নাম' => 'name_ban',
        'নামবাংলা' => 'name_ban',
        'বাংলানাম' => 'name_ban',
        'name' => 'name_ban',
        'studentname' => 'name_ban',
        'studentsname' => 'name_ban',
        'nameofstudent' => 'name_ban',
        'nameofthestudent' => 'name_ban',
        'শিক্ষার্থীরনাম' => 'name_ban',
        'শিক্ষার্থীনাম' => 'name_ban',
        'nameeng' => 'name_eng',
        'nameen' => 'name_eng',
        'nameenglish' => 'name_eng',
        'englishname' => 'name_eng',
        'নামইংরেজি' => 'name_eng',
        'ইংরেজিনাম' => 'name_eng',
        'father' => 'fname_ban',
        'fathername' => 'fname_ban',
        'fathersname' => 'fname_ban',
        'fatherban' => 'fname_ban',
        'fatherbangla' => 'fname_ban',
        'fnameban' => 'fname_ban',
        'পিতা' => 'fname_ban',
        'পিতারনাম' => 'fname_ban',
        'বাবারনাম' => 'fname_ban',
        'fatherenglish' => 'fname_eng',
        'fathernameenglish' => 'fname_eng',
        'fathereng' => 'fname_eng',
        'fatheren' => 'fname_eng',
        'fnameeng' => 'fname_eng',
        'পিতারনামইংরেজি' => 'fname_eng',
        'mother' => 'mname_ban',
        'mothername' => 'mname_ban',
        'mothersname' => 'mname_ban',
        'motherban' => 'mname_ban',
        'mnameban' => 'mname_ban',
        'মাতা' => 'mname_ban',
        'মা' => 'mname_ban',
        'মাতারনাম' => 'mname_ban',
        'motherenglish' => 'mname_eng',
        'mothernameenglish' => 'mname_eng',
        'mothereng' => 'mname_eng',
        'motheren' => 'mname_eng',
        'mnameeng' => 'mname_eng',
        'মাতারনামইংরেজি' => 'mname_eng',
        'fatherprofession' => 'fprofession',
        'fatheroccupation' => 'fprofession',
        'fatherprof' => 'fprofession',
        'fprofession' => 'fprofession',
        'পিতারপেশা' => 'fprofession',
        'motherprofession' => 'mprofession',
        'motheroccupation' => 'mprofession',
        'motherprof' => 'mprofession',
        'mprofession' => 'mprofession',
        'মাতারপেশা' => 'mprofession',
        'fathernid' => 'father_nid_no',
        'fathernidno' => 'father_nid_no',
        'fathersnid' => 'father_nid_no',
        'পিতারএনআইডি' => 'father_nid_no',
        'mothernid' => 'mother_nid_no',
        'mothernidno' => 'mother_nid_no',
        'mothersnid' => 'mother_nid_no',
        'মাতারএনআইডি' => 'mother_nid_no',
        'gender' => 'gender',
        'sex' => 'gender',
        'লিঙ্গ' => 'gender',
        'religion' => 'religion',
        'ধর্ম' => 'religion',
        'roll' => 'roll',
        'rollno' => 'roll',
        'classroll' => 'roll',
        'clsroll' => 'roll',
        'রোল' => 'roll',
        'রোলনং' => 'roll',
        'ক্লাসরোল' => 'roll',
        'শ্রেণিরোল' => 'roll',
        'শ্রেণীররোল' => 'roll',
        'section' => 'section',
        'sec' => 'section',
        'sectionname' => 'section',
        'শাখা' => 'section',
        'গ্রুপ' => 'section',
        'বিভাগ' => 'section',
        'group' => 'section',
        'stream' => 'section',
        'sectiongroup' => 'section',
        'mobile' => 'mobile',
        'mobileno' => 'mobile',
        'phone' => 'mobile',
        'phoneno' => 'mobile',
        'মোবাইল' => 'mobile',
        'মোবাইলনং' => 'mobile',
        'মোবাইলনম্বর' => 'mobile',
        'birthdate' => 'birth_date',
        'dateofbirth' => 'birth_date',
        'dob' => 'birth_date',
        'জন্মতারিখ' => 'birth_date',
        'জন্ম' => 'birth_date',
        'birthregno' => 'birth_reg_no',
        'birthregistrationno' => 'birth_reg_no',
        'birthregistration' => 'birth_reg_no',
        'জন্মনিবন্ধন' => 'birth_reg_no',
        'জন্মনিবন্ধননং' => 'birth_reg_no',
        'জন্মসনদ' => 'birth_reg_no',
        'জন্মসনদনং' => 'birth_reg_no',
        'postoffice' => 'post_office',
        'postofficebn' => 'post_office',
        'postofficeban' => 'post_office',
        'ডাকঘর' => 'post_office',
        'postofficeen' => 'post_office_en',
        'postofficeeng' => 'post_office_en',
        'postofficeenglish' => 'post_office_en',
        'village' => 'village',
        'villagebn' => 'village',
        'villageban' => 'village',
        'গ্রাম' => 'village',
        'villageen' => 'village_en',
        'villageeng' => 'village_en',
        'villageenglish' => 'village_en',
        'housename' => 'house_name',
        'house' => 'house_name',
        'বাড়িরনাম' => 'house_name',
        'বাড়িরনাম' => 'house_name',
        'lastclass' => 'last_class',
        'previousclass' => 'last_class',
        'পূর্বেরশ্রেণি' => 'last_class',
        'শেষশ্রেণি' => 'last_class',
        'passingyear' => 'passing_year',
        'passyear' => 'passing_year',
        'পাশেরবছর' => 'passing_year',
        'lastschool' => 'last_school_name',
        'lastschoolname' => 'last_school_name',
        'previousschool' => 'last_school_name',
        'পূর্বেরবিদ্যালয়' => 'last_school_name',
        'পূর্বেরবিদ্যালয়' => 'last_school_name',
        'division' => 'division',
        'div' => 'division',
        'addressdivision' => 'division',
        'district' => 'district',
        'জেলা' => 'district',
        'upazila' => 'upazila',
        'upazilla' => 'upazila',
        'উপজেলা' => 'upazila',
        'থানা' => 'upazila',
    ];

    return $map[studentImportMatchToken($header)] ?? '';
}

function studentImportAsciiNumber(string $value): string
{
    $value = str_replace(["\u{00A0}", ' ', "\t"], '', studentImportMatchToken($value));
    if (preg_match('/^(\d+)\.0+$/', $value, $matches) === 1) {
        return $matches[1];
    }

    return $value;
}

function studentImportNormalizeRegNo(string $value): string
{
    $value = studentImportAsciiNumber($value);

    return preg_match('/^\d{4,20}$/', $value) === 1 ? $value : '';
}

function studentImportNormalizeRoll(string $value): string
{
    $value = studentImportAsciiNumber($value);
    if (preg_match('/^\d{1,4}$/', $value) !== 1) {
        return '';
    }
    $number = (int) $value;

    return $number > 0 ? (string) $number : '';
}

function studentImportNormalizeGender(string $value): string
{
    $folded = mb_strtolower(trim($value));
    if (preg_match('/female|girl|ছাত্রী|মেয়ে|মেয়ে|মহিলা|নারী|বালিকা/u', $folded) === 1) {
        return 'female';
    }
    if (preg_match('/male|boy|ছাত্র|ছেলে|পুরুষ|বালক/u', $folded) === 1) {
        return 'male';
    }
    $token = studentImportMatchToken($value);

    return match ($token) {
        'f' => 'female',
        'm' => 'male',
        default => '',
    };
}

function studentImportNormalizeReligion(string $value): string
{
    $folded = mb_strtolower(trim($value));
    if ($folded === '') {
        return '';
    }
    if (preg_match('/buddhist|buddhism|বৌদ্ধ|বুদ্ধ/u', $folded) === 1) {
        return 'Buddhist';
    }
    if (preg_match('/christian|খ্রিস্ট|খ্রিষ্ট|খ্রীষ্ট/u', $folded) === 1) {
        return 'Christian';
    }
    if (preg_match('/hindu|হিন্দু|সনাতন/u', $folded) === 1) {
        return 'Hindu';
    }
    if (preg_match('/islam|muslim|ইসলাম|মুসলিম|মুসলমান/u', $folded) === 1) {
        return 'Islam';
    }

    return '';
}

/** Empty string when the cell is blank, null when the text is not a date. */
function studentImportNormalizeBirthDate(string $value): ?string
{
    $value = trim(strtr($value, [
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
        '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
    ]));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d+)(?:\.0+)?$/', $value, $matches) === 1) {
        $serial = (int) $matches[1];
        if ($serial < 20000 || $serial > 80000) {
            return null;
        }

        return gmdate('Y-m-d', ($serial - 25569) * 86400);
    }
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!d/m/y', '!d-m-y'] as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        if ($parsed instanceof DateTimeImmutable && $clean) {
            return $parsed->format('Y-m-d');
        }
    }

    return null;
}

function studentImportClip(string $value, int $max = 250): string
{
    $value = trim($value);
    if (mb_strlen($value) <= $max) {
        return $value;
    }

    return mb_substr($value, 0, $max);
}

function studentImportIsClassNine(array $class): bool
{
    $english = strtolower(trim((string) ($class['class_name'] ?? '')));
    $bangla = trim((string) ($class['class_name_ban'] ?? ''));
    if (preg_match('/(?:^|[^a-z])(?:nine|ix)(?:[^a-z]|$)/', $english) === 1) {
        return true;
    }
    if (preg_match('/(?:^|[^0-9])9(?:[^0-9]|$)/', $english) === 1) {
        return true;
    }

    return str_contains($bangla, 'নবম') || str_contains($bangla, '৯ম') || $bangla === '৯';
}

function studentImportClassNineId(array $classes): int
{
    foreach ($classes as $class) {
        if (is_array($class) && studentImportIsClassNine($class)) {
            return (int) ($class['id'] ?? 0);
        }
    }

    return 0;
}

/**
 * @param list<list<string>> $matrix
 * @return array{line: int, columns: array<string, int>}
 */
function studentImportHeader(array $matrix): array
{
    $limit = min(count($matrix), 15);
    for ($index = 0; $index < $limit; $index++) {
        $columns = [];
        foreach ($matrix[$index] as $position => $cell) {
            $field = studentImportCanonicalHeader((string) $cell);
            if ($field === '') {
                continue;
            }
            if (isset($columns[$field])) {
                throw new RuntimeException('এক্সেলের শিরোনামে একই কলাম দুইবার আছে।');
            }
            $columns[$field] = (int) $position;
        }
        if (isset($columns['reg_no'])) {
            return ['line' => $index + 1, 'columns' => $columns];
        }
    }

    throw new RuntimeException('Registration no. কলাম পাওয়া যায়নি। শিরোনামে Registration no. বা রেজিস্ট্রেশন নং রাখুন।');
}

/**
 * @param list<string> $labels
 * @return array{by_reg: array<string, list<array{stored: string, label: string}>>, unrecognized: list<string>}
 */
function studentImportIndexPhotos(array $photos): array
{
    $byReg = [];
    $unrecognized = [];
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $stored = basename(str_replace('\\', '/', (string) ($photo['stored'] ?? '')));
        $label = basename(str_replace('\\', '/', (string) ($photo['label'] ?? $stored)));
        if ($stored === '' || $label === '') {
            continue;
        }
        $regNo = studentImportNormalizeRegNo(pathinfo($label, PATHINFO_FILENAME));
        if ($regNo === '') {
            $unrecognized[] = $label;
            continue;
        }
        $byReg[$regNo][] = ['stored' => $stored, 'label' => $label];
    }

    return ['by_reg' => $byReg, 'unrecognized' => $unrecognized];
}

/**
 * @return list<string>
 */
function studentImportSectionTokens(string $label): array
{
    $tokens = [];
    $add = static function (string $value) use (&$tokens): void {
        $token = studentImportMatchToken($value);
        if ($token === '') {
            return;
        }
        $tokens[$token] = true;
        $stripped = $token;
        foreach (['শাখা', 'section', 'group', 'গ্রুপ', 'বিভাগ'] as $word) {
            $stripped = str_replace($word, '', $stripped);
        }
        if ($stripped !== '') {
            $tokens[$stripped] = true;
        }
    };
    $add($label);
    if (preg_match_all('/\(([^)]+)\)/u', $label, $matches) > 0) {
        foreach ($matches[1] as $inner) {
            $add((string) $inner);
        }
    }
    $withoutParen = trim((string) (preg_replace('/\([^)]*\)/u', '', $label) ?? $label));
    $add($withoutParen);
    $parts = preg_split('/\s+/u', trim($label)) ?: [];
    if (count($parts) > 1) {
        $add((string) $parts[0]);
    }
    if (str_contains($label, 'শিক্ষা')) {
        $add(str_replace('শিক্ষা', '', $label));
    }

    $groups = [
        ['science', 'sci', 'বিজ্ঞান'],
        ['humanities', 'humanity', 'arts', 'art', 'মানবিক'],
        ['businessstudies', 'business', 'commerce', 'com', 'ব্যবসাশিক্ষা', 'ব্যবসাযশিক্ষা', 'ব্যবসা', 'ব্যবসায'],
    ];
    foreach (array_keys($tokens) as $token) {
        foreach ($groups as $group) {
            if (in_array($token, $group, true)) {
                foreach ($group as $alias) {
                    $tokens[$alias] = true;
                }
            }
        }
    }

    return array_keys($tokens);
}

/**
 * @param list<array<string, mixed>> $sections
 * @return array{tokens: array<string, list<int>>, labels: array<int, string>, ids: list<int>}
 */
function studentImportSectionIndex(array $sections): array
{
    $tokens = [];
    $labels = [];
    foreach ($sections as $section) {
        $id = (int) ($section['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $bangla = trim((string) ($section['section_name_ban'] ?? ''));
        $english = trim((string) ($section['section_name'] ?? ''));
        $labels[$id] = $bangla !== '' ? $bangla : $english;
        foreach (array_unique(array_merge(studentImportSectionTokens($english), studentImportSectionTokens($bangla))) as $token) {
            $tokens[$token][] = $id;
        }
    }

    return ['tokens' => $tokens, 'labels' => $labels, 'ids' => array_keys($labels)];
}

/**
 * @param array{tokens: array<string, list<int>>, labels: array<int, string>, ids: list<int>} $index
 * @return array{id: int, label: string, error: string, warning: string}
 */
function studentImportResolveSection(string $label, array $index): array
{
    $ids = $index['ids'];
    if ($ids === []) {
        return ['id' => 0, 'label' => '', 'error' => 'এই শ্রেণিতে কোনো শাখা নেই। আগে শাখা যোগ করুন।', 'warning' => ''];
    }
    if (trim($label) === '') {
        if (count($ids) === 1) {
            $id = (int) $ids[0];

            return ['id' => $id, 'label' => (string) $index['labels'][$id], 'error' => '', 'warning' => 'শাখা ফাঁকা ছিল, তাই একমাত্র শাখা বসানো হয়েছে।'];
        }

        return ['id' => 0, 'label' => '', 'error' => 'শাখার নাম নেই।', 'warning' => ''];
    }

    $hits = [];
    foreach (studentImportSectionTokens($label) as $token) {
        foreach ($index['tokens'][$token] ?? [] as $id) {
            $hits[(int) $id] = true;
        }
    }
    if (count($hits) === 1) {
        $id = (int) array_key_first($hits);

        return ['id' => $id, 'label' => (string) $index['labels'][$id], 'error' => '', 'warning' => ''];
    }
    if (count($hits) > 1) {
        return ['id' => 0, 'label' => trim($label), 'error' => 'শাখা অস্পষ্ট: ' . trim($label), 'warning' => ''];
    }

    return ['id' => 0, 'label' => trim($label), 'error' => 'শাখা মেলেনি: ' . trim($label), 'warning' => ''];
}

/**
 * @param array<string, list<array<string, mixed>>> $locations
 * @return array{divisions: array<string, list<array{id: int, parent: int}>>, districts: array<string, list<array{id: int, parent: int}>>, upazilas: array<string, list<array{id: int, parent: int}>>, districtParent: array<int, int>}
 */
function studentImportPlaceIndex(array $locations): array
{
    $index = ['divisions' => [], 'districts' => [], 'upazilas' => [], 'districtParent' => []];
    $add = static function (string $bucket, string $token, int $id, int $parent) use (&$index): void {
        foreach ($index[$bucket][$token] ?? [] as $existing) {
            if ((int) $existing['id'] === $id) {
                return;
            }
        }
        $index[$bucket][$token][] = ['id' => $id, 'parent' => $parent];
    };
    foreach ($locations['divisions'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        foreach (['name', 'bn_name'] as $field) {
            $token = studentImportMatchToken((string) ($row[$field] ?? ''));
            if ($token !== '') {
                $add('divisions', $token, $id, 0);
            }
        }
    }
    foreach (['districts' => 'division_id', 'upazilas' => 'district_id'] as $bucket => $parentField) {
        foreach ($locations[$bucket] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $parent = (int) ($row[$parentField] ?? 0);
            if ($id < 1) {
                continue;
            }
            if ($bucket === 'districts') {
                $index['districtParent'][$id] = $parent;
            }
            foreach (['name', 'bn_name'] as $field) {
                $token = studentImportMatchToken((string) ($row[$field] ?? ''));
                if ($token !== '') {
                    $add($bucket, $token, $id, $parent);
                }
            }
        }
    }

    return $index;
}

/**
 * @param array<string, list<array{id: int, parent: int}>> $byToken
 * @return array{id: int, parent: int, error: string}
 */
function studentImportMatchPlace(string $label, array $byToken, int $parentId, string $noun): array
{
    $token = studentImportMatchToken($label);
    if ($token === '') {
        return ['id' => 0, 'parent' => 0, 'error' => ''];
    }
    $hits = [];
    foreach ($byToken[$token] ?? [] as $hit) {
        if ($parentId > 0 && (int) $hit['parent'] !== $parentId) {
            continue;
        }
        $hits[(int) $hit['id']] = $hit;
    }
    if (count($hits) === 1) {
        $hit = array_values($hits)[0];

        return ['id' => (int) $hit['id'], 'parent' => (int) $hit['parent'], 'error' => ''];
    }
    $shown = studentImportClip(trim($label), 40);
    if (count($hits) > 1) {
        return ['id' => 0, 'parent' => 0, 'error' => $noun . ' অস্পষ্ট: ' . $shown];
    }
    if ($parentId > 0 && isset($byToken[$token])) {
        return ['id' => 0, 'parent' => 0, 'error' => $noun . ' এই নামের জায়গার মধ্যে নেই: ' . $shown];
    }

    return ['id' => 0, 'parent' => 0, 'error' => $noun . ' মেলেনি: ' . $shown];
}

/**
 * @param list<list<string>> $matrix
 * @param list<array{stored: string, label: string}> $photos
 * @param list<array<string, mixed>> $sections
 * @param array<string, string> $existingRegs
 * @param list<array{section_id: int, roll: string}> $existingRolls
 * @param array<string, list<array<string, mixed>>> $locations
 * @return array{
 *   rows: list<array<string, mixed>>,
 *   unused_photos: list<string>,
 *   unrecognized_photos: list<string>,
 *   blocking: int,
 *   ready: int
 * }
 */
function studentImportBuildPreview(
    array $matrix,
    array $photos,
    array $sections,
    array $existingRegs,
    array $existingRolls,
    ?callable $photoReady = null,
    array $locations = []
): array {
    $header = studentImportHeader($matrix);
    $columns = $header['columns'];
    foreach (['gender' => 'লিঙ্গ (gender)', 'religion' => 'ধর্ম (religion)', 'roll' => 'রোল (roll)'] as $field => $label) {
        if (!isset($columns[$field])) {
            throw new RuntimeException('এক্সেলে ' . $label . ' কলাম নেই।');
        }
    }
    if (!isset($columns['name_ban']) && !isset($columns['name_eng'])) {
        throw new RuntimeException('এক্সেলে নামের কলাম নেই।');
    }
    if (!isset($columns['section']) && count($sections) !== 1) {
        throw new RuntimeException('এক্সেলে শাখা (section) কলাম নেই।');
    }

    $sectionIndex = studentImportSectionIndex($sections);
    $photoIndex = studentImportIndexPhotos($photos);
    $placeIndex = studentImportPlaceIndex($locations);
    $rows = [];
    for ($index = $header['line']; $index < count($matrix); $index++) {
        $cells = $matrix[$index];
        $joined = trim(implode('', array_map(static fn ($cell): string => trim((string) $cell), $cells)));
        if ($joined === '') {
            continue;
        }
        if (count($rows) >= 500) {
            throw new RuntimeException('একবারে সর্বোচ্চ ৫০০ জন শিক্ষার্থী নেওয়া যাবে।');
        }
        $read = static function (string $field) use ($cells, $columns): string {
            if (!isset($columns[$field])) {
                return '';
            }

            return trim((string) ($cells[$columns[$field]] ?? ''));
        };
        $errors = [];
        $warnings = [];
        $regRaw = $read('reg_no');
        $regNo = studentImportNormalizeRegNo($regRaw);
        if ($regRaw === '') {
            $errors[] = 'রেজিস্ট্রেশন নম্বর নেই।';
        } elseif ($regNo === '') {
            $errors[] = preg_match('/e\+/i', $regRaw) === 1
                ? 'রেজিস্ট্রেশন নম্বর বৈজ্ঞানিক সংখ্যায় আছে। এক্সেলে কলামটি Text করে আবার সেভ করুন।'
                : 'রেজিস্ট্রেশন নম্বর ৪ থেকে ২০ সংখ্যার হতে হবে।';
        }

        $nameBan = studentImportClip($read('name_ban'));
        $nameEng = studentImportClip($read('name_eng'));
        if ($nameBan === '' && $nameEng === '') {
            $errors[] = 'নাম নেই।';
        } elseif ($nameBan === '') {
            $nameBan = $nameEng;
            $warnings[] = 'বাংলা নাম ইংরেজি নাম থেকে নেওয়া হয়েছে।';
        } elseif ($nameEng === '') {
            $nameEng = $nameBan;
            $warnings[] = 'ইংরেজি নাম বাংলা নাম থেকে নেওয়া হয়েছে।';
        }

        $genderRaw = $read('gender');
        $gender = studentImportNormalizeGender($genderRaw);
        if ($genderRaw === '') {
            $errors[] = 'লিঙ্গ নেই।';
        } elseif ($gender === '') {
            $errors[] = 'লিঙ্গ বোঝা যায়নি: ' . studentImportClip($genderRaw, 40);
        }

        $religionRaw = $read('religion');
        $religion = studentImportNormalizeReligion($religionRaw);
        if ($religionRaw === '') {
            $errors[] = 'ধর্ম নেই।';
        } elseif ($religion === '') {
            $errors[] = 'ধর্ম বোঝা যায়নি: ' . studentImportClip($religionRaw, 40) . '। Islam, Hindu, Buddhist বা Christian দিন।';
        }

        $rollRaw = $read('roll');
        $roll = studentImportNormalizeRoll($rollRaw);
        if ($rollRaw === '') {
            $errors[] = 'রোল নেই।';
        } elseif ($roll === '') {
            $errors[] = 'রোল বোঝা যায়নি: ' . studentImportClip($rollRaw, 20);
        }

        $birthRaw = $read('birth_date');
        $birthDate = studentImportNormalizeBirthDate($birthRaw);
        if ($birthDate === null) {
            $errors[] = 'জন্মতারিখ বোঝা যায়নি: ' . studentImportClip($birthRaw, 30);
            $birthDate = '';
        }

        $section = studentImportResolveSection(isset($columns['section']) ? $read('section') : '', $sectionIndex);
        if ($section['error'] !== '') {
            $errors[] = $section['error'];
        }
        if ($section['warning'] !== '') {
            $warnings[] = $section['warning'];
        }

        $mobile = studentImportClip($read('mobile'), 30);
        $mobileDigits = preg_replace('/\D+/', '', studentImportAsciiNumber($mobile)) ?? '';
        if ($mobile !== '' && preg_match('/^01\d{9}$/', $mobileDigits) !== 1) {
            $warnings[] = 'মোবাইল ১১ সংখ্যার নয়।';
        }
        foreach (['father_nid_no' => 'পিতার এনআইডি', 'mother_nid_no' => 'মাতার এনআইডি', 'birth_reg_no' => 'জন্ম নিবন্ধন'] as $field => $label) {
            if (isset($columns[$field]) && preg_match('/e\+/i', $read($field)) === 1) {
                $errors[] = $label . ' বৈজ্ঞানিক সংখ্যায় আছে। এক্সেলে কলামটি Text করুন।';
            }
        }

        $divisionId = 0;
        $districtId = 0;
        $upazilaId = 0;
        if (isset($columns['division']) && $read('division') !== '') {
            $found = studentImportMatchPlace($read('division'), $placeIndex['divisions'], 0, 'বিভাগ');
            if ($found['error'] !== '') {
                $errors[] = $found['error'];
            } else {
                $divisionId = $found['id'];
            }
        }
        if (isset($columns['district']) && $read('district') !== '') {
            $found = studentImportMatchPlace($read('district'), $placeIndex['districts'], $divisionId, 'জেলা');
            if ($found['error'] !== '') {
                $errors[] = $found['error'];
            } else {
                $districtId = $found['id'];
                if ($divisionId === 0) {
                    $divisionId = (int) ($placeIndex['districtParent'][$districtId] ?? 0);
                }
            }
        }
        if (isset($columns['upazila']) && $read('upazila') !== '') {
            $found = studentImportMatchPlace($read('upazila'), $placeIndex['upazilas'], $districtId, 'উপজেলা');
            if ($found['error'] !== '') {
                $errors[] = $found['error'];
            } else {
                $upazilaId = $found['id'];
                if ($districtId === 0) {
                    $districtId = $found['parent'];
                    if ($divisionId === 0) {
                        $divisionId = (int) ($placeIndex['districtParent'][$districtId] ?? 0);
                    }
                }
            }
        }

        $photo = '';
        $photoLabel = '';
        if ($regNo !== '') {
            $matches = $photoIndex['by_reg'][$regNo] ?? [];
            if ($matches === []) {
                $errors[] = 'ছবি নেই। ফাইলের নাম ' . $regNo . '.jpeg হতে হবে।';
            } elseif (count($matches) > 1) {
                $errors[] = 'একই রেজিস্ট্রেশন নম্বরের একাধিক ছবি আছে।';
            } else {
                $photo = $matches[0]['stored'];
                $photoLabel = $matches[0]['label'];
                if ($photoReady !== null) {
                    $photoError = $photoReady($photo);
                    if (is_string($photoError) && $photoError !== '') {
                        $errors[] = $photoError;
                    }
                }
            }
        }

        $rows[] = [
            'line' => $index + 1,
            'reg_no' => $regNo,
            'name_ban' => $nameBan,
            'name_eng' => $nameEng,
            'fname_ban' => studentImportClip($read('fname_ban')),
            'fname_eng' => studentImportClip($read('fname_eng')),
            'mname_ban' => studentImportClip($read('mname_ban')),
            'mname_eng' => studentImportClip($read('mname_eng')),
            'father_nid_no' => studentImportClip($read('father_nid_no')),
            'mother_nid_no' => studentImportClip($read('mother_nid_no')),
            'fprofession' => studentImportClip($read('fprofession')),
            'mprofession' => studentImportClip($read('mprofession')),
            'post_office' => studentImportClip($read('post_office')),
            'post_office_en' => studentImportClip($read('post_office_en')),
            'village' => studentImportClip($read('village')),
            'village_en' => studentImportClip($read('village_en')),
            'house_name' => studentImportClip($read('house_name')),
            'last_class' => studentImportClip($read('last_class')),
            'passing_year' => studentImportClip($read('passing_year'), 20),
            'last_school_name' => studentImportClip($read('last_school_name')),
            'division_id' => $divisionId,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'gender' => $gender,
            'religion' => $religion,
            'roll' => $roll,
            'section_id' => $section['id'],
            'section_label' => $section['label'],
            'mobile' => $mobile,
            'birth_date' => $birthDate,
            'birth_reg_no' => studentImportClip($read('birth_reg_no')),
            'photo' => $photo,
            'photo_label' => $photoLabel,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    if ($rows === []) {
        throw new RuntimeException('এক্সেলে কোনো শিক্ষার্থী নেই।');
    }

    $regRows = [];
    $rollRows = [];
    foreach ($rows as $position => $row) {
        if ($row['reg_no'] !== '') {
            $regRows[$row['reg_no']][] = $position;
        }
        if ($row['roll'] !== '' && $row['section_id'] > 0) {
            $rollRows[$row['section_id'] . ':' . $row['roll']][] = $position;
        }
    }
    foreach ($regRows as $indexes) {
        if (count($indexes) < 2) {
            continue;
        }
        foreach ($indexes as $position) {
            $rows[$position]['errors'][] = 'একই রেজিস্ট্রেশন নম্বর ফাইলে একাধিকবার আছে।';
        }
    }
    foreach ($rollRows as $indexes) {
        if (count($indexes) < 2) {
            continue;
        }
        foreach ($indexes as $position) {
            $rows[$position]['errors'][] = 'একই শাখায় এই রোল ফাইলে একাধিকবার আছে।';
        }
    }
    foreach ($rows as $position => $row) {
        if ($row['reg_no'] !== '' && isset($existingRegs[$row['reg_no']])) {
            $name = $existingRegs[$row['reg_no']];
            $rows[$position]['errors'][] = 'এই রেজিস্ট্রেশন নম্বর ইতিমধ্যে আছে'
                . ($name !== '' ? ': ' . $name : '') . '।';
        }
        if ($row['roll'] === '' || $row['section_id'] < 1) {
            continue;
        }
        foreach ($existingRolls as $taken) {
            if ((int) $taken['section_id'] === (int) $row['section_id'] && (string) $taken['roll'] === (string) $row['roll']) {
                $rows[$position]['errors'][] = 'এই শাখায় রোল ' . $row['roll'] . ' ইতিমধ্যে একজন ভর্তি শিক্ষার্থীর আছে।';
                break;
            }
        }
    }

    $used = [];
    foreach ($rows as $row) {
        if ($row['reg_no'] !== '') {
            $used[$row['reg_no']] = true;
        }
    }
    $unused = [];
    foreach ($photoIndex['by_reg'] as $regNo => $matches) {
        if (!isset($used[$regNo])) {
            foreach ($matches as $match) {
                $unused[] = $match['label'];
            }
        }
    }

    $blocking = 0;
    $ready = 0;
    foreach ($rows as $row) {
        if ($row['errors'] === []) {
            $ready++;
        } else {
            $blocking++;
        }
    }

    return [
        'rows' => $rows,
        'unused_photos' => $unused,
        'unrecognized_photos' => $photoIndex['unrecognized'],
        'blocking' => $blocking,
        'ready' => $ready,
    ];
}

/**
 * @return list<list<string>>
 */
function studentImportReadSpreadsheet(string $path, string $originalName): array
{
    if (!is_file($path)) {
        throw new RuntimeException('এক্সেল ফাইল পাওয়া যায়নি।');
    }
    if (filesize($path) > 5 * 1024 * 1024) {
        throw new RuntimeException('এক্সেল ফাইল ৫ মেগাবাইটের ছোট হতে হবে।');
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if ($extension === 'xls' || str_starts_with($head, "\xD0\xCF\x11\xE0")) {
        throw new RuntimeException('পুরোনো .xls ফাইল পড়া যায় না। .xlsx বা CSV UTF-8 হিসেবে সেভ করুন।');
    }
    if ($extension === 'xlsx' || str_starts_with($head, 'PK')) {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('সার্ভারে Zip চালু নেই। ফাইলটি CSV UTF-8 হিসেবে সেভ করে আপলোড করুন।');
        }

        return studentImportReadXlsx($path);
    }

    return studentImportReadCsv($path);
}

/**
 * @return list<list<string>>
 */
function studentImportReadXlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('এক্সেল ফাইল খোলা যায়নি। .xlsx বা CSV দিন।');
    }
    try {
        $shared = studentImportXlsxSharedStrings($zip);
        $sheetPath = studentImportFirstSheetPath($zip);
        $sheet = $zip->getFromName($sheetPath);
    } finally {
        $zip->close();
    }
    if (!is_string($sheet) || $sheet === '') {
        throw new RuntimeException('এক্সেলের প্রথম শিট পড়া যায়নি।');
    }

    return studentImportXlsxRows($sheet, $shared);
}

/**
 * @return list<string>
 */
function studentImportXlsxSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') {
        return [];
    }
    $document = studentImportLoadXml($xml);
    $strings = [];
    foreach ($document->getElementsByTagName('si') as $item) {
        $strings[] = studentImportXmlText($item);
    }

    return $strings;
}

function studentImportFirstSheetPath(ZipArchive $zip): string
{
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!is_string($workbook) || !is_string($rels)) {
        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }
        throw new RuntimeException('এক্সেলের প্রথম শিট পাওয়া যায়নি।');
    }
    $book = studentImportLoadXml($workbook);
    $sheets = $book->getElementsByTagName('sheet');
    if ($sheets->length < 1) {
        throw new RuntimeException('এক্সেলের প্রথম শিট পাওয়া যায়নি।');
    }
    $sheet = $sheets->item(0);
    $relationId = '';
    if ($sheet instanceof DOMElement) {
        $relationId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($relationId === '') {
            $relationId = $sheet->getAttribute('r:id');
        }
    }
    $relations = studentImportLoadXml($rels);
    foreach ($relations->getElementsByTagName('Relationship') as $relation) {
        if (!$relation instanceof DOMElement || $relation->getAttribute('Id') !== $relationId) {
            continue;
        }
        $path = studentImportResolveZipPath('xl', $relation->getAttribute('Target'));
        if ($path === '' || str_contains($path, '..') || $zip->locateName($path) === false) {
            break;
        }

        return $path;
    }

    throw new RuntimeException('এক্সেলের প্রথম শিট পাওয়া যায়নি।');
}

function studentImportResolveZipPath(string $baseDir, string $target): string
{
    $target = str_replace('\\', '/', trim($target));
    if ($target === '' || str_contains($target, "\0")) {
        return '';
    }
    $path = str_starts_with($target, '/') ? ltrim($target, '/') : $baseDir . '/' . $target;
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

/**
 * @param list<string> $shared
 * @return list<list<string>>
 */
function studentImportXlsxRows(string $xml, array $shared): array
{
    $document = studentImportLoadXml($xml);
    $rows = [];
    foreach ($document->getElementsByTagName('row') as $rowNode) {
        if (!$rowNode instanceof DOMElement) {
            continue;
        }
        $cells = [];
        $width = 0;
        $cursor = 0;
        foreach ($rowNode->childNodes as $cell) {
            if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                continue;
            }
            $reference = $cell->getAttribute('r');
            $column = $reference !== '' ? studentImportColumnIndex($reference) : $cursor;
            $cursor = $column + 1;
            if ($column > 80) {
                continue;
            }
            $cells[$column] = studentImportXlsxCell($cell, $shared);
            $width = max($width, $column);
        }
        $line = [];
        for ($index = 0; $index <= $width; $index++) {
            $line[] = $cells[$index] ?? '';
        }
        $rows[] = $line;
    }
    if (count($rows) > 520) {
        throw new RuntimeException('একবারে সর্বোচ্চ ৫০০ জন শিক্ষার্থী নেওয়া যাবে।');
    }

    return $rows;
}

/**
 * @param list<string> $shared
 */
function studentImportXlsxCell(DOMElement $cell, array $shared): string
{
    $type = $cell->getAttribute('t');
    if ($type === 'inlineStr') {
        return studentImportXmlText($cell);
    }
    $value = '';
    foreach ($cell->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'v') {
            $value = trim($child->textContent);
            break;
        }
    }
    if ($type === 's') {
        return $shared[(int) $value] ?? '';
    }

    return $value;
}

function studentImportXmlText(DOMNode $node): string
{
    $text = '';
    foreach ($node->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        if ($child->localName === 't') {
            $text .= $child->textContent;
        } elseif ($child->localName === 'r' || $child->localName === 'is') {
            $text .= studentImportXmlText($child);
        }
    }

    return $text;
}

function studentImportLoadXml(string $xml): DOMDocument
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($loaded !== true) {
        throw new RuntimeException('এক্সেল ফাইল পড়া যায়নি।');
    }

    return $document;
}

function studentImportColumnIndex(string $reference): int
{
    if (preg_match('/^([A-Z]+)/i', $reference, $matches) !== 1) {
        return 0;
    }
    $index = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }

    return $index - 1;
}

/**
 * @return list<list<string>>
 */
function studentImportReadCsv(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('CSV ফাইলটি খালি।');
    }
    if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        throw new RuntimeException('CSV ফাইল UTF-8 নয়। এক্সেল থেকে .xlsx বা CSV UTF-8 সেভ করুন।');
    }
    $firstLine = strtok($raw, "\r\n") ?: '';
    $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    if (($counts[$delimiter] ?? 0) < 1) {
        $delimiter = ',';
    }
    $stream = fopen('php://temp', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('CSV ফাইল পড়া যায়নি।');
    }
    fwrite($stream, $raw);
    rewind($stream);
    $rows = [];
    while (($values = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
        if (count($rows) > 520) {
            fclose($stream);
            throw new RuntimeException('একবারে সর্বোচ্চ ৫০০ জন শিক্ষার্থী নেওয়া যাবে।');
        }
        $rows[] = array_map(static fn ($value): string => trim((string) $value), $values);
    }
    fclose($stream);

    return $rows;
}

function studentImportTemplateCsv(): string
{
    $stream = fopen('php://temp', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('নমুনা ফাইল তৈরি করা যায়নি।');
    }
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, [
        'registration_no', 'name_ban', 'name_eng', 'gender', 'religion', 'birth_date', 'birth_reg_no',
        'roll', 'section', 'mobile', 'father_ban', 'father_eng', 'father_nid', 'father_profession', 'mother_ban', 'mother_eng', 'mother_nid', 'mother_profession',
        'division', 'district', 'upazila', 'post_office', 'post_office_en', 'village', 'village_en', 'house_name',
        'last_class', 'passing_year', 'last_school_name',
    ], ',', '"', '\\');
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    if ($csv === false) {
        throw new RuntimeException('নমুনা ফাইল তৈরি করা যায়নি।');
    }

    return $csv;
}

function studentImportPassportJpeg(string $path, int $width = 108, int $height = 137, int $maxBytes = 102400): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new RuntimeException('সার্ভারে ছবি প্রস্তুত করার ব্যবস্থা নেই।');
    }
    $binary = @file_get_contents($path);
    $source = is_string($binary) && $binary !== '' ? @imagecreatefromstring($binary) : false;
    if ($source === false) {
        throw new RuntimeException('ছবি পড়া যায়নি। JPEG বা PNG দিন।');
    }
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth < 1 || $sourceHeight < 1) {
        imagedestroy($source);
        throw new RuntimeException('ছবি পড়া যায়নি।');
    }
    $targetRatio = $width / $height;
    $sourceRatio = $sourceWidth / $sourceHeight;
    if ($sourceRatio > $targetRatio) {
        $cropHeight = $sourceHeight;
        $cropWidth = max(1, min($sourceWidth, (int) round($sourceHeight * $targetRatio)));
        $sourceX = (int) (($sourceWidth - $cropWidth) / 2);
        $sourceY = 0;
    } else {
        $cropWidth = $sourceWidth;
        $cropHeight = max(1, min($sourceHeight, (int) round($sourceWidth / $targetRatio)));
        $sourceX = 0;
        $sourceY = (int) (($sourceHeight - $cropHeight) / 2);
    }
    $canvas = imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        imagedestroy($source);
        throw new RuntimeException('ছবি প্রস্তুত করা যায়নি।');
    }
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
    imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, $width, $height, $cropWidth, $cropHeight);
    imagedestroy($source);

    $jpeg = '';
    $quality = 85;
    do {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            imagedestroy($canvas);
            throw new RuntimeException('ছবি প্রস্তুত করা যায়নি।');
        }
        imagejpeg($canvas, $stream, $quality);
        rewind($stream);
        $jpeg = stream_get_contents($stream);
        fclose($stream);
        $quality -= 7;
    } while (is_string($jpeg) && strlen($jpeg) > $maxBytes && $quality >= 40);
    imagedestroy($canvas);
    if (!is_string($jpeg) || $jpeg === '' || strlen($jpeg) > $maxBytes) {
        throw new RuntimeException('ছবি ১০০ কিলোবাইটের মধ্যে আনা যায়নি।');
    }

    return $jpeg;
}

/**
 * @return list<array{stored: string, label: string}>
 */
function studentImportExtractZip(string $zipPath, string $destination): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('সার্ভারে Zip চালু নেই। ছবির জিপ নেওয়া যাচ্ছে না।');
    }
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('ছবির ফোল্ডার তৈরি করা যায়নি।');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ছবির জিপ খোলা যায়নি।');
    }
    $saved = [];
    $totalBytes = 0;
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, "\0") || str_contains($name, '../') || str_starts_with($name, '/')) {
                throw new RuntimeException('জিপ ফাইলে অনিরাপদ পাথ আছে।');
            }
            $base = basename($name);
            if ($base === '' || str_starts_with($base, '.') || str_contains($name, '__MACOSX/')) {
                continue;
            }
            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $stat = $zip->statIndex($index);
            $size = (int) ($stat['size'] ?? 0);
            if ($size > 8 * 1024 * 1024) {
                throw new RuntimeException('একটি ছবি ৮ মেগাবাইটের বড়: ' . $base);
            }
            $totalBytes += $size;
            if ($totalBytes > 80 * 1024 * 1024 || count($saved) >= 800) {
                throw new RuntimeException('ছবির জিপ অনেক বড়। একবারে ৮০০টির কম ছবি দিন।');
            }
            $stored = (count($saved) + 1) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
            $target = $destination . DIRECTORY_SEPARATOR . $stored;
            $input = $zip->getStream($name);
            if ($input === false) {
                throw new RuntimeException('জিপ থেকে ছবি পড়া যায়নি: ' . $base);
            }
            $output = fopen($target, 'wb');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('ছবি রাখা যায়নি।');
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            if (!studentImportFileLooksLikeImage($target)) {
                unlink($target);
                throw new RuntimeException('এটি ছবির ফাইল নয়: ' . $base);
            }
            $saved[] = ['stored' => $stored, 'label' => $base];
        }
    } finally {
        $zip->close();
    }
    if ($saved === []) {
        throw new RuntimeException('জিপে কোনো JPEG বা PNG ছবি নেই।');
    }

    return $saved;
}

function studentImportFileLooksLikeImage(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $head = fread($handle, 16) ?: '';
    fclose($handle);
    if (str_starts_with($head, "\xFF\xD8\xFF")) {
        return true;
    }
    if (str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")) {
        return true;
    }

    return strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
}

function studentImportStagingRoot(): string
{
    static $root = null;
    if (is_string($root)) {
        return $root;
    }
    $candidates = [];
    if (function_exists('deploymentPrivateMediaRootPath')) {
        try {
            $private = deploymentPrivateMediaRootPath();
            if (is_string($private) && $private !== '') {
                $candidates[] = rtrim($private, '/\\') . DIRECTORY_SEPARATOR . 'student-import';
            }
        } catch (Throwable $exception) {
            $candidates = [];
        }
    }
    $candidates[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bmlhs-student-import';
    foreach ($candidates as $candidate) {
        if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
            continue;
        }
        if (is_writable($candidate)) {
            $root = $candidate;

            return $root;
        }
    }

    throw new RuntimeException('আপলোড রাখার জায়গা তৈরি করা যায়নি।');
}

function studentImportCleanupStaging(int $maxAgeSeconds = 21600): void
{
    $root = studentImportStagingRoot();
    $now = time();
    foreach (scandir($root) ?: [] as $entry) {
        if (preg_match('/^[a-f0-9]{32}$/', $entry) !== 1) {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $entry;
        $stamp = filemtime($path);
        if ($stamp !== false && ($now - $stamp) > $maxAgeSeconds) {
            studentImportDeleteTree($path);
        }
    }
}

function studentImportDeleteTree(string $directory): void
{
    $root = realpath(studentImportStagingRoot());
    $target = realpath($directory);
    if ($root === false || $target === false || $target === $root || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
        return;
    }
    $items = scandir($target) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $target . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $nested = scandir($path) ?: [];
            foreach ($nested as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                $childPath = $path . DIRECTORY_SEPARATOR . $child;
                if (is_dir($childPath)) {
                    continue;
                }
                @unlink($childPath);
            }
            @rmdir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($target);
}

function studentImportStagingDirectory(string $token): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        throw new RuntimeException('এই আপলোড আর সক্রিয় নেই। আবার ফাইল দিন।');
    }
    $root = realpath(studentImportStagingRoot());
    $directory = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $token);
    if ($root === false || $directory === false || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('এই আপলোড আর সক্রিয় নেই। আবার ফাইল দিন।');
    }

    return $directory;
}

/**
 * @param array<string, mixed> $manifest
 */
function studentImportWriteManifest(string $directory, array $manifest): void
{
    $encoded = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json', $encoded, LOCK_EX) === false) {
        throw new RuntimeException('আপলোডের তথ্য রাখা যায়নি।');
    }
}

/**
 * @return array<string, mixed>
 */
function studentImportReadManifest(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
    $raw = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('আপলোডের তথ্য পড়া যায়নি। আবার ফাইল দিন।');
    }

    return $decoded;
}

function studentImportStorePassport(string $sourcePath): string
{
    $binary = studentImportPassportJpeg($sourcePath);
    if (!function_exists('publicSitePath') || !function_exists('ensureWebpCompanion')) {
        throw new RuntimeException('ছবি সেভ করার ব্যবস্থা পাওয়া যায়নি।');
    }
    $directory = publicSitePath('student_pic');
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('ছবির ফোল্ডার তৈরি করা যায়নি।');
    }
    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    if (file_put_contents($directory . DIRECTORY_SEPARATOR . $fileName, $binary, LOCK_EX) === false) {
        throw new RuntimeException('ছবি সেভ করা যায়নি।');
    }
    ensureWebpCompanion('student_pic', $fileName);

    return $fileName;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function studentImportCommit(PDO $db, int $classId, string $sessionYear, array $rows, string $photoDirectory): int
{
    if ($classId < 1 || preg_match('/^\d{4}$/', $sessionYear) !== 1) {
        throw new RuntimeException('শ্রেণি ও শিক্ষাবর্ষ ঠিক নেই।');
    }
    if (function_exists('ensureStudentRegistrationColumn')) {
        ensureStudentRegistrationColumn($db);
    }
    if (!function_exists('columnExists') || !columnExists($db, 'students_info', 'reg_no')) {
        throw new RuntimeException('রেজিস্ট্রেশন নম্বরের ঘর তৈরি করা যায়নি।');
    }
    $ready = [];
    foreach ($rows as $row) {
        if (!is_array($row) || ($row['errors'] ?? []) !== []) {
            throw new RuntimeException('ত্রুটি থাকলে ভর্তি করা যাবে না। ফাইল ঠিক করে আবার আপলোড করুন।');
        }
        $mobileDigits = preg_replace('/\D+/', '', studentImportAsciiNumber((string) ($row['mobile'] ?? ''))) ?? '';
        $password = strlen($mobileDigits) >= 6 ? substr($mobileDigits, -6) : '123456';
        $row['password_hash'] = hashPassword($password);
        $ready[] = $row;
    }
    if ($ready === []) {
        throw new RuntimeException('ভর্তি করার মতো কোনো শিক্ষার্থী নেই।');
    }

    $savedPhotos = [];
    $db->beginTransaction();
    try {
        foreach ($ready as $row) {
            $regNo = (string) $row['reg_no'];
            $existing = fetchRows(
                $db,
                'SELECT id FROM students_info WHERE reg_no = :reg LIMIT 1',
                ['reg' => $regNo]
            );
            if ($existing !== []) {
                throw new RuntimeException('রেজিস্ট্রেশন নম্বর ' . $regNo . ' ইতিমধ্যে আছে।');
            }
            assertActiveStudentRollAvailable(
                $db,
                $classId,
                $sessionYear,
                (string) (int) $row['section_id'],
                (string) $row['roll']
            );
            $photoPath = studentImportConfinedPhoto($photoDirectory, (string) $row['photo']);
            $storedPhoto = studentImportStorePassport($photoPath);
            $savedPhotos[] = $storedPhoto;
            $payload = studentImportInsertPayload($db, $row, $classId, $sessionYear, $storedPhoto);
            $columns = array_keys($payload);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $quoted = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns);
            $db->prepare(
                'INSERT INTO students_info (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')'
            )->execute($payload);
            $studentId = (int) $db->lastInsertId();
            openStudentEnrollmentPeriod(
                $db,
                $studentId,
                $sessionYear,
                $classId,
                (int) $row['section_id'],
                (string) $row['roll'],
                'import'
            );
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($savedPhotos as $photo) {
            deletePublicMediaFile('student_pic', $photo);
        }
        throw $exception;
    }

    return count($ready);
}

function studentImportConfinedPhoto(string $directory, string $stored): string
{
    $stored = basename(str_replace('\\', '/', $stored));
    $root = realpath($directory);
    if ($root === false || $stored === '') {
        throw new RuntimeException('ছবি পাওয়া যায়নি।');
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $stored);
    if ($path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('ছবি পাওয়া যায়নি।');
    }

    return $path;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function studentImportInsertPayload(PDO $db, array $row, int $classId, string $sessionYear, string $photoName): array
{
    $candidates = [
        'admission_token' => uniqueAdmissionToken($db),
        'cls_roll' => (int) $row['roll'],
        'cls' => $classId,
        'sections' => (string) (int) $row['section_id'],
        'session_year' => $sessionYear,
        'name_eng' => (string) $row['name_eng'],
        'name_ban' => (string) $row['name_ban'],
        'fname_eng' => (string) ($row['fname_eng'] ?? ''),
        'fname_ban' => (string) ($row['fname_ban'] ?? ''),
        'fprofession' => (string) ($row['fprofession'] ?? ''),
        'father_nid_no' => (string) ($row['father_nid_no'] ?? ''),
        'mname_eng' => (string) ($row['mname_eng'] ?? ''),
        'mname_ban' => (string) ($row['mname_ban'] ?? ''),
        'mprofession' => (string) ($row['mprofession'] ?? ''),
        'mother_nid_no' => (string) ($row['mother_nid_no'] ?? ''),
        'guardian_name' => '',
        'guardian_relation' => '',
        'guardian_mobile' => '',
        'gender' => (string) $row['gender'],
        'religion' => (string) $row['religion'],
        'birth_date' => (string) ($row['birth_date'] ?? ''),
        'birth_reg_no' => (string) ($row['birth_reg_no'] ?? ''),
        'division' => (int) ($row['division_id'] ?? 0),
        'district' => (int) ($row['district_id'] ?? 0),
        'upazilla' => (int) ($row['upazila_id'] ?? 0),
        'unions' => 0,
        'post_office' => (string) ($row['post_office'] ?? ''),
        'post_office_en' => (string) ($row['post_office_en'] ?? ''),
        'village' => (string) ($row['village'] ?? ''),
        'village_en' => (string) ($row['village_en'] ?? ''),
        'house_name' => (string) ($row['house_name'] ?? ''),
        'last_exam' => '',
        'last_class' => (string) ($row['last_class'] ?? ''),
        'exam_roll' => '',
        'exam_result' => '',
        'passing_year' => (string) ($row['passing_year'] ?? ''),
        'scholarship_stipend_info' => 'not_applicable',
        'last_school_name' => (string) ($row['last_school_name'] ?? ''),
        'mobile' => (string) ($row['mobile'] ?? ''),
        'pass' => (string) $row['password_hash'],
        'student_pic' => $photoName,
        'birth_reg_pic' => '',
        'status' => studentStatusAdmitted(),
        'reg_no' => (string) $row['reg_no'],
    ];
    $payload = [];
    foreach ($candidates as $column => $value) {
        if (columnExists($db, 'students_info', $column)) {
            $payload[$column] = $value;
        }
    }

    return $payload;
}

/**
 * @return array<string, string>
 */
/**
 * @return array{divisions: list<array<string, mixed>>, districts: list<array<string, mixed>>, upazilas: list<array<string, mixed>>}
 */
function studentImportLocationCatalog(PDO $db): array
{
    $catalog = ['divisions' => [], 'districts' => [], 'upazilas' => []];
    if (!function_exists('tableExists') || !function_exists('fetchRows')) {
        return $catalog;
    }
    $tables = [
        'divisions' => '',
        'districts' => 'division_id',
        'upazilas' => 'district_id',
    ];
    foreach ($tables as $table => $parent) {
        if (!tableExists($db, $table)) {
            continue;
        }
        $bangla = function_exists('columnExists') && columnExists($db, $table, 'bn_name') ? 'bn_name' : 'name AS bn_name';
        $parentSql = $parent !== '' ? ', `' . $parent . '`' : '';
        $catalog[$table] = fetchRows($db, 'SELECT id, name, ' . $bangla . $parentSql . ' FROM `' . $table . '`');
    }

    return $catalog;
}

function studentImportExistingRegistrations(PDO $db): array
{
    if (!columnExists($db, 'students_info', 'reg_no')) {
        return [];
    }
    $found = [];
    foreach (fetchRows($db, "SELECT reg_no, name_ban, name_eng FROM students_info WHERE reg_no IS NOT NULL AND TRIM(reg_no) <> ''") as $row) {
        $regNo = studentImportNormalizeRegNo((string) ($row['reg_no'] ?? ''));
        if ($regNo === '') {
            continue;
        }
        $name = trim((string) ($row['name_ban'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['name_eng'] ?? ''));
        }
        $found[$regNo] = $name;
    }

    return $found;
}

/**
 * @return list<array{section_id: int, roll: string}>
 */
function studentImportExistingRolls(PDO $db, int $classId, string $sessionYear): array
{
    $rolls = [];
    $rows = fetchRows(
        $db,
        'SELECT sections, cls_roll FROM students_info
         WHERE status = :status AND cls = :cls AND session_year = :session AND cls_roll IS NOT NULL',
        ['status' => studentStatusAdmitted(), 'cls' => $classId, 'session' => $sessionYear]
    );
    foreach ($rows as $row) {
        $roll = studentImportNormalizeRoll((string) ($row['cls_roll'] ?? ''));
        if ($roll === '') {
            continue;
        }
        $rolls[] = ['section_id' => (int) ($row['sections'] ?? 0), 'roll' => $roll];
    }

    return $rolls;
}

function studentImportIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return (int) match ($unit) {
        'g' => $number * 1073741824,
        'm' => $number * 1048576,
        'k' => $number * 1024,
        default => $number,
    };
}
