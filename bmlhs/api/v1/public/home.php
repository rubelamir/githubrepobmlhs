<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

try {
    $db = getDatabaseConnection();
    $settings = schoolSettings($db);
    $school = [];
    foreach (['school_name_bn', 'school_name_en', 'short_name_en', 'address', 'address_en', 'eiin', 'mobile', 'email', 'logo', 'about_short'] as $key) {
        $school[$key] = (string) ($settings[$key] ?? '');
    }

    $notices = fetchRows(
        $db,
        'SELECT id, main_notice_text AS title, main_notice_file AS file, main_notice_creation_time AS published_at
         FROM main_notice
         WHERE main_notice_status = 1
         ORDER BY main_notice_creation_time DESC
         LIMIT 20'
    );
    $headlines = fetchRows(
        $db,
        'SELECT id, notice_headlines AS text, notice_headlines_creation_time AS created_at
         FROM notice_headlines
         WHERE notice_headlines_status = 1
         ORDER BY notice_headlines_creation_time DESC, id DESC
         LIMIT 20'
    );
    foreach ($notices as &$notice) {
        if (trim((string) ($notice['file'] ?? '')) !== '') {
            $notice['file_url'] = publicSiteUrl('main_notice_file/' . basename((string) $notice['file']));
        } else {
            $notice['file_url'] = null;
        }
    }
    unset($notice);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public home');
}
mobileApiJson([
    'data' => [
        'school' => $school,
        'notices' => $notices,
        'headlines' => $headlines,
        'generated_at' => gmdate(DATE_ATOM),
    ],
]);
