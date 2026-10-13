<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET.']], 405);
}

$now = new DateTimeImmutable('today', new DateTimeZone('Asia/Dhaka'));
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$year = is_int($year) && $year >= 2000 && $year <= 2100 ? $year : (int) $now->format('Y');
$month = is_int($month) && $month >= 1 && $month <= 12 ? $month : (int) $now->format('n');

try {
    $db = getDatabaseConnection();
    $items = publicCalendarItems($db, $year, $month);
    foreach ($items as &$item) {
        $item['type_label'] = schoolCalendarTypeLabel((string) ($item['event_type'] ?? ''));
    }
    unset($item);

    $events = tableExists($db, 'events')
        ? fetchRows(
            $db,
            'SELECT id, events_headlines AS title, events_details AS details,
                    events_file AS image, events_creation_time AS published_at
             FROM events WHERE events_status = 1
             ORDER BY ' . eventListOrderSql($db) . ' LIMIT 40'
        )
        : [];
    foreach ($events as &$event) {
        $event['details'] = schoolSnippet(eventPlainText((string) ($event['details'] ?? '')), 500);
        $image = trim((string) ($event['image'] ?? ''));
        $event['image_url'] = $image !== '' ? schoolMediaUrl($image, 'events_file') : null;
        unset($event['image']);
    }
    unset($event);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'public calendar');
}

mobileApiJson([
    'data' => [
        'year' => $year,
        'month' => $month,
        'calendar_items' => $items,
        'events' => $events,
        'generated_at' => gmdate(DATE_ATOM),
    ],
]);
