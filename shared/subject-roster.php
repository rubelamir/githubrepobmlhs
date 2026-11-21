<?php
declare(strict_types=1);

/** Religions stored on students_info.religion. */
function subjectAudienceReligions(): array
{
    return [
        'Islam' => 'ইসলাম',
        'Hindu' => 'হিন্দু',
        'Buddhist' => 'বৌদ্ধ',
        'Christian' => 'খ্রিস্টান',
    ];
}

function subjectAudienceReligion(mixed $value): string
{
    $value = trim((string) $value);

    return array_key_exists($value, subjectAudienceReligions()) ? $value : '';
}

function subjectAudienceLabel(string $religion): string
{
    return subjectAudienceReligions()[$religion] ?? $religion;
}

/** Shared name for alternative papers such as agriculture and home science. */
function subjectChoiceGroup(mixed $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if ($value === '') {
        return '';
    }

    return mb_substr($value, 0, 40);
}

function subjectNameHaystack(array $subject): string
{
    return mb_strtolower(trim((string) ($subject['subject_name_ban'] ?? '') . ' ' . (string) ($subject['subject_name'] ?? '')));
}

function subjectIsAgriculturePaper(array $subject): bool
{
    $name = subjectNameHaystack($subject);

    return str_contains($name, 'কৃষি') || str_contains($name, 'agriculture');
}

function subjectIsHomeSciencePaper(array $subject): bool
{
    $name = subjectNameHaystack($subject);

    return str_contains($name, 'গার্হস্থ্য')
        || str_contains($name, 'home science')
        || str_contains($name, 'home-science')
        || str_contains($name, 'homescience');
}

function subjectReligionFromName(array $subject): string
{
    $name = subjectNameHaystack($subject);
    if ($name === '') {
        return '';
    }
    $keywords = [
        'Islam' => ['ইসলাম', 'islam'],
        'Hindu' => ['হিন্দু', 'hindu'],
        'Buddhist' => ['বৌদ্ধ', 'buddh'],
        'Christian' => ['খ্রিস্ট', 'খ্রিষ্ট', 'christian'],
    ];
    $matched = '';
    foreach ($keywords as $religion => $needles) {
        foreach ($needles as $needle) {
            if (!str_contains($name, $needle) || !subjectNameCarriesReligion($name, $needle)) {
                continue;
            }
            if ($matched !== '' && $matched !== $religion) {
                return '';
            }
            $matched = $religion;
            break;
        }
    }

    return $matched;
}

function subjectNameCarriesReligion(string $name, string $keyword): bool
{
    foreach (['ধর্ম', 'শিক্ষা', 'religion', 'education', 'moral', 'নৈতিক', 'studies'] as $marker) {
        if (str_contains($name, $marker)) {
            return true;
        }
    }
    $rest = str_replace($keyword, '', $name);
    $rest = preg_replace('/[^\p{L}\p{N}]+/u', '', $rest) ?? $rest;

    return $rest === '';
}

/**
 * Islam, Hindu, Buddhist, and Christian papers follow the student's religion
 * when the subject name says so.
 *
 * @param list<array<string, mixed>> $subjects
 * @return list<array<string, mixed>>
 */
function assignReligionSubjects(array $subjects): array
{
    foreach ($subjects as $index => $subject) {
        if (!is_array($subject)) {
            continue;
        }
        $religion = subjectReligionFromName($subject);
        if ($religion === '') {
            continue;
        }
        $subjects[$index]['audience_religion'] = $religion;
    }

    return $subjects;
}

/**
 * Agriculture and home science in the same class are one choice, even when
 * nobody typed a shared group name.
 *
 * @param list<array<string, mixed>> $subjects
 * @return list<array<string, mixed>>
 */
function pairAgricultureHomeScienceSubjects(array $subjects): array
{
    $agriculture = [];
    $home = [];
    foreach ($subjects as $index => $subject) {
        if (!is_array($subject)) {
            continue;
        }
        $isAgriculture = subjectIsAgriculturePaper($subject);
        $isHome = subjectIsHomeSciencePaper($subject);
        if ($isAgriculture && !$isHome) {
            $agriculture[] = $index;
        } elseif ($isHome && !$isAgriculture) {
            $home[] = $index;
        }
    }
    if (count($agriculture) !== 1 || count($home) !== 1) {
        return $subjects;
    }
    $groupA = subjectChoiceGroup($subjects[$agriculture[0]]['choice_group'] ?? '');
    $groupH = subjectChoiceGroup($subjects[$home[0]]['choice_group'] ?? '');
    if ($groupA !== '' && $groupH !== '' && $groupA !== $groupH) {
        return $subjects;
    }
    $group = $groupA !== '' ? $groupA : ($groupH !== '' ? $groupH : 'কৃষি-গার্হস্থ্য');
    $subjects[$agriculture[0]]['choice_group'] = $group;
    $subjects[$home[0]]['choice_group'] = $group;

    return $subjects;
}

/**
 * A paper with a religion is only for students of that religion.
 * A paper in a choice group is only for the student who picked that paper.
 */
function subjectAppliesToStudent(array $subject, string $studentReligion, ?int $chosenSubjectId, ?int $subjectId = null): bool
{
    $id = $subjectId ?? (int) ($subject['id'] ?? 0);
    $audience = subjectAudienceReligion($subject['audience_religion'] ?? '');
    if ($audience !== '' && strcasecmp($audience, trim($studentReligion)) !== 0) {
        return false;
    }
    $group = subjectChoiceGroup($subject['choice_group'] ?? '');
    if ($group === '') {
        return true;
    }

    return $chosenSubjectId !== null && $chosenSubjectId === $id && $id > 0;
}

function subjectRosterNotice(array $subject): string
{
    $parts = [];
    $audience = subjectAudienceReligion($subject['audience_religion'] ?? '');
    if ($audience !== '') {
        $parts[] = 'শুধু ' . subjectAudienceLabel($audience) . ' ধর্মের শিক্ষার্থী';
    }
    $group = subjectChoiceGroup($subject['choice_group'] ?? '');
    if ($group !== '') {
        $parts[] = '«' . $group . '» দল থেকে যাঁরা এই বিষয় নিয়েছেন';
    }
    if ($parts === []) {
        return '';
    }

    return 'এই তালিকায় ' . implode(' এবং ', $parts) . '।';
}
