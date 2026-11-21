<?php
declare(strict_types=1);

require_once __DIR__ . '/student-import.php';

/**
 * @param list<list<string>> $matrix
 * @return array{rows: list<array<string, mixed>>, ready: int, blocking: int, unchanged: int}
 */
function studentBirthdateFixBuildPreview(PDO $db, array $matrix): array
{
    if (!tableExists($db, 'students_info') || !columnExists($db, 'students_info', 'reg_no')) {
        throw new RuntimeException('রেজিস্ট্রেশন নম্বর কলাম নেই। আগে স্কিমা আপডেট দরকার।');
    }
    if (!columnExists($db, 'students_info', 'birth_date')) {
        throw new RuntimeException('জন্মতারিখ কলাম নেই।');
    }
    if ($matrix === []) {
        throw new RuntimeException('এক্সেলে কোনো সারি নেই।');
    }

    $header = array_shift($matrix);
    $columns = [];
    foreach ($header as $index => $cell) {
        $field = studentImportCanonicalHeader((string) $cell);
        if ($field === 'reg_no' || $field === 'birth_date') {
            $columns[$field] = $index;
        }
    }
    if (!isset($columns['reg_no'], $columns['birth_date'])) {
        throw new RuntimeException('শিরোনামে registration_no ও birth_date কলাম লাগবে।');
    }

    $existing = [];
    foreach (fetchRows(
        $db,
        "SELECT id, reg_no, birth_date, name_ban, name_eng, cls_roll
         FROM students_info
         WHERE reg_no IS NOT NULL AND TRIM(reg_no) <> ''"
    ) as $row) {
        $regNo = studentImportNormalizeRegNo((string) ($row['reg_no'] ?? ''));
        if ($regNo === '') {
            continue;
        }
        $existing[$regNo][] = $row;
    }

    $rows = [];
    $seen = [];
    foreach ($matrix as $offset => $cells) {
        $line = $offset + 2;
        $read = static function (string $field) use ($columns, $cells): string {
            $index = $columns[$field] ?? null;
            if ($index === null || !array_key_exists($index, $cells)) {
                return '';
            }

            return trim((string) $cells[$index]);
        };

        $errors = [];
        $regRaw = $read('reg_no');
        $regNo = studentImportNormalizeRegNo($regRaw);
        if ($regRaw === '') {
            $errors[] = 'রেজিস্ট্রেশন নম্বর নেই।';
        } elseif ($regNo === '') {
            $errors[] = 'রেজিস্ট্রেশন নম্বর বোঝা যায়নি: ' . studentImportClip($regRaw, 30);
        } elseif (isset($seen[$regNo])) {
            $errors[] = 'একই রেজিস্ট্রেশন নম্বর ফাইলে আগেও আছে (সারি ' . $seen[$regNo] . ')।';
        } else {
            $seen[$regNo] = $line;
        }

        $birthRaw = $read('birth_date');
        $birthDate = studentImportNormalizeBirthDate($birthRaw);
        if ($birthRaw === '') {
            $errors[] = 'জন্মতারিখ নেই।';
        } elseif ($birthDate === null || $birthDate === '') {
            $errors[] = 'জন্মতারিখ বোঝা যায়নি: ' . studentImportClip($birthRaw, 30);
        }

        $matches = $regNo !== '' ? ($existing[$regNo] ?? []) : [];
        $studentId = 0;
        $current = '';
        $name = '';
        $roll = '';
        if ($regNo !== '' && $errors === []) {
            if ($matches === []) {
                $errors[] = 'ডাটাবেজে এই রেজিস্ট্রেশন নম্বর নেই।';
            } elseif (count($matches) > 1) {
                $errors[] = 'একই রেজিস্ট্রেশন নম্বরের একাধিক শিক্ষার্থী আছে।';
            } else {
                $studentId = (int) $matches[0]['id'];
                $current = trim((string) ($matches[0]['birth_date'] ?? ''));
                $name = trim((string) (($matches[0]['name_ban'] ?? '') !== '' ? $matches[0]['name_ban'] : ($matches[0]['name_eng'] ?? '')));
                $roll = trim((string) ($matches[0]['cls_roll'] ?? ''));
            }
        }

        $unchanged = $errors === [] && $studentId > 0 && $current === $birthDate;
        $rows[] = [
            'line' => $line,
            'reg_no' => $regNo,
            'student_id' => $studentId,
            'name' => $name,
            'roll' => $roll,
            'current_birth_date' => $current,
            'new_birth_date' => $birthDate ?? '',
            'unchanged' => $unchanged,
            'errors' => $errors,
        ];
    }

    if ($rows === []) {
        throw new RuntimeException('এক্সেলে কোনো শিক্ষার্থী নেই।');
    }

    $ready = 0;
    $blocking = 0;
    $unchanged = 0;
    foreach ($rows as $row) {
        if ($row['errors'] !== []) {
            $blocking++;
        } elseif ($row['unchanged']) {
            $unchanged++;
        } else {
            $ready++;
        }
    }

    return [
        'rows' => $rows,
        'ready' => $ready,
        'blocking' => $blocking,
        'unchanged' => $unchanged,
    ];
}

/**
 * @param list<array{student_id: int, new_birth_date: string, errors?: list<string>, unchanged?: bool}> $rows
 */
function studentBirthdateFixApply(PDO $db, array $rows): int
{
    $ready = [];
    foreach ($rows as $row) {
        if (($row['errors'] ?? []) !== [] || !empty($row['unchanged'])) {
            continue;
        }
        $studentId = (int) ($row['student_id'] ?? 0);
        $birthDate = trim((string) ($row['new_birth_date'] ?? ''));
        if ($studentId < 1 || $birthDate === '' || studentImportNormalizeBirthDate($birthDate) !== $birthDate) {
            throw new RuntimeException('সংশোধনের তালিকা অসম্পূর্ণ। আবার ফাইল দিন।');
        }
        $ready[] = ['student_id' => $studentId, 'birth_date' => $birthDate];
    }
    if ($ready === []) {
        return 0;
    }

    $statement = $db->prepare('UPDATE students_info SET birth_date = :birth_date WHERE id = :id');
    $db->beginTransaction();
    try {
        foreach ($ready as $row) {
            $statement->execute([
                'birth_date' => $row['birth_date'],
                'id' => $row['student_id'],
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    return count($ready);
}

function studentBirthdateFixTemplateCsv(): string
{
    $stream = fopen('php://temp', 'r+b');
    if ($stream === false) {
        throw new RuntimeException('নমুনা ফাইল তৈরি করা যায়নি।');
    }
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['registration_no', 'birth_date'], ',', '"', '\\');
    fputcsv($stream, ['2511861724', '2012-05-25'], ',', '"', '\\');
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    if ($csv === false) {
        throw new RuntimeException('নমুনা ফাইল তৈরি করা যায়নি।');
    }

    return $csv;
}
