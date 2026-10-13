<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Staff event CMS API.
 *
 * GET returns events and their existing extra-photo galleries.
 * POST accepts JSON or multipart form data with action=create|update|toggle|delete|
 * delete_image|reorder. Cropped cover images use events_file_data (a JPEG data URL,
 * 1280x720, at most 300 KB); optional multipart event_gallery[] files use the
 * existing Admin upload helper (JPEG/PNG/WebP/GIF, 2 MB each, at most 8 total).
 */
function mobileAdminEventsPayload(): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType === 'application/json') {
        return mobileApiReadJson(1500000);
    }
    if ($contentType === 'multipart/form-data' || $contentType === 'application/x-www-form-urlencoded') {
        return $_POST;
    }

    mobileApiJson(['error' => ['code' => 'unsupported_media_type', 'message' => 'Send JSON or multipart form data.']], 415);
}

function mobileAdminEventId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return is_int($id) && $id > 0 ? $id : 0;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
}

$newFiles = [];
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    if ($identity['account_type'] !== 'staff' || !canAccessModule('events', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage events.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $rows = fetchRows($db, 'SELECT * FROM events ORDER BY ' . eventListOrderSql($db) . ' LIMIT 200');
        $events = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $file = publicMediaBasename((string) ($row['events_file'] ?? ''));
            $images = [];
            foreach (eventImages($db, $id) as $image) {
                $imageFile = publicMediaBasename((string) ($image['image_file'] ?? ''));
                $images[] = [
                    'id' => (int) $image['id'],
                    'caption' => $image['caption'] ?? null,
                    'sort_order' => (int) ($image['sort_order'] ?? 0),
                    'file_url' => $imageFile === '' ? null : publicSiteUrl('events_file/' . $imageFile),
                ];
            }
            $events[] = [
                'id' => $id,
                'headline' => (string) $row['events_headlines'],
                'details' => (string) $row['events_details'],
                'image_url' => $file === '' ? null : publicSiteUrl('events_file/' . $file),
                'status' => (int) $row['events_status'] === 1,
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : null,
                'created_at' => $row['events_creation_time'] ?? null,
                'images' => $images,
            ];
        }
        mobileApiJson(['data' => ['events' => $events, 'gallery_upload_available' => tableExists($db, 'event_images')]]);
    }

    $payload = mobileAdminEventsPayload();
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['create', 'update', 'toggle', 'delete', 'delete_image', 'reorder'], true)) {
        mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Choose create, update, toggle, delete, delete_image or reorder.']], 422);
    }

    if ($action === 'create' || $action === 'update') {
        $headline = $payload['headline'] ?? null;
        $details = $payload['details'] ?? null;
        if (!is_string($headline) || trim($headline) === '' || mb_strlen(trim($headline), 'UTF-8') > 255
            || !is_string($details) || trim($details) === '' || mb_strlen($details, 'UTF-8') > 100000
        ) {
            mobileApiJson(['error' => ['code' => 'invalid_event', 'message' => 'Headline (max 255 characters) and details (max 100,000 characters) are required.']], 422);
        }

        $eventId = 0;
        $existing = null;
        if ($action === 'update') {
            $eventId = mobileAdminEventId($payload['id'] ?? null);
            if ($eventId < 1) {
                mobileApiJson(['error' => ['code' => 'invalid_event', 'message' => 'A valid event ID is required.']], 422);
            }
            $existing = fetchRows($db, 'SELECT * FROM events WHERE id = :id LIMIT 1', ['id' => $eventId])[0] ?? null;
            if (!is_array($existing)) {
                mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Event not found.']], 404);
            }
        }

        $imageData = $payload['events_file_data'] ?? '';
        if (!is_string($imageData)) {
            mobileApiJson(['error' => ['code' => 'invalid_event_image', 'message' => 'events_file_data must be a JPEG data URL.']], 422);
        }
        $file = (string) ($existing['events_file'] ?? '');
        if ($imageData !== '') {
            $file = saveCroppedJpeg($imageData, 'events_file', '', 300 * 1024, 1280, 720);
            $newFiles[] = ['events_file', $file];
        }

        if ($action === 'create') {
            if (columnExists($db, 'events', 'sort_order')) {
                $db->prepare(
                    'INSERT INTO events (events_headlines, events_details, events_file, events_status, sort_order)
                     VALUES (:headline, :details, :file, 1, :sort_order)'
                )->execute([
                    'headline' => trim($headline),
                    'details' => trim($details),
                    'file' => $file,
                    'sort_order' => nextEventSortOrder($db),
                ]);
            } else {
                $db->prepare(
                    'INSERT INTO events (events_headlines, events_details, events_file, events_status)
                     VALUES (:headline, :details, :file, 1)'
                )->execute(['headline' => trim($headline), 'details' => trim($details), 'file' => $file]);
            }
            $eventId = (int) $db->lastInsertId();
        } else {
            $db->prepare(
                'UPDATE events SET events_headlines = :headline, events_details = :details, events_file = :file WHERE id = :id'
            )->execute([
                'headline' => trim($headline),
                'details' => trim($details),
                'file' => $file,
                'id' => $eventId,
            ]);
            if ($imageData !== '') {
                replacePublicMediaFile('events_file', (string) ($existing['events_file'] ?? ''), $file);
            }
        }

        $gallerySaved = saveEventGalleryUploads($db, $eventId, 8, 2 * 1024 * 1024);
        mobileApiJson(
            ['data' => ['id' => $eventId, 'status' => $action === 'create' ? 'created' : 'updated', 'gallery_images_added' => $gallerySaved]],
            $action === 'create' ? 201 : 200
        );
    }

    if ($action === 'delete_image') {
        $eventId = mobileAdminEventId($payload['event_id'] ?? null);
        $imageId = mobileAdminEventId($payload['image_id'] ?? null);
        if ($eventId < 1 || $imageId < 1) {
            mobileApiJson(['error' => ['code' => 'invalid_event_image', 'message' => 'Valid event_id and image_id are required.']], 422);
        }
        if (!deleteEventImage($db, $imageId, $eventId)) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Event photo not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $imageId, 'status' => 'deleted']]);
    }

    if ($action === 'reorder') {
        $order = $payload['order'] ?? null;
        if (!is_array($order) || $order === []) {
            mobileApiJson(['error' => ['code' => 'invalid_event_order', 'message' => 'order must be a non-empty array of event IDs.']], 422);
        }
        foreach ($order as $id) {
            if (mobileAdminEventId($id) < 1) {
                mobileApiJson(['error' => ['code' => 'invalid_event_order', 'message' => 'Every event ID in order must be a positive integer.']], 422);
            }
        }
        saveEventListOrder($db, $order);
        mobileApiJson(['data' => ['status' => 'reordered']]);
    }

    $eventId = mobileAdminEventId($payload['id'] ?? null);
    if ($eventId < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_event', 'message' => 'A valid event ID is required.']], 422);
    }
    if ($action === 'toggle') {
        $statement = $db->prepare('UPDATE events SET events_status = IF(events_status = 1, 0, 1) WHERE id = :id');
        $statement->execute(['id' => $eventId]);
        if ($statement->rowCount() !== 1) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Event not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $eventId, 'status' => 'toggled']]);
    }

    if (!deleteEventRecord($db, $eventId)) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Event not found.']], 404);
    }
    mobileApiJson(['data' => ['id' => $eventId, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    foreach ($newFiles as [$directory, $filename]) {
        deletePublicMediaFile($directory, $filename);
    }
    mobileApiDatabaseFailure($exception, 'admin events');
} catch (RuntimeException $exception) {
    foreach ($newFiles as [$directory, $filename]) {
        deletePublicMediaFile($directory, $filename);
    }
    mobileApiJson(['error' => ['code' => 'invalid_event', 'message' => $exception->getMessage()]], 422);
} catch (Throwable $exception) {
    foreach ($newFiles as [$directory, $filename]) {
        deletePublicMediaFile($directory, $filename);
    }
    error_log('Mobile API admin events failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The event request could not be completed.']], 500);
}
