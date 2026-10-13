<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileCalendarDateIsValid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
}

try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    if ($identity['account_type'] !== 'staff' || !canAccessModule('events', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage the academic calendar.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $items = fetchRows(
            $db,
            'SELECT id, title, event_date, end_date, event_type, description,
                    item_status AS status, created_at
             FROM academic_calendar_items
             ORDER BY event_date DESC, id DESC
             LIMIT 200'
        );
        foreach ($items as &$item) {
            $item['status'] = (int) ($item['status'] ?? 0) === 1;
        }
        unset($item);
        mobileApiJson(['data' => ['items' => $items]]);
    }

    $payload = mobileApiReadJson(65536);
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['create', 'update', 'toggle', 'delete'], true)) {
        mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Choose create, update, toggle or delete.']], 422);
    }

    if ($action === 'create' || $action === 'update') {
        $title = $payload['title'] ?? null;
        $eventDate = $payload['event_date'] ?? null;
        $endDate = $payload['end_date'] ?? '';
        $type = $payload['event_type'] ?? 'event';
        $description = $payload['description'] ?? '';
        if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title), 'UTF-8') > 255
            || !is_string($eventDate) || !mobileCalendarDateIsValid($eventDate)
            || !is_string($endDate) || ($endDate !== '' && !mobileCalendarDateIsValid($endDate))
            || !is_string($type) || !in_array($type, ['holiday', 'exam', 'event'], true)
            || !is_string($description) || mb_strlen(trim($description), 'UTF-8') > 500
        ) {
            mobileApiJson(['error' => ['code' => 'invalid_calendar_item', 'message' => 'Provide a title, valid dates, a valid type, and a note no longer than 500 characters.']], 422);
        }
        if ($endDate !== '' && $endDate < $eventDate) {
            mobileApiJson(['error' => ['code' => 'invalid_calendar_item', 'message' => 'End date cannot be before the start date.']], 422);
        }
        $values = [
            'title' => trim($title),
            'event_date' => $eventDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'event_type' => $type,
            'description' => trim($description) !== '' ? trim($description) : null,
        ];

        if ($action === 'create') {
            $db->prepare(
                'INSERT INTO academic_calendar_items
                    (title, event_date, end_date, event_type, description, item_status)
                 VALUES (:title, :event_date, :end_date, :event_type, :description, 1)'
            )->execute($values);
            mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'published']], 201);
        }

        $itemId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($itemId) || $itemId < 1) {
            mobileApiJson(['error' => ['code' => 'invalid_calendar_item', 'message' => 'A valid calendar item ID is required.']], 422);
        }
        $statement = $db->prepare(
            'UPDATE academic_calendar_items
             SET title = :title, event_date = :event_date, end_date = :end_date,
                 event_type = :event_type, description = :description
             WHERE id = :id'
        );
        $statement->execute($values + ['id' => $itemId]);
        if ($statement->rowCount() === 0) {
            $exists = fetchRows($db, 'SELECT id FROM academic_calendar_items WHERE id = :id LIMIT 1', ['id' => $itemId]);
            if ($exists === []) {
                mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Calendar item not found.']], 404);
            }
        }
        mobileApiJson(['data' => ['id' => $itemId, 'status' => 'updated']]);
    }

    $itemId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($itemId) || $itemId < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_calendar_item', 'message' => 'A valid calendar item ID is required.']], 422);
    }
    if ($action === 'toggle') {
        $statement = $db->prepare(
            'UPDATE academic_calendar_items
             SET item_status = IF(item_status = 1, 0, 1)
             WHERE id = :id'
        );
        $statement->execute(['id' => $itemId]);
        if ($statement->rowCount() !== 1) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Calendar item not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $itemId, 'status' => 'toggled']]);
    }

    $statement = $db->prepare('DELETE FROM academic_calendar_items WHERE id = :id');
    $statement->execute(['id' => $itemId]);
    if ($statement->rowCount() !== 1) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Calendar item not found.']], 404);
    }
    mobileApiJson(['data' => ['id' => $itemId, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'admin calendar');
} catch (Throwable $exception) {
    error_log('Mobile API admin calendar failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The calendar request could not be completed.']], 500);
}
