<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/school.php';
require_once dirname(__DIR__, 2) . '/shared/academics.php';
require_once dirname(__DIR__, 2) . '/shared/schema.php';
require_once dirname(__DIR__, 2) . '/shared/public-features.php';
require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/deployment-config.php';
require_once dirname(__DIR__, 2) . '/shared/sms.php';

function publicDatabase(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databaseConfig = deploymentDatabaseSettings();
    $host = $databaseConfig['host'];
    $database = $databaseConfig['name'];
    $username = $databaseConfig['username'];
    $password = $databaseConfig['password'];

    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    try {
        ensureSchoolSchema($pdo);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
    }

    return $pdo;
}

function currentSchool(): array
{
    static $settings = null;
    if ($settings === null) {
        $settings = schoolSettings(publicDatabase());
    }

    return $settings;
}

function schoolLogoUrl(array $settings): string
{
    $logo = (string) ($settings['logo'] ?? 'image/school_logo.png');
    if (preg_match('#^https?://#', $logo) === 1) {
        return $logo;
    }

    return $logo;
}

function publicStaffDesignationLabel(string $designation): string
{
    $labels = [
        'head_teacher' => 'Head Teacher',
        'assistant_head_teacher' => 'Asst. Head Teacher',
        'senior_teacher' => 'Senior Teacher',
        'assistant_teacher' => 'Assistant Teacher',
        'office_assistant_cum_computer_perator' => 'Office Asst. Cum. Computer Operator',
        'office_assistant_cum_computer_operator' => 'Office Asst. Cum. Computer Operator',
        'computer_lab_operator' => 'Computer Lab Operator',
        'office_assistant' => 'Office Assistant',
        'security_guard' => 'Security Guard',
        'night_guard' => 'Night Guard',
        'cleaner' => 'Cleaner',
        'nurse_maid' => 'Nurse Maid',
    ];

    return $labels[$designation] ?? $designation;
}

function publicStaffSubjectLabel(string $subject): string
{
    $labels = [
        'bangla' => 'Bangla',
        'english' => 'English',
        'mathematics' => 'Mathematics',
        'religion_islam' => 'Religion (Islam)',
        'religion_hindu' => 'Religion (Hindu)',
        'ict' => 'ICT',
        'biology' => 'Biology',
        'chemistry' => 'Chemistry',
        'physics' => 'Physics',
        'business_studies' => 'Business Studies',
        'accounting' => 'Accounting',
        'finance&banking' => 'Finance & Banking',
        'social_science' => 'Social Science',
        'physical_education' => 'Physical Education',
        'agricultural_science' => 'Agricultural Science',
        'home_science' => 'Home Science',
        'library_science' => 'Library Science',
        'not_applicable' => '',
    ];

    return $labels[$subject] ?? $subject;
}

function publicStaffProfileUrl(int $id): string
{
    return signedRecordUrl('teacher_staff_single', $id, 'teacher');
}

function publicStudentProfileUrl(int $id, string $search = ''): string
{
    $query = [];
    $search = trim($search);
    if ($search !== '') {
        $query['q'] = $search;
    }

    return signedRecordUrl('students_preview', $id, 'student', $query);
}

function publicNoticeUrl(int $id): string
{
    return signedRecordUrl('notice_single', $id, 'notice');
}

function publicGalleryAlbumUrl(int $id): string
{
    return signedRecordUrl('gallery_album', $id, 'gallery_album');
}

function publicSyllabusUrl(int $id): string
{
    return signedRecordUrl('syllabus', $id, 'syllabus');
}

function publicStudentIdFromRequest(): int
{
    return publicRecordId('student');
}

function publicStudentSearchQuery(string $raw): string
{
    $query = trim(asciiDigits($raw));
    $query = preg_replace('/\s+/u', ' ', $query) ?? $query;

    return mb_substr($query, 0, 120, 'UTF-8');
}

function publicStudentSearchLike(string $query): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
}

function publicEnrolledStudentFilterSql(PDO $db): string
{
    $parts = ["s.sections <> 'others'"];
    if (columnExists($db, 'students_info', 'status')) {
        $parts[] = 's.status = 1';
    }

    return implode(' AND ', $parts);
}

function publicStudentSearchFromSql(): string
{
    return 'FROM students_info s
            LEFT JOIN cls c ON s.cls = c.id
            LEFT JOIN sections sec ON CAST(s.sections AS CHAR) = CAST(sec.id AS CHAR)';
}

function publicStudentSearchMatchSql(string $query): array
{
    $needle = mb_strtolower($query, 'UTF-8');
    $like = publicStudentSearchLike($needle);
    $digits = preg_replace('/\D+/', '', $query) ?? '';

    $sql = '(LOWER(CONCAT_WS(\' \',
                s.name_ban, s.name_eng, s.fname_ban, s.fname_eng,
                s.mname_ban, s.mname_eng, s.cls_roll, s.session_year,
                s.mobile, s.admission_token, s.birth_reg_no,
                c.class_name_ban, c.class_name, sec.section_name_ban, sec.section_name
            )) LIKE :q ESCAPE \'\\\\\')';
    $params = ['q' => $like];

    if ($digits !== '') {
        $digitLike = publicStudentSearchLike($digits);
        $sql .= ' OR REPLACE(REPLACE(REPLACE(IFNULL(s.mobile, \'\'), \'-\', \'\'), \' \', \'\'), \'+\', \'\') LIKE :d1 ESCAPE \'\\\\\''
            . ' OR CAST(s.cls_roll AS CHAR) LIKE :d2 ESCAPE \'\\\\\''
            . ' OR CAST(s.session_year AS CHAR) LIKE :d3 ESCAPE \'\\\\\'';
        $params['d1'] = $digitLike;
        $params['d2'] = $digitLike;
        $params['d3'] = $digitLike;
    }

    return ['(' . $sql . ')', $params];
}

function publicStudentSearchExists(PDO $db, string $rawQuery): bool
{
    $query = publicStudentSearchQuery($rawQuery);
    if ($query === '' || !tableExists($db, 'students_info')) {
        return false;
    }
    [$matchSql, $params] = publicStudentSearchMatchSql($query);
    $sql = 'SELECT 1 ' . publicStudentSearchFromSql()
        . ' WHERE ' . publicEnrolledStudentFilterSql($db)
        . ' AND ' . $matchSql
        . ' LIMIT 1';
    $rows = fetchRows($db, $sql, $params);

    return $rows !== [];
}

function searchPublicStudents(PDO $db, string $rawQuery, int $limit = 150): array
{
    $query = publicStudentSearchQuery($rawQuery);
    if ($query === '' || !tableExists($db, 'students_info')) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    [$matchSql, $params] = publicStudentSearchMatchSql($query);
    $sql = 'SELECT s.id, s.cls_roll, s.session_year, s.name_ban, s.name_eng,
                   s.fname_ban, s.fname_eng, s.mname_ban, s.mname_eng,
                   s.birth_date, s.mobile, s.student_pic,
                   c.class_name_ban, c.class_name,
                   sec.section_name_ban, sec.section_name
            ' . publicStudentSearchFromSql() . '
            WHERE ' . publicEnrolledStudentFilterSql($db) . '
              AND ' . $matchSql . '
            ORDER BY ' . studentsListOrderSql() . '
            LIMIT ' . $limit;

    return fetchRows($db, $sql, $params);
}

function publicStudentProfile(PDO $db, int $id): ?array
{
    if ($id <= 0 || !tableExists($db, 'students_info')) {
        return null;
    }
    $rows = fetchRows(
        $db,
        'SELECT s.*,
                c.class_name_ban, c.class_name,
                sec.section_name_ban, sec.section_name,
                divisions.bn_name AS division_bn, divisions.name AS division_en,
                districts.bn_name AS district_bn, districts.name AS district_en,
                upazilas.bn_name AS upazilla_bn, upazilas.name AS upazilla_en
         FROM students_info s
         LEFT JOIN cls c ON s.cls = c.id
         LEFT JOIN sections sec ON CAST(s.sections AS CHAR) = CAST(sec.id AS CHAR)
         LEFT JOIN divisions ON s.division = divisions.id
         LEFT JOIN districts ON s.district = districts.id
         LEFT JOIN upazilas ON s.upazilla = upazilas.id
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $id]
    );

    return $rows[0] ?? null;
}

function publicStudentGenderLabel(string $gender): string
{
    $labels = [
        'male' => 'ছাত্র',
        'female' => 'ছাত্রী',
    ];
    $gender = strtolower(trim($gender));

    return $labels[$gender] ?? $gender;
}

function publicStudentClassLabel(array $row): string
{
    $label = trim((string) ($row['class_name_ban'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['class_name'] ?? ''));
    }

    return $label;
}

function publicStudentSectionLabel(array $row): string
{
    $label = trim((string) ($row['section_name_ban'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['section_name'] ?? ''));
    }

    return $label;
}

function homepageClassSectionChartData(PDO $db): array
{
    if (!tableExists($db, 'students_info')) {
        return [];
    }

    $filter = publicEnrolledStudentFilterSql($db);
    $hasCls = tableExists($db, 'cls');
    $hasSec = tableExists($db, 'sections');
    $classSort = ($hasCls && columnExists($db, 'cls', 'sort_order'))
        ? 'MIN(c.sort_order) ASC, MIN(c.id) ASC'
        : ($hasCls ? 'MIN(c.id) ASC' : 's.cls ASC');
    $sectionSort = ($hasSec && columnExists($db, 'sections', 'sort_order'))
        ? 'MIN(sec.sort_order) ASC, MIN(sec.id) ASC'
        : ($hasSec ? 'MIN(sec.id) ASC' : 's.sections ASC');
    $classSelect = $hasCls
        ? "COALESCE(NULLIF(MAX(c.class_name_ban), ''), NULLIF(MAX(c.class_name), ''), CONCAT('শ্রেণী ', s.cls))"
        : "CONCAT('শ্রেণী ', s.cls)";
    $sectionSelect = $hasSec
        ? "COALESCE(NULLIF(MAX(sec.section_name_ban), ''), NULLIF(MAX(sec.section_name), ''), '')"
        : "''";
    $joinCls = $hasCls ? 'LEFT JOIN cls c ON CAST(s.cls AS CHAR) = CAST(c.id AS CHAR)' : '';
    $joinSec = $hasSec ? 'LEFT JOIN sections sec ON CAST(s.sections AS CHAR) = CAST(sec.id AS CHAR)' : '';

    $rows = fetchRows($db, "
        SELECT {$classSelect} AS class_label,
               {$sectionSelect} AS section_label,
               COUNT(*) AS total
        FROM students_info s
        {$joinCls}
        {$joinSec}
        WHERE {$filter}
        GROUP BY s.cls, s.sections
        ORDER BY {$classSort}, {$sectionSort}
    ");

    $items = [];
    foreach ($rows as $row) {
        $count = (int) ($row['total'] ?? 0);
        if ($count < 1) {
            continue;
        }

        $class = trim((string) ($row['class_label'] ?? ''));
        $section = trim((string) ($row['section_label'] ?? ''));
        $label = $class !== '' ? $class : 'শ্রেণী';
        if ($section !== '') {
            $label .= '-' . $section;
        }
        if (function_exists('english_to_bengali')) {
            $label = english_to_bengali($label);
        }

        $items[] = [
            'label' => $label,
            'value' => $count,
        ];
    }

    return $items;
}

function publicStudentDisplayName(array $row): string
{
    $name = trim((string) ($row['name_ban'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['name_eng'] ?? ''));
    }

    return $name;
}

function publicStaffMobileDisplay(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? $mobile;
    if (strlen($digits) >= 11) {
        return substr($digits, 0, 5) . '-' . substr($digits, 5);
    }

    return $mobile;
}

function publicStaffDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }
    $date = date_create($value);

    return $date instanceof DateTimeInterface ? $date->format('d-m-Y') : $value;
}

function publicHasDisplayValue($value): bool
{
    $text = trim((string) $value);

    return $text !== '' && $text !== '—' && !str_starts_with($text, '0000-00-00');
}

function publicReligionLabel(string $religion): string
{
    $labels = [
        'Islam' => 'ইসলাম',
        'Hindu' => 'হিন্দু',
        'Buddhist' => 'বৌদ্ধ',
        'Christian' => 'খ্রিস্টান',
    ];
    $religion = trim($religion);

    return $labels[$religion] ?? $religion;
}

function echoPublicProfileRow(string $label, $value): bool
{
    if (!publicHasDisplayValue($value)) {
        return false;
    }

    echo '<div><dt>' . h($label) . '</dt><dd>' . h((string) $value) . '</dd></div>';

    return true;
}

/**
 * Drops the "ডাকঘর:", "উপজেলা:" style prefixes so the address reads as one plain line.
 */
function schoolPlainAddress(string $address): string
{
    $clean = preg_replace('/(গ্রাম|ডাকঘর|পোস্ট|থানা|উপজেলা|জেলা|বিভাগ)\s*:\s*/u', '', $address);

    return trim((string) ($clean ?? $address));
}

/**
 * Removes the post-office label while keeping its locality in the address.
 */
function schoolAddressWithoutPostOffice(string $address): string
{
    $clean = preg_replace('/(?:ডাকঘর|পোস্ট\s*অফিস)\s*:\s*/u', '', $address);

    return trim((string) ($clean ?? $address));
}

function schoolMapEmbedSrc(array $school): string
{
    $fallback = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1051.855847980347!2d90.85751129803283!3d22.884504486590195!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3754c6dcb4fcbf97%3A0xcc99e0263d5a323c!2sBhabaniganj%20Multilateral%20High%20School!5e1!3m2!1sen!2sbd!4v1676239598968!5m2!1sen!2sbd';
    $embed = html_entity_decode(trim((string) ($school['map_embed'] ?? '')), ENT_QUOTES, 'UTF-8');
    if ($embed === '') {
        return $fallback;
    }
    if (preg_match('#https://www\.google\.com/maps/embed\?[^"\'\s<>]+#i', $embed, $match) === 1) {
        return $match[0];
    }
    if (preg_match('#^https://www\.google\.com/maps/embed\?#i', $embed) === 1) {
        return $embed;
    }

    return $fallback;
}

function schoolFacebookUrl(array $school): string
{
    $facebook = trim((string) ($school['facebook'] ?? ''));
    if ($facebook === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $facebook) !== 1) {
        return 'https://www.facebook.com/' . ltrim($facebook, '/');
    }

    return $facebook;
}

function publicAbsoluteUrl(string $relative = ''): string
{
    $relative = trim($relative);
    if (preg_match('#^https?://#i', $relative) === 1) {
        return $relative;
    }

    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    $path = $relative === '' ? publicSiteUrl() : publicSiteUrl($relative);
    if ($host === '') {
        return $path;
    }

    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($secure ? 'https' : 'http') . '://' . $host . $path;
}

/**
 * Renders a QR code as inline SVG using the QR encoder bundled with the PDF tools.
 * Returns an empty string when the payload cannot be encoded.
 */
function qrCodeSvg(string $text, string $class = '', string $label = ''): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    require_once dirname(__DIR__) . '/include/barcodes/qrcode.php';

    try {
        $barcode = (new QRcode($text, 'M'))->getBarcodeArray();
    } catch (Throwable $exception) {
        error_log('QR code generation failed: ' . $exception->getMessage());

        return '';
    }

    if (!is_array($barcode) || empty($barcode['bcode'])) {
        return '';
    }

    $quiet = 2;
    $span = max((int) $barcode['num_rows'], (int) $barcode['num_cols']) + ($quiet * 2);

    $path = '';
    foreach ($barcode['bcode'] as $y => $row) {
        $columns = count($row);
        for ($x = 0; $x < $columns; $x++) {
            if ((int) $row[$x] !== 1) {
                continue;
            }
            $run = 0;
            while ($x + $run < $columns && (int) $row[$x + $run] === 1) {
                $run++;
            }
            $path .= 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h' . $run . 'v1h-' . $run . 'z';
            $x += $run - 1;
        }
    }

    return '<svg class="' . h($class) . '" viewBox="0 0 ' . $span . ' ' . $span . '"'
        . ' shape-rendering="crispEdges" role="img" aria-label="' . h($label !== '' ? $label : 'QR code') . '">'
        . '<rect width="' . $span . '" height="' . $span . '" fill="#ffffff"/>'
        . '<path d="' . $path . '" fill="#0f2f26"/>'
        . '</svg>';
}

function homepageGlanceStats(PDO $db): array
{
    $empty = [
        'staff' => 0,
        'boys' => 0,
        'girls' => 0,
        'committee' => 0,
        'chart' => [],
    ];
    $file = projectRootPath() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'homepage-glance.json';
    if (is_file($file) && (time() - (int) @filemtime($file)) < 300) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['staff'], $decoded['boys'], $decoded['girls'], $decoded['committee'], $decoded['chart']) && is_array($decoded['chart'])) {
            return $decoded;
        }
    }

    try {
        $empty['staff'] = tableCount($db, 'teacher_info');
        $empty['boys'] = tableCount($db, 'students_info', "sections <> 'others' AND gender = 'male'");
        $empty['girls'] = tableCount($db, 'students_info', "sections <> 'others' AND gender = 'female'");
        $empty['committee'] = count(publicCommitteeMembers($db, true));
        $empty['chart'] = homepageClassSectionChartData($db);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
    }

    $directory = dirname($file);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    if (is_dir($directory) && is_writable($directory)) {
        @file_put_contents($file, json_encode($empty, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    return $empty;
}

function schoolPageHero(string $title, string $lead = '', string $kicker = 'বিদ্যালয়'): void
{
    $title = trim($title);
    $lead = trim($lead);
    $kicker = trim($kicker);
    ?>
    <header class="school-page-hero">
        <div class="school-page-hero-inner">
            <?php if ($kicker !== ''): ?>
                <p class="school-page-kicker"><?= h($kicker) ?></p>
            <?php endif; ?>
            <h1 class="school-page-title"><?= h($title) ?></h1>
            <?php if ($lead !== ''): ?>
                <p class="school-page-lead"><?= h($lead) ?></p>
            <?php endif; ?>
        </div>
    </header>
    <?php
}

function schoolSectionHead(string $title, string $kicker = '', string $sub = '', string $tag = 'h2'): void
{
    $title = trim($title);
    $kicker = trim($kicker);
    $sub = trim($sub);
    $tag = in_array($tag, ['h1', 'h2', 'h3'], true) ? $tag : 'h2';
    ?>
    <header class="school-section-head">
        <?php if ($kicker !== ''): ?>
            <p class="school-section-kicker"><?= h($kicker) ?></p>
        <?php endif; ?>
        <<?= $tag ?> class="school-section-title"><?= h($title) ?></<?= $tag ?>>
        <?php if ($sub !== ''): ?>
            <p class="school-section-sub"><?= h($sub) ?></p>
        <?php endif; ?>
    </header>
    <?php
}

function schoolHistoryTeaser(string $text, int $maxLines = 4, int $maxChars = 420): array
{
    $text = trim($text);
    if ($text === '') {
        return ['text' => '', 'truncated' => false];
    }

    $lines = preg_split('/\R+/u', $text) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    $truncated = count($lines) > $maxLines;
    if ($truncated) {
        $lines = array_slice($lines, 0, $maxLines);
    }

    $snippet = implode("\n", $lines);
    if (mb_strlen($snippet, 'UTF-8') > $maxChars) {
        $snippet = rtrim(schoolSnippet($snippet, $maxChars), " \t\n\r\0\x0B…");
        $truncated = true;
        $snippet .= '…';
    } elseif (!$truncated && $snippet !== $text) {
        $truncated = true;
    }

    return ['text' => $snippet, 'truncated' => $truncated || mb_strlen($text, 'UTF-8') > mb_strlen($snippet, 'UTF-8')];
}
