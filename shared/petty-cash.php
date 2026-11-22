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
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_petty_cash_month (entry_date, entry_type),
            KEY idx_petty_cash_type (entry_type, entry_date, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (function_exists('schemaMigrationApplied') && function_exists('recordSchemaMigration')
        && !schemaMigrationApplied($db, 'petty_cash_book_v1')) {
        recordSchemaMigration($db, 'petty_cash_book_v1');
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
                (entry_type, entry_date, description, amount, created_by, updated_by, created_at, updated_at)
             VALUES
                (:type, :date, :description, :amount, :created_by, :updated_by, :created_at, :updated_at)'
        );
        $income = 0;
        $expense = 0;
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
            $insert->execute([
                'type' => $type,
                'date' => $date,
                'description' => $description,
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
 * Only the current calendar month (Asia/Dhaka) may receive new or edited entries.
 *
 * @throws RuntimeException
 */
function pettyCashAssertCurrentMonthDate(string $date, ?DateTimeImmutable $today = null): string
{
    $validated = pettyCashValidateDate($date);
    $today ??= new DateTimeImmutable('today', new DateTimeZone('Asia/Dhaka'));
    $entryMonth = substr($validated, 0, 7);
    $currentMonth = $today->format('Y-m');
    if ($entryMonth !== $currentMonth) {
        $currentLabel = pettyCashMonthLabel((int) $today->format('Y'), (int) $today->format('n'));
        throw new RuntimeException(
            'চলতি মাস (' . $currentLabel . ') ব্যতীত অন্য মাসের তারিখে আয়-ব্যয় এন্ট্রি করা যাবে না।'
        );
    }

    return $validated;
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
        'SELECT id, entry_type, entry_date, description, amount, created_by, updated_by, created_at, updated_at
         FROM petty_cash_book
         WHERE entry_type = :type AND entry_date >= :from AND entry_date <= :to
         ORDER BY entry_date ASC, id ASC'
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
        'SELECT id, entry_type, entry_date, description, amount, created_by, updated_by, created_at, updated_at
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

function pettyCashInsert(
    PDO $db,
    string $type,
    string $date,
    string $description,
    int $paisa,
    ?int $actorId
): int {
    if (!in_array($type, [PETTY_CASH_INCOME, PETTY_CASH_EXPENSE], true)) {
        throw new RuntimeException('অজানা এন্ট্রি ধরন।');
    }
    if ($paisa <= 0) {
        throw new RuntimeException('টাকার অংক শূন্যের বেশি হতে হবে।');
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $statement = $db->prepare(
        'INSERT INTO petty_cash_book
            (entry_type, entry_date, description, amount, created_by, updated_by, created_at, updated_at)
         VALUES
            (:type, :date, :description, :amount, :created_by, :updated_by, :created_at, :updated_at)'
    );
    $statement->execute([
        'type' => $type,
        'date' => pettyCashAssertCurrentMonthDate($date),
        'description' => pettyCashValidateDescription($description),
        'amount' => \School\Accounting\Support\Money::toDb($paisa),
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
    ?int $actorId
): void {
    $existing = pettyCashFind($db, $id);
    if ($existing === null) {
        throw new RuntimeException('এন্ট্রিটি পাওয়া যায়নি।');
    }
    if ($paisa <= 0) {
        throw new RuntimeException('টাকার অংক শূন্যের বেশি হতে হবে।');
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $statement = $db->prepare(
        'UPDATE petty_cash_book
         SET entry_date = :date, description = :description, amount = :amount,
             updated_by = :updated_by, updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'date' => pettyCashValidateDate($date),
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
 * @param list<array<string, mixed>> $income
 * @param list<array<string, mixed>> $expense
 */
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

    $rows = pettyCashTableRows($income, $expense);
    $body = '';
    foreach ($rows as $row) {
        $left = $row['income'];
        $right = $row['expense'];
        $body .= '<tr>'
            . '<td class="center">' . ($left ? h(function_exists('accFormatDate') ? accFormatDate((string) $left['entry_date']) : (string) $left['entry_date']) : '') . '</td>'
            . '<td>' . ($left ? h((string) $left['description']) : '') . '</td>'
            . '<td class="num">' . ($left ? h(\School\Accounting\Support\Money::format(\School\Accounting\Support\Money::fromDb($left['amount']))) : '') . '</td>'
            . '<td class="center">' . ($right ? h(function_exists('accFormatDate') ? accFormatDate((string) $right['entry_date']) : (string) $right['entry_date']) : '') . '</td>'
            . '<td>' . ($right ? h((string) $right['description']) : '') . '</td>'
            . '<td class="num">' . ($right ? h(\School\Accounting\Support\Money::format(\School\Accounting\Support\Money::fromDb($right['amount']))) : '') . '</td>'
            . '</tr>';
    }
    if ($body === '') {
        $body = '<tr><td colspan="6" class="center">এই মাসে কোনো এন্ট্রি নেই।</td></tr>';
    }

    $diff = $incomeTotal - $expenseTotal;
    $balanceHtml = $diff === 0
        ? '<p class="ok">আয়-ব্যয়ের দুই পাশ মিলেছে।</p>'
        : '<p class="diff">পার্থক্য: ' . h(\School\Accounting\Support\Money::format(abs($diff)))
            . ($diff > 0 ? ' (আয় বেশি)' : ' (ব্যয় বেশি)') . '</p>';

    $pad = $settings !== [] ? mpdfSchoolPadHtml($settings) : '';
    $font = mpdfBanglaFontFamily();
    $digitFont = function_exists('mpdfBanglaDigitsFontFamily') ? mpdfBanglaDigitsFontFamily() : $font;
    $html = $pad
        . '<h1>' . h($title) . '</h1>'
        . '<p class="sub">' . (function_exists('mpdfClearBnDigitsHtml') ? mpdfClearBnDigitsHtml(h($subtitle)) : h($subtitle)) . '</p>'
        . '<table class="report"><thead>'
        . '<tr><th colspan="3">আয়ের খাত সমূহ</th><th colspan="3">ব্যয়ের খাত সমূহ</th></tr>'
        . '<tr><th>তারিখ</th><th>বিবরণ</th><th>টাকা</th><th>তারিখ</th><th>বিবরণ</th><th>টাকা</th></tr>'
        . '</thead><tbody>' . $body . '</tbody>'
        . '<tfoot><tr>'
        . '<td colspan="2"><strong>মোট আয়</strong></td><td class="num"><strong>'
        . h(\School\Accounting\Support\Money::format($incomeTotal)) . '</strong></td>'
        . '<td colspan="2"><strong>মোট ব্যয়</strong></td><td class="num"><strong>'
        . h(\School\Accounting\Support\Money::format($expenseTotal)) . '</strong></td>'
        . '</tr></tfoot></table>'
        . $balanceHtml;

    if (function_exists('mpdfClearBnDigitsHtml')) {
        $html = mpdfClearBnDigitsHtml($html);
    }

    $mpdf = createBanglaMpdf($title, $title, 'A4', 'L');
    $mpdf->SetMargins(12, 12, 12);
    $css = (function_exists('mpdfSchoolPadCss') ? mpdfSchoolPadCss() : '')
        . "body{font-family:{$font},sans-serif;font-size:10pt}"
        . ".bn-num{font-family:{$digitFont},{$font},sans-serif}"
        . 'h1{font-size:14pt;margin:2mm 0 1mm;text-align:center}'
        . '.sub{color:#444;margin:0 0 3mm;text-align:center;font-size:10pt}'
        . 'table.report{width:100%;border-collapse:collapse}'
        . 'table.report th,table.report td{border:0.25mm solid #687b72;padding:1.4mm 1.2mm;vertical-align:top}'
        . 'table.report th{background:#e5ede9;font-weight:bold;text-align:center}'
        . 'table.report td.center,.center{text-align:center}'
        . 'table.report td.num,.num{text-align:right;white-space:nowrap}'
        . '.ok{color:#0a7a3e;font-weight:bold;text-align:center;margin-top:3mm}'
        . '.diff{color:#c62828;font-weight:bold;text-align:center;margin-top:3mm}';
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
    $safe = 'petty-cash-' . preg_replace('/[^0-9-]/', '', $monthKey) . '.pdf';
    $mpdf->Output($safe !== '.pdf' ? $safe : 'petty-cash.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}
