<?php
declare(strict_types=1);

/** Religions stored on students_info.religion. */
function subjectAudienceReligions(): array
{
    return [
        'Islam' => 'ইসলাম',
        'Hindu' => 'হিন্দু',
        'Buddhist' => 'বৌদ্ধ',
        'Christian' => 'খ্রিষ্টান',
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
