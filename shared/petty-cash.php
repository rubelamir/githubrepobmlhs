<?php

declare(strict_types=1);

/**
 * Standalone petty cash book — separate from acc_* ledgers and vouchers.
 */

const PETTY_CASH_INCOME = 'income';
const PETTY_CASH_EXPENSE = 'expense';

function pettyCashEnsureSchema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec(
        "CREATE TABLE IF NOT EXISTS petty_cash_book (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entry_type ENUM('income', 'expense') NOT NULL,
            entry_date DATE NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_petty_cash_month (entry_date, entry_type),
            KEY idx_petty_cash_type (entry_type, entry_date, id),
            KEY idx_petty_cash_sort (entry_type, entry_date, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (function_exists('schemaMigrationApplied') && function_exists('recordSchemaMigration')
        && !schemaMigrationApplied($db, 'petty_cash_book_v1')) {
        recordSchemaMigration($db, 'petty_cash_book_v1');
    }

    if (function_exists('ensureColumn')) {
        ensureColumn($db, 'petty_cash_book', 'sort_order', 'INT NOT NULL DEFAULT 0');
    }

    if (function_exists('schemaMigrationApplied') && function_exists('recordSchemaMigration')
        && !schemaMigrationApplied($db, 'petty_cash_sort_order_v1')) {
        $rows = $db->query(
            'SELECT id FROM petty_cash_book ORDER BY entry_date ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $update = $db->prepare('UPDATE petty_cash_book SET sort_order = :sort WHERE id = :id');
        foreach ($rows as $index => $row) {
            $update->execute([
                'sort' => ((int) $index + 1) * 10,
                'id' => (int) $row['id'],
            ]);
        }
        recordSchemaMigration($db, 'petty_cash_sort_order_v1');
    }
}

function pettyCashImportJsonPath(): string
{
    return __DIR__ . '/data/petty-cash-jan21-may26.json';
}

function pettyCashImportMigrationName(): string
{
    return 'petty_cash_import_jan21_may26';
}

/**
 * Loads Jan-21…May-26 cashbook rows from the bundled JSON into petty_cash_book.
 * Replaces any existing rows in that date range. Idempotent via schema_migrations.
 *
 * @return array{skipped: bool, inserted: int, income: int, expense: int, deleted: int, message: string}
 */
function pettyCashImportFromBundledJson(PDO $db, ?int $actorId = null, bool $force = false): array
{
    pettyCashEnsureSchema($db);
    $migration = pettyCashImportMigrationName();
    if (!$force && function_exists('schemaMigrationApplied') && schemaMigrationApplied($db, $migration)) {
        $count = (int) $db->query('SELECT COUNT(*) FROM petty_cash_book')->fetchColumn();

        return [
            'skipped' => true,
            'inserted' => 0,
            'income' => 0,
            'expense' => 0,
            'deleted' => 0,
            'message' => 'Jan-21 থেকে May-26 ইমপোর্ট আগেই সম্পন্ন (মোট ' . $count . 'টি এন্ট্রি)।',
        ];
    }

    $path = pettyCashImportJsonPath();
    if (!is_file($path)) {
        throw new RuntimeException('ইমপোর্ট ফাইল পাওয়া যায়নি।');
    }
    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload) || !isset($payload['entries']) || !is_array($payload['entries'])) {
        throw new RuntimeException('ইমপোর্ট ফাইলটি সঠিক নয়।');
    }

    $from = '2021-01-01';
    $to = '2026-05-31';
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM petty_cash_book WHERE entry_date >= :from AND entry_date <= :to');
        $delete->execute(['from' => $from, 'to' => $to]);
        $deleted = $delete->rowCount();

        $insert = $db->prepare(
            'INSERT INTO petty_cash_book
                (entry_type, entry_date, description, amount, sort_order, created_by, updated_by, created_at, updated_at)
             VALUES
                (:type, :date, :description, :amount, :sort_order, :created_by, :updated_by, :created_at, :updated_at)'
        );
        $income = 0;
        $expense = 0;
        $sort = 0;
        foreach ($payload['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['type'] ?? '');
            if (!in_array($type, [PETTY_CASH_INCOME, PETTY_CASH_EXPENSE], true)) {
                continue;
            }
            $date = pettyCashValidateDate((string) ($entry['date'] ?? ''));
            $description = pettyCashValidateDescription((string) ($entry['description'] ?? ''));
            $paisa = \School\Accounting\Support\Money::fromDb($entry['amount'] ?? '0');
            if ($paisa <= 0) {
                continue;
            }
            $sort += 10;
            $insert->execute([
                'type' => $type,
                'date' => $date,
                'description' => $description,
                'sort_order' => $sort,
                'amount' => \School\Accounting\Support\Money::toDb($paisa),
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($type === PETTY_CASH_INCOME) {
                $income++;
            } else {
                $expense++;
            }
        }
        if (function_exists('recordSchemaMigration') && !schemaMigrationApplied($db, $migration)) {
            recordSchemaMigration($db, $migration);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $inserted = $income + $expense;

    return [
        'skipped' => false,
        'inserted' => $inserted,
        'income' => $income,
        'expense' => $expense,
        'deleted' => $deleted,
        'message' => 'Jan-21 থেকে May-26 পর্যন্ত ' . $inserted . 'টি এন্ট্রি তোলা হয়েছে (আয় ' . $income . ', ব্যয় ' . $expense . ').',
    ];
}

/** @return array<int, string> */
function pettyCashBanglaMonths(): array
{
    return [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
        5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];
}

/** Display label like নভেম্বর-২০২৬ */
function pettyCashMonthLabel(int $year, int $month): string
{
    $names = pettyCashBanglaMonths();
    $name = $names[$month] ?? sprintf('%02d', $month);
    $yearText = function_exists('bnDigits') ? bnDigits((string) $year) : (string) $year;

    return $name . '-' . $yearText;
}

/**
 * Entry dates must fall inside the month currently selected in the UI.
 *
 * @throws RuntimeException
 */
function pettyCashAssertSelectedMonthDate(string $date, string $monthKey): string
{
    $validated = pettyCashValidateDate($date);
    $month = pettyCashParseMonth($monthKey);
    $entryMonth = substr($validated, 0, 7);
    if ($entryMonth !== $month['key']) {
        throw new RuntimeException(
            'শুধুমাত্র নির্বাচিত মাস (' . $month['label'] . ') এর তারিখে আয়-ব্যয় এন্ট্রি করা যাবে।'
        );
    }

    return $validated;
}

/**
 * @return list<array<string, mixed>>
 */
function pettyCashSearch(PDO $db, string $query, int $limit = 100): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    if ($q === '') {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $like = '%' . $q . '%';
    $amount = null;
    if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', str_replace(',', '.', $q)) === 1) {
        $amount = str_replace(',', '.', $q);
    }

    $sql = 'SELECT id, entry_type, entry_date, description, amount, created_by, updated_by, created_at, updated_at
            FROM petty_cash_book
            WHERE description LIKE :like
               OR entry_date LIKE :like_date
               OR CAST(amount AS CHAR) LIKE :like_amount';
    $params = [
        'like' => $like,
        'like_date' => $like,
        'like_amount' => $like,
    ];
    if ($amount !== null) {
        $sql .= ' OR amount = :amount';
        $params['amount'] = $amount;
    }
    $sql .= ' ORDER BY entry_date DESC, id DESC LIMIT ' . $limit;

    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{year: int, month: int, key: string, from: string, to: string, label: string} */
function pettyCashParseMonth(?string $value, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today', new DateTimeZone('Asia/Dhaka'));
    $key = trim((string) $value);
    if (preg_match('/^(\d{4})-(\d{2})$/', $key, $match) === 1) {
        $year = (int) $match[1];
        $month = (int) $match[2];
        if ($month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month));
            if ($start instanceof DateTimeImmutable) {
                return [
                    'year' => $year,
                    'month' => $month,
                    'key' => $start->format('Y-m'),
                    'from' => $start->format('Y-m-d'),
                    'to' => $start->format('Y-m-t'),
                    'label' => pettyCashMonthLabel($year, $month),
                ];
            }
        }
    }

    $start = $today->modify('first day of this month');
    $year = (int) $start->format('Y');
    $month = (int) $start->format('n');

    return [
        'year' => $year,
        'month' => $month,
        'key' => $start->format('Y-m'),
        'from' => $start->format('Y-m-d'),
        'to' => $start->format('Y-m-t'),
        'label' => pettyCashMonthLabel($year, $month),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function pettyCashEntries(PDO $db, string $type, string $from, string $to): array
{
    if (!in_array($type, [PETTY_CASH_INCOME, PETTY_CASH_EXPENSE], true)) {
        return [];
    }
    $statement = $db->prepare(
        'SELECT id, entry_type, entry_date, description, amount, sort_order, created_by, updated_by, created_at, updated_at
         FROM petty_cash_book
         WHERE entry_type = :type AND entry_date >= :from AND entry_date <= :to
         ORDER BY sort_order ASC, entry_date ASC, id ASC'
    );
    $statement->execute(['type' => $type, 'from' => $from, 'to' => $to]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function pettyCashFind(PDO $db, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $statement = $db->prepare(
        'SELECT id, entry_type, entry_date, description, amount, sort_order, created_by, updated_by, created_at, updated_at
         FROM petty_cash_book WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * @return list<array{income: ?array<string, mixed>, expense: ?array<string, mixed>}>
 */
function pettyCashTableRows(array $income, array $expense): array
{
    $count = max(count($income), count($expense));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'income' => $income[$i] ?? null,
            'expense' => $expense[$i] ?? null,
        ];
    }

    return $rows;
}

function pettyCashTotalPaisa(array $entries): int
{
    $total = 0;
    foreach ($entries as $entry) {
        $total += \School\Accounting\Support\Money::fromDb($entry['amount'] ?? 0);
    }

    return $total;
}

function pettyCashValidateDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('তারিখ সঠিক নয়।');
    }

    return $value;
}

function pettyCashValidateDescription(string $value): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($text === '') {
        throw new RuntimeException('বিবরণ / খাত লিখুন।');
    }
    if (mb_strlen($text) > 255) {
        throw new RuntimeException('বিবরণ সর্বোচ্চ ২৫৫ অক্ষর হতে পারে।');
    }

    return $text;
}

/** Whole taka only — rejects paisa fractions. */
function pettyCashParseAmount(string $input): int
{
    $paisa = \School\Accounting\Support\Money::parse($input, 'টাকার অংক');
    if ($paisa % 100 !== 0) {
        throw new RuntimeException('টাকার অংক পূর্ণ সংখ্যায় লিখুন (যেমন ১২৫০)।');
    }

    return $paisa;
}

/** "৳ ১,২৫০" — whole taka, no .00 fraction. */
function pettyCashFormatAmount(int $paisa, bool $symbol = true): string
{
    $sign = $paisa < 0 ? '-' : '';
    $taka = (string) intdiv(abs($paisa), 100);
    if (strlen($taka) > 3) {
        $head = substr($taka, 0, -3);
        $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head) ?? $head;
        $taka = $head . ',' . substr($taka, -3);
    }
    $text = $sign . $taka;
    if (function_exists('bnDigits')) {
        $text = bnDigits($text);
    }

    return ($symbol ? '৳ ' : '') . $text;
}

function pettyCashNextSortOrder(PDO $db, string $type, string $from, string $to): int
{
    $statement = $db->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) FROM petty_cash_book
         WHERE entry_type = :type AND entry_date >= :from AND entry_date <= :to'
    );
    $statement->execute(['type' => $type, 'from' => $from, 'to' => $to]);

    return ((int) $statement->fetchColumn()) + 10;
}

/**
 * @param list<int|string> $orderedIds
 */
function pettyCashReorder(PDO $db, string $type, string $from, string $to, array $orderedIds): void
{
    if (!in_array($type, [PETTY_CASH_INCOME, PETTY_CASH_EXPENSE], true)) {
        throw new RuntimeException('অজানা এন্ট্রি ধরন।');
    }
    $ids = [];
    foreach ($orderedIds as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        throw new RuntimeException('সাজানোর তালিকা খালি।');
    }

    $existing = pettyCashEntries($db, $type, $from, $to);
    $allowed = [];
    foreach ($existing as $row) {
        $allowed[(int) $row['id']] = true;
    }
    if (count($ids) !== count($allowed)) {
        throw new RuntimeException('সাজানোর তালিকা মিলেনি।');
    }
    foreach ($ids as $id) {
        if (!isset($allowed[$id])) {
            throw new RuntimeException('সাজানোর তালিকায় অজানা এন্ট্রি আছে।');
        }
    }

    $statement = $db->prepare(
        'UPDATE petty_cash_book SET sort_order = :sort WHERE id = :id AND entry_type = :type'
    );
    foreach ($ids as $index => $id) {
        $statement->execute([
            'sort' => ($index + 1) * 10,
            'id' => $id,
            'type' => $type,
        ]);
    }
}

function pettyCashInsert(
    PDO $db,
    string $type,
    string $date,
    string $description,
    int $paisa,
    ?int $actorId,
    string $monthKey
): int {
    if (!in_array($type, [PETTY_CASH_INCOME, PETTY_CASH_EXPENSE], true)) {
        throw new RuntimeException('অজানা এন্ট্রি ধরন।');
    }
    if ($paisa <= 0) {
        throw new RuntimeException('টাকার অংক শূন্যের বেশি হতে হবে।');
    }
    if ($paisa % 100 !== 0) {
        throw new RuntimeException('টাকার অংক পূর্ণ সংখ্যায় লিখুন (যেমন ১২৫০)।');
    }
    $validatedDate = pettyCashAssertSelectedMonthDate($date, $monthKey);
    $month = pettyCashParseMonth($monthKey);
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $statement = $db->prepare(
        'INSERT INTO petty_cash_book
            (entry_type, entry_date, description, amount, sort_order, created_by, updated_by, created_at, updated_at)
         VALUES
            (:type, :date, :description, :amount, :sort_order, :created_by, :updated_by, :created_at, :updated_at)'
    );
    $statement->execute([
        'type' => $type,
        'date' => $validatedDate,
        'description' => pettyCashValidateDescription($description),
        'amount' => \School\Accounting\Support\Money::toDb($paisa),
        'sort_order' => pettyCashNextSortOrder($db, $type, $month['from'], $month['to']),
        'created_by' => $actorId,
        'updated_by' => $actorId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $db->lastInsertId();
}

function pettyCashUpdate(
    PDO $db,
    int $id,
    string $date,
    string $description,
    int $paisa,
    ?int $actorId,
    string $monthKey
): void {
    $existing = pettyCashFind($db, $id);
    if ($existing === null) {
        throw new RuntimeException('এন্ট্রিটি পাওয়া যায়নি।');
    }
    if ($paisa <= 0) {
        throw new RuntimeException('টাকার অংক শূন্যের বেশি হতে হবে।');
    }
    if ($paisa % 100 !== 0) {
        throw new RuntimeException('টাকার অংক পূর্ণ সংখ্যায় লিখুন (যেমন ১২৫০)।');
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $statement = $db->prepare(
        'UPDATE petty_cash_book
         SET entry_date = :date, description = :description, amount = :amount,
             updated_by = :updated_by, updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'date' => pettyCashAssertSelectedMonthDate($date, $monthKey),
        'description' => pettyCashValidateDescription($description),
        'amount' => \School\Accounting\Support\Money::toDb($paisa),
        'updated_by' => $actorId,
        'updated_at' => $now,
        'id' => $id,
    ]);
}

function pettyCashDelete(PDO $db, int $id): void
{
    $existing = pettyCashFind($db, $id);
    if ($existing === null) {
        throw new RuntimeException('এন্ট্রিটি পাওয়া যায়নি।');
    }
    $statement = $db->prepare('DELETE FROM petty_cash_book WHERE id = :id');
    $statement->execute(['id' => $id]);
}

/**
 * Max description character length for a side (at least the header label).
 *
 * @param list<array<string, mixed>> $entries
 */
function pettyCashReportMaxDescChars(array $entries): int
{
    $max = mb_strlen('বিবরণ', 'UTF-8');
    foreach ($entries as $entry) {
        $len = mb_strlen(trim((string) ($entry['description'] ?? '')), 'UTF-8');
        if ($len > $max) {
            $max = $len;
        }
    }

    return $max;
}

/**
 * Pane widths for print/PDF — same rules as the on-screen split:
 * 1) date/amount stay content-tight inside each pane
 * 2) both sides equal (50/50) when both need ≥ half or both fit under half
 * 3) if one side's content fits under half and the other needs more, the short
 *    side stays content-fit and the long side takes the leftover
 *
 * @param list<array<string, mixed>> $income
 * @param list<array<string, mixed>> $expense
 * @return array{left: int, right: int, left_fit: bool, right_fit: bool, equal: bool}
 */
function pettyCashReportDescWidthShare(array $income, array $expense): array
{
    // Approximate natural width in % of the full report (landscape ~10pt).
    $fitColsChars = 14; // তারিখ + টাকা
    $fullWidthChars = 72;
    $leftNeedPct = 100 * ($fitColsChars + pettyCashReportMaxDescChars($income)) / $fullWidthChars;
    $rightNeedPct = 100 * ($fitColsChars + pettyCashReportMaxDescChars($expense)) / $fullWidthChars;

    // Keep a usable minimum so date/amount (and Bangla glyphs) are not clipped in mPDF.
    $minFitPct = 22;

    if ($leftNeedPct < 50 && $rightNeedPct > 50) {
        $left = (int) max($minFitPct, min(48, round($leftNeedPct)));

        return [
            'left' => $left,
            'right' => 100 - $left,
            'left_fit' => true,
            'right_fit' => false,
            'equal' => false,
        ];
    }

    if ($rightNeedPct < 50 && $leftNeedPct > 50) {
        $right = (int) max($minFitPct, min(48, round($rightNeedPct)));

        return [
            'left' => 100 - $right,
            'right' => $right,
            'left_fit' => false,
            'right_fit' => true,
            'equal' => false,
        ];
    }

    return [
        'left' => 50,
        'right' => 50,
        'left_fit' => false,
        'right_fit' => false,
        'equal' => true,
    ];
}

/**
 * One income or expense side table for print/PDF.
 *
 * @param list<array<string, mixed>> $entries
 */
function pettyCashReportSideTableHtml(
    string $title,
    array $entries,
    int $totalPaisa,
    string $totalLabel,
    bool $contentFit
): string {
    $descClass = 'desc' . ($contentFit ? ' desc-fit' : '');
    $body = '';
    foreach ($entries as $entry) {
        $body .= '<tr>'
            . '<td class="center fit">' . h(function_exists('accFormatDate') ? accFormatDate((string) $entry['entry_date']) : (string) $entry['entry_date']) . '</td>'
            . '<td class="' . $descClass . '">' . h((string) $entry['description']) . '</td>'
            . '<td class="num fit">' . h(pettyCashFormatAmount(\School\Accounting\Support\Money::fromDb($entry['amount']))) . '</td>'
            . '</tr>';
    }
    if ($body === '') {
        $body = '<tr><td colspan="3" class="center">এই মাসে কোনো এন্ট্রি নেই।</td></tr>';
    }

    return '<table class="side' . ($contentFit ? ' side-fit' : '') . '"><thead>'
        . '<tr><th colspan="3">' . h($title) . '</th></tr>'
        . '<tr><th class="fit">তারিখ</th><th class="' . $descClass . '">বিবরণ</th><th class="fit">টাকা</th></tr>'
        . '</thead><tbody>' . $body
        . '<tr class="totals"><td class="fit" colspan="2"><strong>' . h($totalLabel) . '</strong></td>'
        . '<td class="num fit"><strong>' . h(pettyCashFormatAmount($totalPaisa)) . '</strong></td></tr>'
        . '</tbody></table>';
}

/**
 * Shared Petty Cash report markup used by PDF download and browser print.
 *
 * @param list<array<string, mixed>> $income
 * @param list<array<string, mixed>> $expense
 * @return array{title: string, subtitle: string, html: string, css: string}
 */
function pettyCashReportDocument(
    string $monthLabel,
    string $from,
    string $to,
    array $income,
    array $expense,
    int $incomeTotal,
    int $expenseTotal,
    bool $forBrowser = false
): array {
    require_once __DIR__ . '/mpdf-bangla.php';
    if (!function_exists('schoolPlainAddress')) {
        require_once dirname(__DIR__) . '/bmlhs/includes/site.php';
    }

    $settings = function_exists('schoolSettings') && function_exists('getDatabaseConnection')
        ? schoolSettings(getDatabaseConnection())
        : [];
    $title = 'Petty Cash Book';
    $subtitle = (function_exists('accFormatDate') ? accFormatDate($from) : $from)
        . ' থেকে '
        . (function_exists('accFormatDate') ? accFormatDate($to) : $to)
        . ' · মাস ' . $monthLabel;

    $widths = pettyCashReportDescWidthShare($income, $expense);
    $incomeHtml = pettyCashReportSideTableHtml(
        'আয়ের খাত সমূহ',
        $income,
        $incomeTotal,
        'মোট আয়',
        $widths['left_fit']
    );
    $expenseHtml = pettyCashReportSideTableHtml(
        'ব্যয়ের খাত সমূহ',
        $expense,
        $expenseTotal,
        'মোট ব্যয়',
        $widths['right_fit']
    );

    $diff = $incomeTotal - $expenseTotal;
    $balanceHtml = $diff === 0
        ? '<p class="ok">আয়-ব্যয়ের দুই পাশ মিলেছে।</p>'
        : '<p class="diff">পার্থক্য: ' . h(pettyCashFormatAmount(abs($diff)))
            . ($diff > 0 ? ' (আয় বেশি)' : ' (ব্যয় বেশি)') . '</p>';

    $pad = '';
    if ($settings !== []) {
        $pad = $forBrowser ? pettyCashBrowserPadHtml($settings) : mpdfSchoolPadHtml($settings);
    }

    // Two panes side-by-side (same structure/rules as the on-screen split).
    $html = $pad
        . '<h1>' . h($title) . '</h1>'
        . '<p class="sub">' . h($subtitle) . '</p>'
        . '<table class="split"><tr>'
        . '<td class="pane' . ($widths['left_fit'] ? ' pane-fit' : '') . '" style="width:' . $widths['left'] . '%">'
        . $incomeHtml . '</td>'
        . '<td class="pane' . ($widths['right_fit'] ? ' pane-fit' : '') . '" style="width:' . $widths['right'] . '%">'
        . $expenseHtml . '</td>'
        . '</tr></table>'
        . $balanceHtml;

    if (function_exists('mpdfClearBnDigitsHtml')) {
        $html = mpdfClearBnDigitsHtml($html);
    }

    $font = $forBrowser
        ? '"Noto Sans Bengali", "Hind Siliguri", sans-serif'
        : mpdfBanglaFontFamily();
    $digitFont = $forBrowser
        ? '"Noto Sans Bengali", sans-serif'
        : (function_exists('mpdfBanglaDigitsFontFamily') ? mpdfBanglaDigitsFontFamily() : $font);
    $css = ($forBrowser ? pettyCashBrowserPadCss() : (function_exists('mpdfSchoolPadCss') ? mpdfSchoolPadCss() : ''))
        . "body{font-family:{$font};font-size:10pt;color:#16231e;margin:0;padding:12mm;background:#fff}"
        . ".bn-num{font-family:{$digitFont}}"
        . 'h1{font-size:14pt;margin:2mm 0 1mm;text-align:center}'
        . '.sub{color:#444;margin:0 0 3mm;text-align:center;font-size:10pt}'
        . 'table.split{width:100%;border-collapse:collapse;table-layout:fixed}'
        . 'table.split>tbody>tr>td.pane{vertical-align:top;padding:0;border:none;overflow:visible}'
        . 'table.side{width:100%;border-collapse:collapse;table-layout:auto}'
        . 'table.side th,table.side td{border:0.25mm solid #687b72;padding:1.4mm 1.2mm;vertical-align:top}'
        . 'table.side th{background:#e5ede9;font-weight:bold;text-align:center}'
        . 'table.side td.center,.center{text-align:center}'
        . 'table.side th.fit,table.side td.fit{width:1%;white-space:nowrap}'
        . 'table.side td.num,.num{text-align:right;white-space:nowrap}'
        . 'table.side th.desc,table.side td.desc{width:auto;overflow-wrap:break-word;word-break:break-word;white-space:normal}'
        . 'table.side th.desc-fit,table.side td.desc-fit{width:1%;white-space:nowrap;overflow-wrap:break-word;word-break:break-word}'
        . 'table.side tr.totals td{font-weight:bold;background:#f3f7f5}'
        . 'table.side thead{display:table-header-group}'
        . '.ok{color:#0a7a3e;font-weight:bold;text-align:center;margin-top:3mm}'
        . '.diff{color:#c62828;font-weight:bold;text-align:center;margin-top:3mm}';

    return [
        'title' => $title,
        'subtitle' => $subtitle,
        'html' => $html,
        'css' => $css,
    ];
}

/**
 * Embed the school logo as a data URI so browser print does not depend on
 * relative asset paths under /dist/pages/accounts/.
 *
 * @param array<string, mixed> $settings
 */
function pettyCashBrowserLogoDataUri(array $settings): string
{
    require_once __DIR__ . '/mpdf-bangla.php';
    $path = mpdfSchoolLogoPath($settings);
    if ($path === '' || !is_file($path)) {
        return '';
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return '';
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        default => 'image/png',
    };

    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

/**
 * School letterhead for browser print (embedded logo, same source as the PDF pad).
 *
 * @param array<string, mixed> $settings
 */
function pettyCashBrowserPadHtml(array $settings): string
{
    $logo = pettyCashBrowserLogoDataUri($settings);

    $schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
    $address = function_exists('schoolPlainAddress')
        ? schoolPlainAddress((string) ($settings['address'] ?? ''))
        : trim((string) ($settings['address'] ?? ''));
    $eiin = trim((string) ($settings['eiin'] ?? ''));
    $mobile = function_exists('publicStaffMobileDisplay')
        ? publicStaffMobileDisplay((string) ($settings['mobile'] ?? ''))
        : trim((string) ($settings['mobile'] ?? ''));
    $meta = '';
    if ($eiin !== '') {
        $meta .= 'EIIN: ' . h(function_exists('bnDigits') ? bnDigits($eiin) : $eiin);
    }
    if ($eiin !== '' && $mobile !== '') {
        $meta .= ' · ';
    }
    if ($mobile !== '') {
        $meta .= 'মোবাইল: ' . h(function_exists('bnDigits') ? bnDigits($mobile) : $mobile);
    }

    return '<table class="school-pad"><tr>'
        . '<td class="school-pad-logo">'
        . ($logo !== '' ? '<img class="school-pad-logo-img" src="' . h($logo) . '" width="40" height="40" alt="">' : '')
        . '</td>'
        . '<td class="school-pad-brand">'
        . '<div class="school-pad-name">' . h($schoolName) . '</div>'
        . ($address !== '' ? '<div class="school-pad-meta">' . h($address) . '</div>' : '')
        . ($meta !== '' ? '<div class="school-pad-meta">' . $meta . '</div>' : '')
        . '</td>'
        . '<td class="school-pad-logo"></td>'
        . '</tr></table>';
}

function pettyCashBrowserPadCss(): string
{
    return <<<'CSS'
.school-pad { width: 100%; border-collapse: collapse; margin: 0 0 2.5mm; border-bottom: 0.35mm solid #1a3a30; }
.school-pad td { border: 0 !important; padding: 0 0 1.5mm; vertical-align: middle; background: transparent; }
.school-pad-logo { width: 14mm; text-align: center; }
.school-pad-logo-img, .school-pad-logo img { width: 11mm; height: 11mm; max-width: 11mm; max-height: 11mm; }
.school-pad-brand { text-align: center; }
.school-pad-name { font-size: 15pt; font-weight: bold; line-height: 1.3; color: #174936; }
.school-pad-meta { font-size: 9pt; color: #374b43; margin-top: 0.4mm; }
CSS;
}

function pettyCashRenderBrowserPrint(
    string $monthKey,
    string $monthLabel,
    string $from,
    string $to,
    array $income,
    array $expense,
    int $incomeTotal,
    int $expenseTotal
): void {
    $doc = pettyCashReportDocument($monthLabel, $from, $to, $income, $expense, $incomeTotal, $expenseTotal, true);
    $title = $doc['title'] . ' · ' . $monthLabel;
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;700&display=swap" rel="stylesheet">'
        . '<style>'
        . '@page{size:A4 landscape;margin:10mm}'
        . '@media print{body{padding:0 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . '.no-print{display:none !important}}'
        . '.no-print{margin:0 0 8px;text-align:right}'
        . '.no-print button{font:inherit;padding:6px 12px;cursor:pointer}'
        . $doc['css']
        . '</style></head><body>'
        . '<div class="no-print"><button type="button" onclick="window.print()">প্রিন্ট</button></div>'
        . $doc['html']
        . '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});</script>'
        . '</body></html>';
    exit;
}

function pettyCashDownloadPdf(
    string $monthKey,
    string $monthLabel,
    string $from,
    string $to,
    array $income,
    array $expense,
    int $incomeTotal,
    int $expenseTotal
): void {
    require_once __DIR__ . '/mpdf-bangla.php';
    $doc = pettyCashReportDocument($monthLabel, $from, $to, $income, $expense, $incomeTotal, $expenseTotal, false);
    $mpdf = createBanglaMpdf($doc['title'], $doc['title'], 'A4', 'L');
    $mpdf->SetMargins(12, 12, 12);
    $mpdf->WriteHTML($doc['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($doc['html'], \Mpdf\HTMLParserMode::HTML_BODY);
    $safe = 'petty-cash-' . preg_replace('/[^0-9-]/', '', $monthKey) . '.pdf';
    $mpdf->Output($safe !== '.pdf' ? $safe : 'petty-cash.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}
