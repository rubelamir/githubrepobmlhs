<?php
declare(strict_types=1);

function resultsCsvHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

    return trim($value, '_');
}

function readResultsCsv($stream, array $requiredColumns): array
{
    $header = fgetcsv($stream, null, ',', '"', '');
    if (!is_array($header) || $header === []) {
        throw new RuntimeException('CSV ফাইলটি খালি অথবা পড়া যাচ্ছে না।');
    }

    $columns = array_map(
        static fn ($value): string => resultsCsvHeader((string) $value),
        $header
    );
    if (in_array('', $columns, true) || count($columns) !== count(array_unique($columns))) {
        throw new RuntimeException('CSV header খালি বা duplicate; template ডাউনলোড করে ব্যবহার করুন।');
    }

    $allowedColumns = ['roll', 'student_name', 'mcq', 'cq', 'practical', 'total', 'grade'];
    if (array_diff($columns, $allowedColumns) !== []) {
        throw new RuntimeException('CSV-তে অচেনা column আছে; ডাউনলোড করা template ব্যবহার করুন।');
    }
    if (array_diff($requiredColumns, $columns) !== []) {
        throw new RuntimeException('CSV-তে roll ও বিষয়টির প্রয়োজনীয় mark column থাকতে হবে।');
    }

    $rows = [];
    $line = 1;
    while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $line++;
        if (count($rows) >= 2000) {
            throw new RuntimeException('একটি CSV-তে সর্বোচ্চ ২০০০টি student row import করা যাবে।');
        }
        if ($values === [null] || array_filter($values, static fn ($value): bool => trim((string) $value) !== '') === []) {
            continue;
        }
        if (count($values) > count($columns)) {
            throw new RuntimeException('CSV-এর row ' . $line . '-এ প্রয়োজনের চেয়ে বেশি value আছে।');
        }
        $values = array_pad($values, count($columns), '');
        $rows[] = ['line' => $line, 'values' => array_combine($columns, array_map(
            static fn ($value): string => trim((string) $value),
            $values
        ))];
    }

    return $rows;
}

function encodeResultsCsv(array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('CSV তৈরি করা যায়নি।');
    }

    foreach ($rows as $row) {
        $safeRow = array_map(static function ($value): string {
            $value = (string) $value;

            return preg_match('/^[\t\r\n ]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
        }, $row);
        fputcsv($stream, $safeRow, ',', '"', '', "\r\n");
    }

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    if ($csv === false) {
        throw new RuntimeException('CSV তৈরি করা যায়নি।');
    }

    return "\xEF\xBB\xBF" . $csv;
}
