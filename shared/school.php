<?php
declare(strict_types=1);

require_once __DIR__ . '/deployment-config.php';

function projectRootPath(): string
{
    return dirname(__DIR__);
}

function projectBaseUrl(): string
{
    $documentRoot = str_replace('\\', '/', realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
    $root = str_replace('\\', '/', projectRootPath());
    if ($documentRoot !== '' && str_starts_with($root, $documentRoot)) {
        $url = substr($root, strlen($documentRoot));
        return $url === '' ? '' : rtrim($url, '/');
    }

    return '/AdminLTE-4.9.1';
}

function publicSiteUrl(string $path = ''): string
{
    return projectBaseUrl() . '/bmlhs/' . ltrim($path, '/');
}

function adminUrl(string $path = ''): string
{
    return projectBaseUrl() . '/dist/' . ltrim($path, '/');
}

function publicSitePath(string $path = ''): string
{
    $base = projectRootPath() . DIRECTORY_SEPARATOR . 'bmlhs';
    if ($path === '') {
        return $base;
    }

    return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hText(?string $value): string
{
    $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
}

function bnDigits(string $value): string
{
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'],
        $value
    );
}

function formatMarkValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $formatted = (string) $value;
    if (!str_contains($formatted, '.')) {
        return $formatted;
    }

    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * Keep only a non-negative decimal mark string (optional, up to 2 places).
 * Bangla digits become ASCII; letters/signs/extra dots are stripped.
 */
function sanitizeMarkInputString(string $raw): string
{
    $raw = trim(asciiDigits($raw));
    if ($raw === '') {
        return '';
    }

    $cleaned = preg_replace('/[^0-9.]/', '', $raw) ?? '';
    if ($cleaned === '') {
        return '';
    }

    $dotPos = strpos($cleaned, '.');
    if ($dotPos === false) {
        return $cleaned;
    }

    $intPart = substr($cleaned, 0, $dotPos);
    $fracPart = preg_replace('/\./', '', substr($cleaned, $dotPos + 1)) ?? '';
    $intPart = $intPart === '' ? '0' : $intPart;
    $fracPart = substr($fracPart, 0, 2);
    if ($fracPart === '') {
        return $intPart;
    }

    return $intPart . '.' . $fracPart;
}

/**
 * @return array{ok:bool,value:?float,display:string,error:?string}
 */
function parseMarkComponentInput(string $raw, string $componentLabel = 'নম্বর', string $roll = ''): array
{
    $display = sanitizeMarkInputString($raw);
    $prefix = $roll !== '' ? ('রোল ' . $roll . '-এর ') : '';
    if ($display === '') {
        return ['ok' => true, 'value' => null, 'display' => '', 'error' => null];
    }
    if (!is_numeric($display) || !is_finite((float) $display)) {
        return [
            'ok' => false,
            'value' => null,
            'display' => $display,
            'error' => $prefix . $componentLabel . ' শুধু সংখ্যায় দিন।',
        ];
    }
    $value = (float) $display;
    if ($value < 0) {
        return [
            'ok' => false,
            'value' => null,
            'display' => $display,
            'error' => $prefix . $componentLabel . ' ঋণাত্মক হতে পারে না।',
        ];
    }

    return ['ok' => true, 'value' => $value, 'display' => formatMarkValue($value), 'error' => null];
}

function formatFullMarksLabel(float $fullMarks): string
{
    return rtrim(rtrim(number_format($fullMarks, 2, '.', ''), '0'), '.');
}

function asciiDigits(string $value): string
{
    return str_replace(
        ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        $value
    );
}

function studentIdCardRollFilterValues(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $normalized = asciiDigits($raw);
    $normalized = preg_replace('/[–—~]/u', '-', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s*-\s*/', '-', $normalized) ?? $normalized;
    $parts = preg_split('/[,;،\s]+/u', $normalized) ?: [];
    $numbers = [];
    $literals = [];

    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^(\d+)-(\d+)$/', $part, $match) === 1) {
            $from = (int) $match[1];
            $to = (int) $match[2];
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            if ($to - $from > 199) {
                $to = $from + 199;
            }
            for ($n = $from; $n <= $to; $n++) {
                $numbers[] = $n;
            }
            continue;
        }
        if (preg_match('/^\d+$/', $part) === 1) {
            $numbers[] = (int) $part;
            continue;
        }
        $literals[] = $part;
    }

    $values = [];
    foreach (array_unique($numbers) as $number) {
        $plain = (string) $number;
        $padded = str_pad($plain, 2, '0', STR_PAD_LEFT);
        $values[] = $plain;
        $values[] = $padded;
        $values[] = bnDigits($plain);
        $values[] = bnDigits($padded);
    }
    foreach ($literals as $literal) {
        $values[] = $literal;
        $values[] = bnDigits($literal);
    }

    return array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));
}

function tableExists(PDO $db, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($db) . "\0" . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $statement = $db->query('SHOW TABLES LIKE ' . $db->quote($table));
    $cache[$key] = $statement !== false && $statement->fetchColumn() !== false;

    return $cache[$key];
}

function tableCount(PDO $db, string $table, string $where = '1=1'): int
{
    if (!tableExists($db, $table)) {
        return 0;
    }

    return (int) $db->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where}")->fetchColumn();
}

function fetchRows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function schoolSettings(PDO $db): array
{
    $defaults = [
        'school_name_bn' => 'ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়',
        'school_name_en' => 'Bhabanigonj ML High School',
        'short_name_en' => 'BMLHS',
        'address' => 'ডাকঘর: ভবানীগঞ্জ, উপজেলা: লক্ষ্মীপুর সদর, জেলা: লক্ষ্মীপুর।',
        'address_en' => 'Upazila: Lakshmipur Sadar, District: Lakshmipur.',
        'established' => '১৯৪৭ইং',
        'eiin' => '106888',
        'mpo_code' => '1001181302',
        'mobile' => '01309-106888',
        'email' => 'bbhs1947@gmail.com',
        'logo' => 'image/school_logo.png',
        'about_short' => 'শিক্ষা, নৈতিকতা ও সুনাগরিক গড়ার প্রতিশ্রুতিতে পরিচালিত একটি ঐতিহ্যবাহী মাধ্যমিক বিদ্যালয়।',
        'facebook' => 'https://www.facebook.com/BMLHSOfficial',
        'map_embed' => '',
        'nav_cta_label' => 'ভর্তি আবেদন',
        'nav_cta_url' => 'online_admission',
        'footer_credit' => 'ওয়েব ডেভেলপার: রুবেল আমির · ০১৯২৫-৫২১২৫৭',
        'title_messages' => 'সভাপতি ও প্রধান শিক্ষকের বাণী',
        'title_history' => 'বিদ্যালয়ের সংক্ষিপ্ত ইতিহাস',
        'title_info' => 'প্রাতিষ্ঠানিক তথ্য',
        'title_events' => 'সাম্প্রতিক ইভেন্টস',
        'title_ssc' => 'বিগত বছরগুলোর এস.এস.সি পরীক্ষার ফলাফল',
        'title_teachers' => 'বিদ্যালয়ের সম্মানিত শিক্ষক ও কর্মচারীবৃন্দ',
        'title_president' => 'সভাপতি',
        'title_head_teacher' => 'প্রধান শিক্ষক',
        'title_asst_head' => 'সহকারী প্রধান শিক্ষক',
        'title_eservices' => 'আভ্যন্তরীণ ই-সেবাসমূহ',
        'title_important_links' => 'গুরুত্বপূর্ণ লিংক',
        'sms_provider' => '',
        'sms_api_token' => '',
        'sms_sid' => '',
    ];

    if (!tableExists($db, 'school_settings')) {
        return $defaults;
    }

    $row = $db->query('SELECT * FROM school_settings ORDER BY id ASC LIMIT 1')->fetch() ?: [];

    return array_merge($defaults, array_filter($row, static fn ($value) => $value !== null && $value !== ''));
}

function storeUploadedFile(string $field, string $relativeDir, array $allowedExtensions, int $maxBytes = 5242880, bool $verifySignature = false): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }

    $uploadError = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    $original = trim((string) ($_FILES[$field]['name'] ?? ''));
    if ($uploadError === UPLOAD_ERR_NO_FILE || $original === '') {
        return null;
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('File is larger than ' . formatUploadMaxLabel($maxBytes) . '.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }

    if ((int) $_FILES[$field]['size'] > $maxBytes) {
        throw new RuntimeException('File is larger than ' . formatUploadMaxLabel($maxBytes) . '.');
    }

    $original = (string) $_FILES[$field]['name'];
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('This file type is not allowed.');
    }

    $tmpName = (string) $_FILES[$field]['tmp_name'];
    if ($verifySignature && $tmpName !== '' && !uploadedFileSignatureMatches($tmpName, $extension)) {
        throw new RuntimeException('This file type is not allowed.');
    }

    $directory = publicSitePath($relativeDir);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create upload directory.');
    }
    @chmod($directory, 0775);
    if (!is_writable($directory)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!saveUploadedTempFile($tmpName, $target)) {
        throw new RuntimeException('Unable to save the uploaded file.');
    }
    writeImageWebpSibling($target);

    return $filename;
}

/**
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
 * @param list<string> $allowedExtensions
 */
function storeUploadedFileFromArray(array $file, string $relativeDir, array $allowedExtensions, int $maxBytes = 5242880, bool $verifySignature = false): ?string
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $original = trim((string) ($file['name'] ?? ''));
    if ($uploadError === UPLOAD_ERR_NO_FILE || $original === '') {
        return null;
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('File is larger than ' . formatUploadMaxLabel($maxBytes) . '.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }

    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('File is larger than ' . formatUploadMaxLabel($maxBytes) . '.');
    }

    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('This file type is not allowed.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($verifySignature && $tmpName !== '' && !uploadedFileSignatureMatches($tmpName, $extension)) {
        throw new RuntimeException('This file type is not allowed.');
    }

    $directory = publicSitePath($relativeDir);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create upload directory.');
    }
    @chmod($directory, 0775);
    if (!is_writable($directory)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!saveUploadedTempFile($tmpName, $target)) {
        throw new RuntimeException('Unable to save the uploaded file.');
    }
    writeImageWebpSibling($target);

    return $filename;
}

function saveUploadedTempFile(string $tmpName, string $target): bool
{
    if ($tmpName === '' || $target === '') {
        return false;
    }
    if (@move_uploaded_file($tmpName, $target)) {
        return true;
    }
    // Some Windows/XAMPP setups reject move_uploaded_file even for valid uploads.
    if (is_uploaded_file($tmpName) && @copy($tmpName, $target)) {
        @unlink($tmpName);

        return is_file($target);
    }

    return false;
}

function writeImageWebpSibling(string $absolutePath): void
{
    if (!function_exists('imagewebp') || !is_file($absolutePath)) {
        return;
    }
    if (@filesize($absolutePath) > 4 * 1024 * 1024) {
        return;
    }
    $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        return;
    }
    $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $absolutePath);
    if (!is_string($webpPath) || $webpPath === $absolutePath) {
        return;
    }
    if (is_file($webpPath) && filemtime($webpPath) >= filemtime($absolutePath)) {
        return;
    }
    $image = $extension === 'png' ? @imagecreatefrompng($absolutePath) : @imagecreatefromjpeg($absolutePath);
    if ($image === false) {
        return;
    }
    if ($extension === 'png') {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }
    @imagewebp($image, $webpPath, 78);
    imagedestroy($image);
}

function optimizedPublicImageUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^(https?:)?//#i', $url) === 1 || str_starts_with($url, 'data:')) {
        return $url;
    }
    $relative = ltrim(str_replace('\\', '/', $url), '/');
    $publicPrefix = trim(str_replace('\\', '/', publicSiteUrl()), '/');
    if ($publicPrefix !== '' && str_starts_with($relative, $publicPrefix . '/')) {
        $relative = substr($relative, strlen($publicPrefix) + 1);
    }
    if (preg_match('/\.(jpe?g|png)$/i', $relative) !== 1) {
        return $url;
    }
    $absolute = publicSitePath($relative);
    if (!is_file($absolute)) {
        return $url;
    }
    writeImageWebpSibling($absolute);
    $webpRelative = preg_replace('/\.(jpe?g|png)$/i', '.webp', $relative);
    if (!is_string($webpRelative) || !is_file(publicSitePath($webpRelative))) {
        return $url;
    }
    if ($publicPrefix !== '' && str_contains(str_replace('\\', '/', $url), $publicPrefix . '/')) {
        return publicSiteUrl($webpRelative);
    }

    return $webpRelative;
}

/**
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function uploadedFileEntries(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $spec = $_FILES[$field];
    if (!isset($spec['name'])) {
        return [];
    }
    if (!is_array($spec['name'])) {
        return [[
            'name' => (string) $spec['name'],
            'type' => (string) ($spec['type'] ?? ''),
            'tmp_name' => (string) ($spec['tmp_name'] ?? ''),
            'error' => (int) ($spec['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($spec['size'] ?? 0),
        ]];
    }

    $out = [];
    foreach ($spec['name'] as $index => $name) {
        $out[] = [
            'name' => (string) $name,
            'type' => (string) ($spec['type'][$index] ?? ''),
            'tmp_name' => (string) ($spec['tmp_name'][$index] ?? ''),
            'error' => (int) ($spec['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($spec['size'][$index] ?? 0),
        ];
    }

    return $out;
}

function publicMediaBasename(?string $stored): string
{
    $stored = trim(str_replace('\\', '/', (string) $stored));
    if ($stored === '' || preg_match('#^(https?:)?//#i', $stored) === 1) {
        return '';
    }

    $basename = basename($stored);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return '';
    }

    return $basename;
}

function isProtectedPublicMedia(string $basename): bool
{
    return in_array(strtolower($basename), ['school_logo.png', '1.jpg', 'user2-160x160.jpg', 'retired_dummy.svg', 'student.png'], true);
}

function deletePublicMediaFile(string $relativeDir, ?string $stored): bool
{
    $basename = publicMediaBasename($stored);
    if ($basename === '' || isProtectedPublicMedia($basename)) {
        return false;
    }

    $stored = trim(str_replace('\\', '/', (string) $stored));
    $dir = trim(str_replace('\\', '/', $relativeDir), '/');
    if (str_contains($stored, '/')) {
        $fromStored = dirname($stored);
        if ($fromStored !== '.' && $fromStored !== '') {
            $dir = $fromStored;
        }
    }
    if ($dir === '' || str_contains($dir, '..') || str_starts_with($dir, '/')) {
        return false;
    }

    $webpName = webpCompanionName($basename);
    if (function_exists('safeUnlinkUnderDirectory')) {
        if ($webpName !== '') {
            safeUnlinkUnderDirectory(publicSitePath($dir), $webpName);
        }
        return safeUnlinkUnderDirectory(publicSitePath($dir), $basename);
    }

    $baseDir = realpath(publicSitePath($dir));
    if ($baseDir === false || !is_dir($baseDir)) {
        return false;
    }
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $basename;
    if (!is_file($fullPath)) {
        return false;
    }
    $resolved = realpath($fullPath);
    if ($resolved === false || !str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)) {
        return false;
    }
    if ($webpName !== '') {
        @unlink($baseDir . DIRECTORY_SEPARATOR . $webpName);
    }

    return @unlink($resolved);
}

function replacePublicMediaFile(string $relativeDir, ?string $previous, ?string $current): void
{
    $prev = trim((string) $previous);
    $curr = trim((string) $current);
    if ($prev === '' || $prev === $curr) {
        return;
    }
    if ($curr !== '' && publicMediaBasename($prev) === publicMediaBasename($curr)) {
        return;
    }

    deletePublicMediaFile($relativeDir, $prev);
}

function privateMediaDirectoryPath(string $directory): string
{
    if (!in_array($directory, ['birth_reg_pic', 'teacher_nid_pic'], true)) {
        throw new RuntimeException('The private media directory is invalid.');
    }

    $root = deploymentPrivateMediaRootPath();
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create private media storage.');
    }
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
        throw new RuntimeException('Private media storage is unavailable.');
    }
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $document = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $private = str_replace('\\', '/', $resolvedRoot);
        if (strcasecmp($private, $document) === 0 || strncasecmp($private, $document . '/', strlen($document) + 1) === 0) {
            throw new RuntimeException('Private media storage must be outside the web document root.');
        }
    }

    $target = $resolvedRoot . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create private media directory.');
    }
    $resolvedTarget = realpath($target);
    if ($resolvedTarget === false || !str_starts_with($resolvedTarget, $resolvedRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private media directory is invalid.');
    }

    return $resolvedTarget;
}

function privateMediaFilePath(string $directory, string $filename): ?string
{
    $basename = basename(str_replace(["\0", '\\'], ['', '/'], $filename));
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return null;
    }

    $root = deploymentPrivateMediaRootPath();
    $resolvedRoot = realpath($root);
    $resolvedDirectory = realpath($root . DIRECTORY_SEPARATOR . $directory);
    if (
        $resolvedRoot === false
        || $resolvedDirectory === false
        || !str_starts_with($resolvedDirectory, $resolvedRoot . DIRECTORY_SEPARATOR)
    ) {
        return null;
    }
    $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $basename;
    if (!is_file($path)) {
        return null;
    }
    $resolvedPath = realpath($path);
    if ($resolvedPath === false || !str_starts_with($resolvedPath, $resolvedDirectory . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $resolvedPath;
}

function deletePrivateMediaFile(string $directory, ?string $filename): bool
{
    if ($filename === null || $filename === '') {
        return false;
    }

    try {
        $path = privateMediaFilePath($directory, $filename);
        return $path !== null && @unlink($path);
    } catch (RuntimeException $exception) {
        error_log('Private media deletion failed: ' . $exception->getMessage());

        return false;
    }
}

function replacePrivateMediaFile(string $directory, ?string $previous, ?string $current): void
{
    $previous = trim((string) $previous);
    $current = trim((string) $current);
    if ($previous === '' || $previous === $current) {
        return;
    }

    deletePrivateMediaFile($directory, $previous);
}

/**
 * @param array<string, string> $mediaColumns column => upload directory under bmlhs/
 */
function deleteDatabaseRowWithMedia(PDO $db, string $table, int $id, array $mediaColumns): bool
{
    static $allowed = [
        'main_notice' => true,
        'comitee' => true,
        'files' => true,
        'events' => true,
        'event_images' => true,
        'image_gallery' => true,
        'carousel' => true,
        'homepage_info_cards' => true,
        'teacher_info' => true,
        'students_info' => true,
        'school_messages' => true,
        'retirement_teacher' => true,
        'syllabus' => true,
        'meritorious_students' => true,
    ];
    if ($id <= 0 || !isset($allowed[$table])) {
        return false;
    }

    $statement = $db->prepare("SELECT * FROM `{$table}` WHERE id = :id LIMIT 1");
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return false;
    }

    foreach ($mediaColumns as $column => $directory) {
        deletePublicMediaFile($directory, isset($row[$column]) ? (string) $row[$column] : null);
    }

    $db->prepare("DELETE FROM `{$table}` WHERE id = :id")->execute(['id' => $id]);

    return true;
}

function formatUploadMaxLabel(int $maxBytes): string
{
    $megaBytes = $maxBytes / 1048576;
    $label = $megaBytes >= 10
        ? (string) (int) round($megaBytes)
        : rtrim(rtrim(number_format($megaBytes, 1, '.', ''), '0'), '.');

    return $label . ' MB';
}

function uploadedFileSignatureMatches(string $path, string $extension): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 16) ?: '';
    fclose($handle);

    return match ($extension) {
        'jpg', 'jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
        'png' => str_starts_with($header, "\x89PNG\x0D\x0A\x1A\x0A"),
        'gif' => str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a'),
        'webp' => strlen($header) >= 12 && str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP',
        'bmp' => str_starts_with($header, 'BM'),
        'pdf' => str_starts_with($header, '%PDF'),
        'doc', 'xls' => str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'docx', 'xlsx' => str_starts_with($header, "PK\x03\x04") || str_starts_with($header, "PK\x05\x06") || str_starts_with($header, "PK\x07\x08"),
        default => true,
    };
}

function saveCroppedJpeg(string $dataUrl, string $relativeDir, string $fileName = '', int $maxBytes = 150000, int $expectWidth = 0, int $expectHeight = 0): string
{
    $binary = validatedCroppedJpegBinary($dataUrl, $maxBytes, $expectWidth, $expectHeight);
    $directory = publicSitePath($relativeDir);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    if ($fileName === '') {
        $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    }

    $target = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($target, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save the cropped image.');
    }
    ensureWebpCompanion($relativeDir, $fileName);

    return $fileName;
}

function savePrivateCroppedJpeg(string $dataUrl, string $directory, string $fileName = '', int $maxBytes = 150000, int $expectWidth = 0, int $expectHeight = 0): string
{
    $binary = validatedCroppedJpegBinary($dataUrl, $maxBytes, $expectWidth, $expectHeight);
    if ($fileName === '') {
        $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    }
    $fileName = basename(str_replace(["\0", '\\'], ['', '/'], $fileName));
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        throw new RuntimeException('The private image filename is invalid.');
    }

    $target = privateMediaDirectoryPath($directory) . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($target, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save the private image.');
    }

    return $fileName;
}

function validatedCroppedJpegBinary(string $dataUrl, int $maxBytes, int $expectWidth, int $expectHeight): string
{
    if (!preg_match('#^data:image/jpeg;base64,#', $dataUrl)) {
        throw new RuntimeException('Please crop and upload a JPEG image.');
    }

    $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    $imageInfo = $binary !== false ? @getimagesizefromstring($binary) : false;
    if ($binary === false || $imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/jpeg') {
        throw new RuntimeException('Cropped image could not be read.');
    }
    if ($expectWidth > 0 && ((int) $imageInfo[0] !== $expectWidth || (int) $imageInfo[1] !== $expectHeight)) {
        throw new RuntimeException('Cropped image size is invalid.');
    }
    if (strlen($binary) > $maxBytes) {
        throw new RuntimeException('Cropped image is larger than the allowed size.');
    }

    return $binary;
}

function webpCompanionName(string $filename): string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);

    return $base !== '' ? $base . '.webp' : '';
}

function ensureWebpCompanion(string $relativeDir, string $filename): string
{
    $filename = publicMediaBasename($filename);
    $webpName = webpCompanionName($filename);
    if ($filename === '' || $webpName === '') {
        return '';
    }
    $directory = publicSitePath($relativeDir);
    $source = $directory . DIRECTORY_SEPARATOR . $filename;
    $target = $directory . DIRECTORY_SEPARATOR . $webpName;
    if (!is_file($source)) {
        return '';
    }
    if (is_file($target) && filemtime($target) >= filemtime($source)) {
        return $webpName;
    }
    if (!function_exists('imagewebp')) {
        return '';
    }
    $image = @imagecreatefromstring((string) file_get_contents($source));
    if ($image === false) {
        return '';
    }
    imagepalettetotruecolor($image);
    $ok = @imagewebp($image, $target, 78);
    imagedestroy($image);

    return $ok ? $webpName : '';
}

/**
 * @param array<string, string|null> $attributes
 */
function schoolResponsiveImage(string $stored, string $directory, array $attributes = []): string
{
    $src = schoolMediaUrl($stored, $directory);
    $file = publicMediaBasename($stored);
    $webpName = ensureWebpCompanion($directory, $file);
    $webpSrc = $webpName !== '' ? rtrim($directory, '/') . '/' . $webpName : '';
    $attr = '';
    foreach ($attributes as $name => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $attr .= ' ' . $name . '="' . h((string) $value) . '"';
    }
    $img = '<img src="' . h($src) . '"' . $attr . '>';
    if ($webpSrc === '') {
        return $img;
    }

    return '<picture><source srcset="' . h($webpSrc) . '" type="image/webp">' . $img . '</picture>';
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $cache = &columnExistsCache();
    $key = spl_object_id($db) . "\0" . $table;
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        try {
            $statement = $db->prepare(
                'SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            while (($field = $statement->fetchColumn()) !== false) {
                $cache[$key][(string) $field] = true;
            }
        } catch (Throwable $exception) {
            error_log('Column discovery failed for `' . $table . '`: ' . $exception->getMessage());
        }
    }

    return isset($cache[$key][$column]);
}

function ensureColumn(PDO $db, string $table, string $column, string $definition): void
{
    if (!tableExists($db, $table) || columnExists($db, $table, $column)) {
        return;
    }

    $quotedTable = str_replace('`', '``', $table);
    $quotedColumn = str_replace('`', '``', $column);
    $db->exec("ALTER TABLE `{$quotedTable}` ADD `{$quotedColumn}` {$definition}");

    // Keep the per-request cache in sync so later checks do not hit SHOW COLUMNS again.
    $key = spl_object_id($db) . "\0" . $table;
    $cache = &columnExistsCache();
    $cache[$key][$column] = true;
}

function dropColumn(PDO $db, string $table, string $column): void
{
    if (!tableExists($db, $table) || !columnExists($db, $table, $column)) {
        return;
    }

    $quotedTable = str_replace('`', '``', $table);
    $quotedColumn = str_replace('`', '``', $column);
    $db->exec("ALTER TABLE `{$quotedTable}` DROP COLUMN `{$quotedColumn}`");

    $key = spl_object_id($db) . "\0" . $table;
    $cache = &columnExistsCache();
    unset($cache[$key][$column]);
}

/**
 * @return array<string, array<string, bool>>
 */
function &columnExistsCache(): array
{
    static $cache = [];

    return $cache;
}

function refreshColumnCache(PDO $db, string $table): void
{
    $key = spl_object_id($db) . "\0" . $table;
    $cache = &columnExistsCache();
    unset($cache[$key]);
}

function userFacingError(Throwable $exception): string
{
    if ($exception instanceof PDOException) {
        error_log($exception->getMessage());

        return 'The request could not be completed. Please try again.';
    }

    if ($exception instanceof RuntimeException) {
        $message = trim($exception->getMessage());
        if ($message !== '') {
            return $message;
        }
    }

    error_log($exception::class . ': ' . $exception->getMessage());

    return 'The request could not be completed. Please try again.';
}

function redirectWithMessage(string $url, string $type, string $message): void
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_message'] = $message;
    header('Location: ' . $url, true, 302);
    exit;
}

function consumeFlash(): array
{
    $type = (string) ($_SESSION['flash_type'] ?? '');
    $message = (string) ($_SESSION['flash_message'] ?? '');
    unset($_SESSION['flash_type'], $_SESSION['flash_message']);

    return [$type, $message];
}

function currentNav(): string
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return pathinfo($script, PATHINFO_FILENAME);
}

function seedDefaultNavMenus(PDO $db): void
{
    if (!tableExists($db, 'nav_menus')) {
        return;
    }

    if ((int) $db->query('SELECT COUNT(*) FROM nav_menus')->fetchColumn() > 0) {
        return;
    }

    $defaults = [
        ['হোম', 'Home', 'index', null, 10],
        ['আমাদের সম্পর্কে', 'About', '#', null, 20],
        ['ইতিহাস', 'History', 'about', null, 21],
        ['ম্যানেজিং কমিটি', 'Committee', 'managing_comitee', null, 22],
        ['শিক্ষক ও কর্মচারীবৃন্দ', 'Teachers', 'teacher_staff', null, 23],
        ['প্রাক্তন শিক্ষকবৃন্দ', 'Former Teachers', 'retirement_teacher', null, 24],
        ['একাডেমিক্স', 'Academics', '#', null, 30],
        ['ছাত্র-ছাত্রী', 'Students', 'students', null, 31],
        ['কৃতি শিক্ষার্থী', 'Meritorious', 'meritorious_students', null, 32],
        ['সিলেবাস', 'Syllabus', 'syllabus', null, 33],
        ['একাডেমিক ক্যালেন্ডার', 'Calendar', 'academic_calender', null, 34],
        ['ফলাফল', 'Results', 'result', null, 35],
        ['হাজিরা', 'Attendance', 'attendance', null, 36],
        ['শ্রেণী রুটিন', 'Routine', 'routine', null, 37],
        ['ভর্তি', 'Admission', 'online_admission', null, 40],
        ['নোটিশ', 'Notices', 'notice', null, 50],
        ['ছবির গ্যালারী', 'Gallery', 'image_gallery', null, 60],
        ['স্কলারশিপ', 'Scholarship', 'stipend_scholarship_students_list', null, 70],
        ['ডাউনলোড', 'Downloads', 'download_preview', null, 80],
        ['যোগাযোগ', 'Contact', 'contact', null, 90],
    ];

    // Insert top-level items first, then wire parent_id for dropdown children.
    $insert = $db->prepare(
        'INSERT INTO nav_menus (label_bn, label_en, url, parent_id, sort_order, is_active, target)
         VALUES (:label_bn, :label_en, :url, NULL, :sort_order, 1, :target)'
    );

    $idsByLabel = [];
    foreach ($defaults as [$labelBn, $labelEn, $url, , $sort]) {
        $insert->execute([
            'label_bn' => $labelBn,
            'label_en' => $labelEn,
            'url' => $url,
            'sort_order' => $sort,
            'target' => '_self',
        ]);
        $idsByLabel[$labelBn] = (int) $db->lastInsertId();
    }

    $parentMap = [
        'ইতিহাস' => 'আমাদের সম্পর্কে',
        'ম্যানেজিং কমিটি' => 'আমাদের সম্পর্কে',
        'শিক্ষক ও কর্মচারীবৃন্দ' => 'আমাদের সম্পর্কে',
        'প্রাক্তন শিক্ষকবৃন্দ' => 'আমাদের সম্পর্কে',
        'ছাত্র-ছাত্রী' => 'একাডেমিক্স',
        'কৃতি শিক্ষার্থী' => 'একাডেমিক্স',
        'সিলেবাস' => 'একাডেমিক্স',
        'একাডেমিক ক্যালেন্ডার' => 'একাডেমিক্স',
        'ফলাফল' => 'একাডেমিক্স',
        'হাজিরা' => 'একাডেমিক্স',
        'শ্রেণী রুটিন' => 'একাডেমিক্স',
    ];

    $updateParent = $db->prepare('UPDATE nav_menus SET parent_id = :parent_id WHERE id = :id');
    foreach ($parentMap as $childLabel => $parentLabel) {
        if (!isset($idsByLabel[$childLabel], $idsByLabel[$parentLabel])) {
            continue;
        }
        $updateParent->execute([
            'parent_id' => $idsByLabel[$parentLabel],
            'id' => $idsByLabel[$childLabel],
        ]);
    }
}

function ensureAttendanceNavMenu(PDO $db): void
{
    if (!tableExists($db, 'nav_menus')) {
        return;
    }

    $existing = fetchRows(
        $db,
        "SELECT id FROM nav_menus WHERE url IN ('attendance', 'attendance.php') LIMIT 1"
    );
    if ($existing !== []) {
        return;
    }

    $parentRows = fetchRows(
        $db,
        "SELECT id FROM nav_menus
         WHERE label_bn = 'একাডেমিক্স' AND (parent_id IS NULL OR parent_id = 0)
         LIMIT 1"
    );
    $parentId = $parentRows !== [] ? (int) $parentRows[0]['id'] : null;
    $sort = $parentId !== null
        ? (int) $db->query(
            'SELECT COALESCE(MAX(sort_order), 30) + 1 FROM nav_menus WHERE parent_id = ' . $parentId
        )->fetchColumn()
        : (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM nav_menus')->fetchColumn();

    $db->prepare(
        'INSERT INTO nav_menus (label_bn, label_en, url, parent_id, sort_order, is_active, target)
         VALUES (:label_bn, :label_en, :url, :parent_id, :sort_order, 1, :target)'
    )->execute([
        'label_bn' => 'হাজিরা',
        'label_en' => 'Attendance',
        'url' => 'attendance',
        'parent_id' => $parentId,
        'sort_order' => $sort,
        'target' => '_self',
    ]);
}

function ensureRoutineNavMenu(PDO $db): void
{
    if (!tableExists($db, 'nav_menus')) {
        return;
    }

    $existing = fetchRows(
        $db,
        "SELECT id FROM nav_menus WHERE url IN ('routine', 'routine.php') LIMIT 1"
    );
    if ($existing !== []) {
        return;
    }

    $parentRows = fetchRows(
        $db,
        "SELECT id FROM nav_menus
         WHERE label_bn = 'একাডেমিক্স' AND (parent_id IS NULL OR parent_id = 0)
         LIMIT 1"
    );
    $parentId = $parentRows !== [] ? (int) $parentRows[0]['id'] : null;
    $sort = $parentId !== null
        ? (int) $db->query(
            'SELECT COALESCE(MAX(sort_order), 30) + 1 FROM nav_menus WHERE parent_id = ' . $parentId
        )->fetchColumn()
        : (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM nav_menus')->fetchColumn();

    $db->prepare(
        'INSERT INTO nav_menus (label_bn, label_en, url, parent_id, sort_order, is_active, target)
         VALUES (:label_bn, :label_en, :url, :parent_id, :sort_order, 1, :target)'
    )->execute([
        'label_bn' => 'শ্রেণী রুটিন',
        'label_en' => 'Routine',
        'url' => 'routine',
        'parent_id' => $parentId,
        'sort_order' => $sort,
        'target' => '_self',
    ]);
}

function publicNavHref(string $url): string
{
    $url = trim($url);
    if ($url === '' || $url === '#') {
        return '#';
    }
    if (preg_match('#^(https?:)?//#i', $url) === 1 || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        return $url;
    }

    return $url;
}

function publicNavIsActive(string $url, string $curPageName): bool
{
    $url = trim($url);
    if ($url === '' || $url === '#') {
        return false;
    }
    if (preg_match('#^(https?:)?//#i', $url) === 1 || str_starts_with($url, '/')) {
        return false;
    }
    $slug = pathinfo(basename(parse_url($url, PHP_URL_PATH) ?: $url), PATHINFO_FILENAME);

    return $slug !== '' && $slug === $curPageName;
}

/**
 * Active public navbar tree: top-level items with optional one-level children.
 *
 * @return list<array{item: array, children: list<array>}>
 */
function publicNavMenuTree(PDO $db): array
{
    if (!tableExists($db, 'nav_menus')) {
        return [];
    }

    $rows = fetchRows(
        $db,
        'SELECT * FROM nav_menus WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    );

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    $tree = [];
    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        if ($parentId === null || $parentId === 0) {
            $tree[(int) $row['id']] = ['item' => $row, 'children' => []];
        }
    }

    foreach ($rows as $row) {
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        if ($parentId !== null && $parentId !== 0 && isset($tree[$parentId])) {
            $tree[$parentId]['children'][] = $row;
        }
    }

    return array_values($tree);
}

function homepageInfoCards(PDO $db): array
{
    if (!tableExists($db, 'homepage_info_cards')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT * FROM homepage_info_cards WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    );
}

function homepageLinks(PDO $db, string $group): array
{
    if (!tableExists($db, 'homepage_links')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT * FROM homepage_links WHERE link_group = :g AND is_active = 1 ORDER BY sort_order ASC, id ASC',
        ['g' => $group]
    );
}

function sscExamResults(PDO $db): array
{
    if (!tableExists($db, 'ssc_exam_results')) {
        return [];
    }

    return fetchRows($db, 'SELECT * FROM ssc_exam_results ORDER BY exam_year DESC');
}

function homepageCardImageSrc(string $imagePath): string
{
    $imagePath = trim($imagePath);
    if ($imagePath === '') {
        return 'image/1.jpg';
    }
    if (preg_match('#^(https?:)?//#i', $imagePath) === 1) {
        return $imagePath;
    }

    return ltrim($imagePath, '/');
}

function schoolMediaUrl(string $stored, string $directory, string $fallback = 'image/1.jpg'): string
{
    $stored = trim(str_replace('\\', '/', $stored));
    if ($stored === '') {
        return $fallback;
    }
    if (preg_match('#^(https?:)?//#i', $stored) === 1) {
        return $stored;
    }
    $directory = trim($directory, '/');
    $stored = ltrim($stored, '/');
    if ($directory !== '' && (str_starts_with($stored, $directory . '/') || str_starts_with($stored, 'image/'))) {
        return $stored;
    }
    if ($directory === '') {
        return $stored;
    }

    return $directory . '/' . $stored;
}

function schoolLeaderMessageRoles(): array
{
    return [
        'president' => [
            'default_heading' => 'সভাপতির বাণী',
            'designation_title' => 'title_president',
            'fallback_designation' => 'সভাপতি',
            'sort_order' => 10,
            'default_message' => 'শিক্ষাই জাতির মেরুদণ্ড। ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের শিক্ষার্থীরা যেন জ্ঞান, নৈতিকতা ও সুনাগরিকত্বে সমৃদ্ধ হয়ে দেশ ও সমাজের কল্যাণে নিজেদের নিয়োজিত করতে পারে—এটাই আমাদের প্রত্যাশা। অভিভাবক, শিক্ষক ও পরিচালনা কমিটির সম্মিলিত প্রচেষ্টায় বিদ্যালয়টি তার গৌরবোজ্জ্বল ঐতিহ্য ধরে রাখবে বলে আমি আশাবাদী।',
        ],
        'head_teacher' => [
            'default_heading' => 'প্রধান শিক্ষকের বাণী',
            'designation_title' => 'title_head_teacher',
            'fallback_designation' => 'প্রধান শিক্ষক',
            'sort_order' => 20,
            'default_message' => 'প্রিয় শিক্ষার্থী, অভিভাবক ও শুভানুধ্যায়ীবৃন্দ, ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ে আপনাদের স্বাগতম। আমরা শিক্ষার্থীদের মেধা, চরিত্র ও নেতৃত্ব বিকাশে নিরলসভাবে কাজ করে যাচ্ছি। শৃঙ্খলা, পরিশ্রম ও নৈতিক মূল্যবোধের সমন্বয়ে একটি আদর্শ শিক্ষাঙ্গন গড়ে তোলাই আমাদের অঙ্গীকার।',
        ],
    ];
}

function schoolLeaderPortrait(PDO $db, string $role): array
{
    $empty = ['name' => '', 'photo' => '', 'teacher_id' => 0];
    if ($role === 'president' && tableExists($db, 'comitee')) {
        $member = committeeMemberBySlug($db, 'president');
        if ($member !== null) {
            return [
                'name' => (string) ($member['name'] ?? ''),
                'photo' => (string) ($member['photo'] ?? ''),
                'teacher_id' => (int) ($member['teacher_id'] ?? 0),
            ];
        }
        $rows = fetchRows(
            $db,
            "SELECT comitee_name, comitee_picture
             FROM comitee
             WHERE comitee_designation = '1'
             ORDER BY id ASC
             LIMIT 1"
        );
        if ($rows !== []) {
            return [
                'name' => trim((string) ($rows[0]['comitee_name'] ?? '')),
                'photo' => schoolMediaUrl((string) ($rows[0]['comitee_picture'] ?? ''), 'comitee_picture', ''),
                'teacher_id' => 0,
            ];
        }
    }
    if ($role === 'head_teacher' && tableExists($db, 'teacher_info')) {
        $rows = fetchRows(
            $db,
            "SELECT id, teacher_name, teacher_pic
             FROM teacher_info
             WHERE teacher_designation = 'head_teacher'
             ORDER BY id ASC
             LIMIT 1"
        );
        if ($rows !== []) {
            return [
                'name' => trim((string) ($rows[0]['teacher_name'] ?? '')),
                'photo' => schoolMediaUrl((string) ($rows[0]['teacher_pic'] ?? ''), 'teacher_pic', ''),
                'teacher_id' => (int) ($rows[0]['id'] ?? 0),
            ];
        }
    }

    return $empty;
}

function schoolLeaderMessageByRole(PDO $db, string $role): array
{
    $meta = schoolLeaderMessageRoles()[$role] ?? null;
    if ($meta === null) {
        return [];
    }
    $rows = tableExists($db, 'school_messages')
        ? fetchRows($db, 'SELECT * FROM school_messages WHERE role = :role LIMIT 1', ['role' => $role])
        : [];
    $row = $rows[0] ?? [
        'id' => 0,
        'role' => $role,
        'heading' => $meta['default_heading'],
        'person_name' => '',
        'photo_path' => '',
        'message' => '',
        'is_active' => 1,
        'sort_order' => $meta['sort_order'],
    ];
    $portrait = schoolLeaderPortrait($db, $role);
    $customPhoto = trim((string) ($row['photo_path'] ?? ''));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'role' => $role,
        'heading' => trim((string) ($row['heading'] ?? '')) !== '' ? trim((string) $row['heading']) : $meta['default_heading'],
        'person_name' => trim((string) ($row['person_name'] ?? '')),
        'photo_path' => $customPhoto,
        'message' => (string) ($row['message'] ?? ''),
        'is_active' => (int) ($row['is_active'] ?? 1) === 1,
        'sort_order' => (int) ($row['sort_order'] ?? $meta['sort_order']),
        'auto_name' => $portrait['name'],
        'auto_photo' => $portrait['photo'],
        'teacher_id' => $portrait['teacher_id'],
        'display_name' => trim((string) ($row['person_name'] ?? '')) !== '' ? trim((string) $row['person_name']) : $portrait['name'],
        'display_photo' => $customPhoto !== ''
            ? schoolMediaUrl($customPhoto, 'messages_pic', '')
            : $portrait['photo'],
    ];
}

function homepageLeaderMessages(PDO $db): array
{
    $settings = schoolSettings($db);
    $items = [];
    foreach (array_keys(schoolLeaderMessageRoles()) as $role) {
        $item = schoolLeaderMessageByRole($db, $role);
        if ($item === [] || !$item['is_active'] || trim((string) $item['message']) === '') {
            continue;
        }
        $meta = schoolLeaderMessageRoles()[$role];
        $designation = trim((string) ($settings[$meta['designation_title']] ?? ''));
        $item['designation'] = $designation !== '' ? $designation : $meta['fallback_designation'];
        $items[] = $item;
    }

    return $items;
}

function committeeRoles(PDO $db, bool $activeOnly = false): array
{
    if (!tableExists($db, 'comitee_roles')) {
        return [];
    }
    $sql = 'SELECT * FROM comitee_roles';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    return fetchRows($db, $sql);
}

function committeeRoleById(PDO $db, int $id): ?array
{
    if ($id <= 0 || !tableExists($db, 'comitee_roles')) {
        return null;
    }
    $rows = fetchRows($db, 'SELECT * FROM comitee_roles WHERE id = :id LIMIT 1', ['id' => $id]);

    return $rows[0] ?? null;
}

function teacherListOrderSql(PDO $db): string
{
    if (columnExists($db, 'teacher_info', 'sort_order')) {
        return 'sort_order ASC, id ASC';
    }

    return 'teacher_designation_serial IS NULL, teacher_designation_serial ASC, teacher_name ASC, id ASC';
}

function nextTeacherSortOrder(PDO $db): int
{
    if (!columnExists($db, 'teacher_info', 'sort_order')) {
        return 0;
    }

    return (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM teacher_info')->fetchColumn();
}

function saveTeacherListOrder(PDO $db, array $orderedIds): void
{
    if (!columnExists($db, 'teacher_info', 'sort_order')) {
        throw new RuntimeException('Teacher order is not available yet.');
    }

    $valid = [];
    foreach (fetchRows($db, 'SELECT id FROM teacher_info') as $row) {
        $valid[(int) $row['id']] = true;
    }

    $seen = [];
    $ordered = [];
    foreach ($orderedIds as $id) {
        $id = (int) $id;
        if ($id < 1 || !isset($valid[$id]) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ordered[] = $id;
    }

    foreach ($valid as $id => $unused) {
        if (!isset($seen[$id])) {
            $ordered[] = $id;
        }
    }

    if ($ordered === []) {
        return;
    }

    $update = $db->prepare('UPDATE teacher_info SET sort_order = :sort WHERE id = :id');
    foreach ($ordered as $index => $id) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => $id,
        ]);
    }
}

function moveTeacherInList(PDO $db, int $id, string $direction): bool
{
    $rows = fetchRows($db, 'SELECT id FROM teacher_info ORDER BY ' . teacherListOrderSql($db));
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $index = array_search($id, $ids, true);
    if ($index === false) {
        throw new RuntimeException('Teacher not found.');
    }

    $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
    if ($target === null || !isset($ids[$target])) {
        return false;
    }

    $swap = $ids[$index];
    $ids[$index] = $ids[$target];
    $ids[$target] = $swap;
    saveTeacherListOrder($db, $ids);

    return true;
}

function downloadListOrderSql(PDO $db): string
{
    if (columnExists($db, 'files', 'sort_order')) {
        return 'sort_order ASC, id ASC';
    }

    return 'files_creation_time DESC, id DESC';
}

function nextDownloadSortOrder(PDO $db): int
{
    if (!columnExists($db, 'files', 'sort_order')) {
        return 0;
    }

    // Newer uploads sort first (lower sort_order).
    return (int) $db->query('SELECT COALESCE(MIN(sort_order), 10) - 10 FROM files')->fetchColumn();
}

function saveDownloadListOrder(PDO $db, array $orderedIds): void
{
    if (!columnExists($db, 'files', 'sort_order')) {
        throw new RuntimeException('Download order is not available yet.');
    }

    $valid = [];
    foreach (fetchRows($db, 'SELECT id FROM files') as $row) {
        $valid[(int) $row['id']] = true;
    }

    $seen = [];
    $ordered = [];
    foreach ($orderedIds as $id) {
        $id = (int) $id;
        if ($id < 1 || !isset($valid[$id]) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ordered[] = $id;
    }

    foreach ($valid as $id => $unused) {
        if (!isset($seen[$id])) {
            $ordered[] = $id;
        }
    }

    if ($ordered === []) {
        return;
    }

    $update = $db->prepare('UPDATE files SET sort_order = :sort WHERE id = :id');
    foreach ($ordered as $index => $id) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => $id,
        ]);
    }
}

function moveDownloadInList(PDO $db, int $id, string $direction): bool
{
    $rows = fetchRows($db, 'SELECT id FROM files ORDER BY ' . downloadListOrderSql($db));
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $index = array_search($id, $ids, true);
    if ($index === false) {
        throw new RuntimeException('Download not found.');
    }

    $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
    if ($target === null || !isset($ids[$target])) {
        return false;
    }

    $swap = $ids[$index];
    $ids[$index] = $ids[$target];
    $ids[$target] = $swap;
    saveDownloadListOrder($db, $ids);

    return true;
}

function eventListOrderSql(PDO $db): string
{
    if (columnExists($db, 'events', 'sort_order')) {
        return 'sort_order ASC, id ASC';
    }

    return 'events_creation_time DESC, id DESC';
}

function nextEventSortOrder(PDO $db): int
{
    if (!columnExists($db, 'events', 'sort_order')) {
        return 0;
    }

    return (int) $db->query('SELECT COALESCE(MIN(sort_order), 10) - 10 FROM events')->fetchColumn();
}

function saveEventListOrder(PDO $db, array $orderedIds): void
{
    if (!columnExists($db, 'events', 'sort_order')) {
        throw new RuntimeException('Event order is not available yet.');
    }

    $valid = [];
    foreach (fetchRows($db, 'SELECT id FROM events') as $row) {
        $valid[(int) $row['id']] = true;
    }

    $seen = [];
    $ordered = [];
    foreach ($orderedIds as $id) {
        $id = (int) $id;
        if ($id < 1 || !isset($valid[$id]) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ordered[] = $id;
    }

    foreach ($valid as $id => $unused) {
        if (!isset($seen[$id])) {
            $ordered[] = $id;
        }
    }

    if ($ordered === []) {
        return;
    }

    $update = $db->prepare('UPDATE events SET sort_order = :sort WHERE id = :id');
    foreach ($ordered as $index => $id) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => $id,
        ]);
    }
}

function moveEventInList(PDO $db, int $id, string $direction): bool
{
    $rows = fetchRows($db, 'SELECT id FROM events ORDER BY ' . eventListOrderSql($db));
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $index = array_search($id, $ids, true);
    if ($index === false) {
        throw new RuntimeException('Event not found.');
    }

    $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
    if ($target === null || !isset($ids[$target])) {
        return false;
    }

    $swap = $ids[$index];
    $ids[$index] = $ids[$target];
    $ids[$target] = $swap;
    saveEventListOrder($db, $ids);

    return true;
}

function carouselListOrderSql(PDO $db): string
{
    if (columnExists($db, 'carousel', 'sort_order')) {
        return 'sort_order ASC, id ASC';
    }

    return 'carousel_creation_time DESC, id DESC';
}

function homepageCarouselSlideLimit(): int
{
    return 5;
}

function publicCarouselSlides(PDO $db): array
{
    if (!tableExists($db, 'carousel')) {
        return [];
    }

    $limit = homepageCarouselSlideLimit();

    return fetchRows(
        $db,
        'SELECT carousel_file, carousel_text FROM carousel WHERE carousel_status = 1 ORDER BY '
        . carouselListOrderSql($db)
        . ' LIMIT ' . $limit
    );
}

function nextCarouselSortOrder(PDO $db): int
{
    if (!columnExists($db, 'carousel', 'sort_order')) {
        return 0;
    }

    return (int) $db->query('SELECT COALESCE(MIN(sort_order), 10) - 10 FROM carousel')->fetchColumn();
}

function saveCarouselListOrder(PDO $db, array $orderedIds): void
{
    if (!columnExists($db, 'carousel', 'sort_order')) {
        throw new RuntimeException('Slider order is not available yet.');
    }

    $valid = [];
    foreach (fetchRows($db, 'SELECT id FROM carousel') as $row) {
        $valid[(int) $row['id']] = true;
    }

    $seen = [];
    $ordered = [];
    foreach ($orderedIds as $id) {
        $id = (int) $id;
        if ($id < 1 || !isset($valid[$id]) || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $ordered[] = $id;
    }

    foreach ($valid as $id => $unused) {
        if (!isset($seen[$id])) {
            $ordered[] = $id;
        }
    }

    if ($ordered === []) {
        return;
    }

    $update = $db->prepare('UPDATE carousel SET sort_order = :sort WHERE id = :id');
    foreach ($ordered as $index => $id) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => $id,
        ]);
    }
}

function moveCarouselInList(PDO $db, int $id, string $direction): bool
{
    $rows = fetchRows($db, 'SELECT id FROM carousel ORDER BY ' . carouselListOrderSql($db));
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $index = array_search($id, $ids, true);
    if ($index === false) {
        throw new RuntimeException('Slider image not found.');
    }

    $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : null);
    if ($target === null || !isset($ids[$target])) {
        return false;
    }

    $swap = $ids[$index];
    $ids[$index] = $ids[$target];
    $ids[$target] = $swap;
    saveCarouselListOrder($db, $ids);

    return true;
}

function adminDateTimeLabel(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }
    $stamp = strtotime($value);
    if ($stamp === false) {
        return '';
    }

    return 'at ' . date('g:i A', $stamp) . ' in ' . date('d-m-Y', $stamp);
}

function committeeTeachers(PDO $db): array
{
    if (!tableExists($db, 'teacher_info')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT id, teacher_name, teacher_mobile, teacher_pic, teacher_designation, teacher_status
         FROM teacher_info
         ORDER BY ' . teacherListOrderSql($db)
    );
}

function committeeHydrateTeacherMember(array $teacher, array $role, int $memberId, bool $isAuto, bool $isActive = true): array
{
    return [
        'member_id' => $memberId,
        'is_auto' => $isAuto,
        'is_active' => $isActive,
        'role_id' => (int) ($role['id'] ?? 0),
        'role_label' => (string) ($role['label_bn'] ?? ''),
        'role_slug' => (string) ($role['slug'] ?? ''),
        'sort_order' => (int) ($role['sort_order'] ?? 0),
        'name' => trim((string) ($teacher['teacher_name'] ?? '')),
        'mobile' => trim((string) ($teacher['teacher_mobile'] ?? '')),
        'photo' => schoolMediaUrl((string) ($teacher['teacher_pic'] ?? ''), 'teacher_pic', ''),
        'teacher_id' => (int) ($teacher['id'] ?? 0),
        'teacher_designation' => (string) ($teacher['teacher_designation'] ?? ''),
        'member_source' => 'teacher',
    ];
}

function committeeHydrateManualMember(array $row, array $role): array
{
    return [
        'member_id' => (int) ($row['id'] ?? 0),
        'is_auto' => false,
        'is_active' => (int) ($row['comitee_status'] ?? 1) === 1,
        'role_id' => (int) ($role['id'] ?? 0),
        'role_label' => (string) ($role['label_bn'] ?? ''),
        'role_slug' => (string) ($role['slug'] ?? ''),
        'sort_order' => (int) ($role['sort_order'] ?? 0),
        'name' => trim((string) ($row['comitee_name'] ?? '')),
        'mobile' => trim((string) ($row['comitee_mobile'] ?? '')),
        'photo' => schoolMediaUrl((string) ($row['comitee_picture'] ?? ''), 'comitee_picture', ''),
        'teacher_id' => (int) ($row['teacher_id'] ?? 0),
        'teacher_designation' => '',
        'member_source' => 'manual',
    ];
}

function publicCommitteeMembers(PDO $db, bool $activeOnly = true): array
{
    $roles = committeeRoles($db, $activeOnly);
    if ($roles === []) {
        return [];
    }

    $stored = tableExists($db, 'comitee')
        ? fetchRows($db, 'SELECT * FROM comitee ORDER BY id ASC')
        : [];
    $teachers = [];
    $byDesignation = [];
    foreach (committeeTeachers($db) as $teacher) {
        $teachers[(int) $teacher['id']] = $teacher;
        if ((int) ($teacher['teacher_status'] ?? 0) === 1) {
            $byDesignation[(string) $teacher['teacher_designation']][] = $teacher;
        }
    }

    $usedTeacherIds = [];
    $out = [];
    foreach ($roles as $role) {
        $roleId = (string) $role['id'];
        $roleMembers = array_values(array_filter(
            $stored,
            static fn (array $row): bool => (string) ($row['comitee_designation'] ?? '') === $roleId
        ));
        if ($activeOnly) {
            $roleMembers = array_values(array_filter(
                $roleMembers,
                static fn (array $row): bool => (int) ($row['comitee_status'] ?? 1) === 1
            ));
        }

        $source = (string) ($role['member_source'] ?? 'manual');
        $autoDesignation = trim((string) ($role['auto_teacher_designation'] ?? ''));
        if ($source === 'teacher') {
            if ($autoDesignation !== '') {
                $autos = $byDesignation[$autoDesignation] ?? [];
                if ((int) ($role['allow_multiple'] ?? 1) !== 1) {
                    $autos = array_slice($autos, 0, 1);
                }
                foreach ($autos as $teacher) {
                    $teacherId = (int) $teacher['id'];
                    if (isset($usedTeacherIds[$teacherId])) {
                        continue;
                    }
                    $usedTeacherIds[$teacherId] = true;
                    $out[] = committeeHydrateTeacherMember($teacher, $role, 0, true);
                }
            }
            foreach ($roleMembers as $row) {
                $teacherId = (int) ($row['teacher_id'] ?? 0);
                if ($teacherId > 0 && isset($teachers[$teacherId])) {
                    if (isset($usedTeacherIds[$teacherId])) {
                        continue;
                    }
                    $teacher = $teachers[$teacherId];
                    if ($activeOnly && (int) ($teacher['teacher_status'] ?? 0) !== 1) {
                        continue;
                    }
                    $usedTeacherIds[$teacherId] = true;
                    $out[] = committeeHydrateTeacherMember(
                        $teacher,
                        $role,
                        (int) $row['id'],
                        false,
                        (int) ($row['comitee_status'] ?? 1) === 1
                    );
                    continue;
                }
                if ($autoDesignation !== '') {
                    continue;
                }
                $out[] = committeeHydrateManualMember($row, $role);
            }
            continue;
        }

        foreach ($roleMembers as $row) {
            $out[] = committeeHydrateManualMember($row, $role);
        }
    }

    return $out;
}

function retirementTeachers(PDO $db, bool $activeOnly = true): array
{
    if (!tableExists($db, 'retirement_teacher')) {
        return [];
    }
    $sql = 'SELECT * FROM retirement_teacher';
    if ($activeOnly) {
        $sql .= ' WHERE retirement_teacher_status = 1';
    }
    $sql .= ' ORDER BY CAST(retirement_year AS UNSIGNED) DESC, id DESC';

    return fetchRows($db, $sql);
}

function retirementTeacherById(PDO $db, int $id): ?array
{
    if ($id <= 0 || !tableExists($db, 'retirement_teacher')) {
        return null;
    }
    $rows = fetchRows($db, 'SELECT * FROM retirement_teacher WHERE id = :id LIMIT 1', ['id' => $id]);

    return $rows[0] ?? null;
}

function retirementYearRange(int $joiningYear, int $retirementYear): string
{
    return bnDigits((string) $joiningYear) . '–' . bnDigits((string) $retirementYear);
}

function retirementDummyPhoto(): string
{
    return 'image/retired_dummy.svg';
}

function retirementTeacherPhotoUrl(?string $stored): string
{
    $dummy = retirementDummyPhoto();
    $basename = publicMediaBasename($stored);
    if ($basename === '') {
        return $dummy;
    }

    $relative = schoolMediaUrl($basename, 'retirement_teacher_file', '');
    if ($relative === '') {
        return $dummy;
    }

    $fullPath = publicSitePath($relative);
    if (!is_file($fullPath)) {
        return $dummy;
    }

    return $relative;
}

function committeeMemberBySlug(PDO $db, string $slug): ?array
{
    foreach (publicCommitteeMembers($db, true) as $member) {
        if ((string) ($member['role_slug'] ?? '') === $slug) {
            return $member;
        }
    }

    return null;
}

function schoolSnippet(string $text, int $width, string $ellipsis = '…'): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, $ellipsis);
    }
    if (strlen($text) <= $width) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $width - strlen($ellipsis)))) . $ellipsis;
}

function schoolFileUrl(string $stored, string $directory = '', string $fallback = ''): string
{
    $stored = trim(str_replace('\\', '/', $stored));
    if ($stored === '') {
        return $fallback;
    }
    $relative = schoolMediaUrl($stored, $directory, $fallback);
    if ($relative === '' || preg_match('#^(https?:)?//#i', $relative) === 1) {
        return $relative;
    }

    return publicSiteUrl($relative);
}

function requestIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function currentAbsoluteUrl(): string
{
    $scheme = requestIsHttps() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    return $scheme . '://' . $host . $uri;
}

function facebookSharerUrl(string $url): string
{
    return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url);
}

function eventPublicHref(int $id): string
{
    return signedRecordUrl('events_single', $id, 'event');
}

function eventIdFromPublicParam(?string $raw): int
{
    $id = function_exists('requestRecordId') ? requestRecordId('event') : 0;
    if ($id > 0) {
        return $id;
    }

    return function_exists('legacyOpaqueId') ? legacyOpaqueId($raw) : 0;
}

function schoolBengaliDate(?string $datetime): array
{
    $stamp = strtotime((string) $datetime);
    if ($stamp === false) {
        $stamp = time();
    }
    $months = [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
        5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];
    $ampm = date('a', $stamp) === 'am' ? 'পূর্বাহ্ন' : 'অপরাহ্ন';

    return [
        'stamp' => $stamp,
        'day' => bnDigits(date('d', $stamp)),
        'month' => $months[(int) date('n', $stamp)] ?? date('M', $stamp),
        'year' => bnDigits(date('Y', $stamp)),
        'time' => bnDigits(date('g:i', $stamp)) . ' ' . $ampm,
        'iso' => date('c', $stamp),
        'ymd' => date('Y-m-d', $stamp),
    ];
}

function eventPlainText(string $details): string
{
    $text = preg_replace('/\[img:\d+\]/i', ' ', $details) ?? $details;
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    return $text;
}

function eventImages(PDO $db, int $eventId): array
{
    if ($eventId < 1 || !tableExists($db, 'event_images')) {
        return [];
    }

    return fetchRows(
        $db,
        'SELECT * FROM event_images WHERE event_id = :id ORDER BY sort_order ASC, id ASC',
        ['id' => $eventId]
    );
}

function reindexEventImages(PDO $db, int $eventId): void
{
    if ($eventId < 1 || !tableExists($db, 'event_images')) {
        return;
    }
    $update = $db->prepare('UPDATE event_images SET sort_order = :sort WHERE id = :id');
    foreach (eventImages($db, $eventId) as $index => $image) {
        $update->execute([
            'sort' => $index + 1,
            'id' => (int) $image['id'],
        ]);
    }
}

function saveEventGalleryUploads(PDO $db, int $eventId, int $maxImages = 8, int $maxBytes = 2097152): int
{
    if ($eventId < 1) {
        return 0;
    }
    if (function_exists('ensureEventImagesSchema')) {
        ensureEventImagesSchema($db);
    }

    $entries = uploadedFileEntries('event_gallery');
    $incoming = 0;
    foreach ($entries as $entry) {
        if ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && trim((string) ($entry['name'] ?? '')) !== '') {
            $incoming++;
        }
    }
    if ($incoming === 0) {
        return 0;
    }

    $existing = eventImages($db, $eventId);
    $remaining = $maxImages - count($existing);
    if ($remaining <= 0) {
        throw new RuntimeException('Maximum ' . $maxImages . ' extra photos per event.');
    }

    $saved = 0;
    $sort = $existing === [] ? 0 : (int) max(array_map(static fn (array $row): int => (int) ($row['sort_order'] ?? 0), $existing));
    $insert = $db->prepare(
        'INSERT INTO event_images (event_id, image_file, caption, sort_order) VALUES (:event_id, :image_file, NULL, :sort_order)'
    );
    foreach ($entries as $entry) {
        if ($saved >= $remaining) {
            break;
        }
        $filename = storeUploadedFileFromArray(
            $entry,
            'events_file',
            ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            $maxBytes,
            true
        );
        if ($filename === null) {
            continue;
        }
        $sort++;
        $insert->execute([
            'event_id' => $eventId,
            'image_file' => $filename,
            'sort_order' => $sort,
        ]);
        $saved++;
    }

    return $saved;
}

function deleteEventImage(PDO $db, int $imageId, int $eventId): bool
{
    if ($imageId < 1 || $eventId < 1 || !tableExists($db, 'event_images')) {
        return false;
    }
    $rows = fetchRows(
        $db,
        'SELECT * FROM event_images WHERE id = :id AND event_id = :event_id LIMIT 1',
        ['id' => $imageId, 'event_id' => $eventId]
    );
    if ($rows === []) {
        return false;
    }
    deletePublicMediaFile('events_file', (string) ($rows[0]['image_file'] ?? ''));
    $db->prepare('DELETE FROM event_images WHERE id = :id')->execute(['id' => $imageId]);
    reindexEventImages($db, $eventId);

    return true;
}

function deleteEventRecord(PDO $db, int $id): bool
{
    if ($id < 1) {
        return false;
    }
    foreach (eventImages($db, $id) as $image) {
        deletePublicMediaFile('events_file', (string) ($image['image_file'] ?? ''));
    }
    if (tableExists($db, 'event_images')) {
        $db->prepare('DELETE FROM event_images WHERE event_id = :id')->execute(['id' => $id]);
    }

    return deleteDatabaseRowWithMedia($db, 'events', $id, ['events_file' => 'events_file']);
}

function eventImageFigureHtml(array $image, int $number, string $gallery = 'gallery'): string
{
    $src = h(schoolMediaUrl((string) ($image['image_file'] ?? ''), 'events_file'));
    $caption = trim((string) ($image['caption'] ?? ''));
    $alt = $caption !== '' ? h($caption) : h('ইভেন্টের ছবি ' . bnDigits((string) $number));
    $html = '<figure class="school-news-figure">';
    $html .= '<a href="' . $src . '" data-fancybox="' . h($gallery) . '">';
    $html .= '<img src="' . $src . '" alt="' . $alt . '" loading="lazy" decoding="async">';
    $html .= '</a>';
    if ($caption !== '') {
        $html .= '<figcaption>' . h($caption) . '</figcaption>';
    }
    $html .= '</figure>';

    return $html;
}

/**
 * @param list<array<string, mixed>> $images
 * @return array{html:string,unused:list<array{image:array<string,mixed>,number:int}>}
 */
function renderEventDetailsHtml(string $details, array $images): array
{
    $used = [];
    $parts = preg_split('/(\[img:\d+\])/i', $details, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        $parts = [$details];
    }

    $html = '';
    foreach ($parts as $part) {
        if (preg_match('/^\[img:(\d+)\]$/i', $part, $match) === 1) {
            $number = (int) $match[1];
            $index = $number - 1;
            if (!isset($images[$index])) {
                continue;
            }
            $used[$index] = true;
            $html .= eventImageFigureHtml($images[$index], $number);
            continue;
        }
        $text = trim($part);
        if ($text === '') {
            continue;
        }
        $paragraphs = preg_split("/\R{2,}/u", $text) ?: [$text];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= '<p>' . nl2br(h($paragraph)) . '</p>';
        }
    }

    $unused = [];
    foreach ($images as $index => $image) {
        if (empty($used[$index])) {
            $unused[] = ['image' => $image, 'number' => $index + 1];
        }
    }

    return ['html' => $html, 'unused' => $unused];
}

function seedHomepageCms(PDO $db): void
{
    if (tableExists($db, 'homepage_info_cards')
        && (int) $db->query('SELECT COUNT(*) FROM homepage_info_cards')->fetchColumn() === 0
    ) {
        $cards = [
            ['managing_comitee', 'ম্যানেজিং কমিটি', 'image/comitee.png', 10],
            ['teacher_staff', 'শিক্ষক ও কর্মচারীবৃন্দ', 'image/teacher.png', 20],
            ['retirement_teacher', 'প্রাক্তন শিক্ষকবৃন্দ', 'image/former_teachers.png', 30],
            ['meritorious_students', 'কৃতি শিক্ষার্থী', 'image/former_students.png', 40],
            ['students', 'অধ্যয়নরত ছাত্র/ছাত্রী', 'image/students.png', 50],
            ['image_gallery', 'ছবির গ্যালারী', 'image/image_gallery.gif', 60],
            ['notice', 'নোটিশ', 'image/notice.png', 70],
            ['stipend_scholarship_students_list', 'স্কলারশিপ', 'image/scholarship.png', 80],
            ['online_admission', 'ভর্তি', 'image/admission.gif', 90],
            ['academic_calender', 'একাডেমিক ক্যালেন্ডার', 'image/calendar.png', 100],
            ['contact', 'যোগাযোগ', 'image/contacts.gif', 110],
            ['download_preview', 'ডাউনলোড', 'image/download.gif', 120],
        ];
        $insert = $db->prepare(
            'INSERT INTO homepage_info_cards (url, title, image_path, sort_order, is_active)
             VALUES (:url, :title, :image_path, :sort_order, 1)'
        );
        foreach ($cards as [$url, $title, $image, $sort]) {
            $insert->execute([
                'url' => $url,
                'title' => $title,
                'image_path' => $image,
                'sort_order' => $sort,
            ]);
        }
    }

    if (tableExists($db, 'homepage_links')
        && (int) $db->query('SELECT COUNT(*) FROM homepage_links')->fetchColumn() === 0
    ) {
        $links = [
            ['eservices', 'ভর্তির জন্য আবেদন', 'online_admission', '_self', 10],
            ['eservices', 'উপবৃত্তির জন্য আবেদন', 'http://hsp.pmeat.gov.bd/HSP-MIS/login', '_blank', 20],
            ['eservices', 'অনলাইন ক্লাস', '#', '_blank', 30],
            ['important', 'কুমিল্লা বোর্ড', 'https://web.comillaboard.gov.bd', '_blank', 10],
            ['important', 'মাধ্যমিক ও উচ্চশিক্ষা অধিদপ্তর', 'http://www.dshe.gov.bd', '_blank', 20],
            ['important', 'শিক্ষা মন্ত্রণালয়', 'https://shed.portal.gov.bd', '_blank', 30],
            ['important', 'লক্ষ্মীপুর জেলা শিক্ষা অফিস', 'http://deo.lakshmipur.gov.bd', '_blank', 40],
            ['important', 'লক্ষ্মীপুর উপজেলা মাধ্যমিক শিক্ষা অফিস', 'https://www.facebook.com/useodebidwar.comilla', '_blank', 50],
            ['important', 'বই ডাউনলোড', 'http://nctb.portal.gov.bd/site/page/221b05b6-76d6-4e79-9749-49a21823b1d8', '_blank', 60],
            ['important', 'ইএমআইএস', 'http://emis.gov.bd/emis', '_blank', 70],
            ['important', 'ব্যানবেইস জরিপ', '#', '_self', 80],
            ['important', 'শিক্ষক বাতায়ন', 'https://www.teachers.gov.bd', '_blank', 90],
            ['important', 'মুক্ত পাঠ', 'https://muktopaath.gov.bd', '_blank', 100],
            ['important', 'বোর্ডের ফলাফল', 'https://eboardresults.com/v2/', '_blank', 110],
            ['footer', 'অনলাইন ভর্তি', 'online_admission', '_self', 10],
            ['footer', 'নোটিশ', 'notice', '_self', 20],
            ['footer', 'যোগাযোগ', 'contact', '_self', 30],
        ];
        $insert = $db->prepare(
            'INSERT INTO homepage_links (link_group, label, url, target, sort_order, is_active)
             VALUES (:link_group, :label, :url, :target, :sort_order, 1)'
        );
        foreach ($links as [$group, $label, $url, $target, $sort]) {
            $insert->execute([
                'link_group' => $group,
                'label' => $label,
                'url' => $url,
                'target' => $target,
                'sort_order' => $sort,
            ]);
        }
    }

    if (tableExists($db, 'ssc_exam_results')
        && (int) $db->query('SELECT COUNT(*) FROM ssc_exam_results')->fetchColumn() === 0
    ) {
        $years = [2023, 2022, 2021, 2020, 2019, 2018, 2017, 2016, 2015, 2014, 2013, 2012];
        $gpa5 = [5, 12, 7, 6, 3, 4, 3, 0, 1, 12, 2, 4];
        $gpa4 = [41, 54, 50, 30, 22, 27, 23, 24, 27, 60, 49, 67];
        $gpa3 = [67, 88, 106, 44, 49, 98, 73, 66, 38, 75, 64, 88];
        $gpa2 = [48, 51, 96, 49, 47, 46, 67, 83, 0, 36, 26, 30];
        $gpa1 = [4, 2, 6, 0, 0, 1, 3, 4, 0, 0, 0, 1];
        $fail = [23, 26, 16, 52, 77, 47, 30, 104, 109, 15, 25, 20];
        $insert = $db->prepare(
            'INSERT INTO ssc_exam_results (exam_year, gpa5, gpa4, gpa3, gpa2, gpa1, fail_count)
             VALUES (:exam_year, :gpa5, :gpa4, :gpa3, :gpa2, :gpa1, :fail_count)'
        );
        foreach ($years as $i => $year) {
            $insert->execute([
                'exam_year' => $year,
                'gpa5' => $gpa5[$i],
                'gpa4' => $gpa4[$i],
                'gpa3' => $gpa3[$i],
                'gpa2' => $gpa2[$i],
                'gpa1' => $gpa1[$i],
                'fail_count' => $fail[$i],
            ]);
        }
    }

    if (!tableExists($db, 'school_settings')) {
        return;
    }

    $defaults = [
        'facebook' => 'https://www.facebook.com/BMLHSOfficial',
        'nav_cta_label' => 'ভর্তি আবেদন',
        'nav_cta_url' => 'online_admission',
        'footer_credit' => 'ওয়েব ডেভেলপার: রুবেল আমির · ০১৯২৫-৫২১২৫৭',
        'title_messages' => 'সভাপতি ও প্রধান শিক্ষকের বাণী',
        'title_history' => 'বিদ্যালয়ের সংক্ষিপ্ত ইতিহাস',
        'title_info' => 'প্রাতিষ্ঠানিক তথ্য',
        'title_events' => 'সাম্প্রতিক ইভেন্টস',
        'title_ssc' => 'বিগত বছরগুলোর এস.এস.সি পরীক্ষার ফলাফল',
        'title_teachers' => 'বিদ্যালয়ের সম্মানিত শিক্ষক ও কর্মচারীবৃন্দ',
        'title_president' => 'সভাপতি',
        'title_head_teacher' => 'প্রধান শিক্ষক',
        'title_asst_head' => 'সহকারী প্রধান শিক্ষক',
        'title_eservices' => 'আভ্যন্তরীণ ই-সেবাসমূহ',
        'title_important_links' => 'গুরুত্বপূর্ণ লিংক',
    ];
    foreach ($defaults as $column => $value) {
        if (!columnExists($db, 'school_settings', $column)) {
            continue;
        }
        $db->prepare(
            "UPDATE school_settings SET `{$column}` = :v WHERE `{$column}` IS NULL OR `{$column}` = ''"
        )->execute(['v' => $value]);
    }
}
