<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Staff gallery CMS API.
 *
 * POST accepts JSON or multipart form data. Album covers and gallery photos use
 * the Admin cropper's JPEG data URL (1200x900, at most 300 KB); the shared helper
 * validates the image and writes it into the fixed public image_gallery directory.
 * No client-supplied storage paths are accepted.
 */
function mobileAdminGalleryPayload(): array
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

function mobileAdminGalleryId(mixed $value): int
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
    if ($identity['account_type'] !== 'staff' || !canAccessModule('cms-gallery', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage the photo gallery.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $hasAlbums = tableExists($db, 'gallery_albums');
        $albums = [];
        if ($hasAlbums) {
            foreach (fetchRows($db, 'SELECT * FROM gallery_albums ORDER BY album_year DESC, id DESC LIMIT 200') as $album) {
                $cover = publicMediaBasename((string) ($album['cover_file'] ?? ''));
                $albums[] = [
                    'id' => (int) $album['id'],
                    'title' => (string) $album['title'],
                    'event_name' => $album['event_name'] ?? null,
                    'year' => isset($album['album_year']) ? (int) $album['album_year'] : null,
                    'cover_url' => $cover === '' ? null : publicSiteUrl('image_gallery_file/' . $cover),
                    'status' => (int) $album['album_status'] === 1,
                    'created_at' => $album['created_at'] ?? null,
                ];
            }
        }

        $hasAlbumLink = columnExists($db, 'image_gallery', 'album_id');
        $columns = 'id, image_gallery_caption AS caption, image_gallery_file AS file,
                    image_gallery_status AS status, image_gallery_creation_time AS created_at';
        $rows = fetchRows($db, 'SELECT ' . $columns . ($hasAlbumLink ? ', album_id' : '') . '
                                FROM image_gallery ORDER BY image_gallery_creation_time DESC, id DESC LIMIT 300');
        $photos = [];
        foreach ($rows as $row) {
            $file = publicMediaBasename((string) ($row['file'] ?? ''));
            $photos[] = [
                'id' => (int) $row['id'],
                'caption' => (string) $row['caption'],
                'file_url' => $file === '' ? null : publicSiteUrl('image_gallery_file/' . $file),
                'status' => (int) ($row['status'] ?? 0) === 1,
                'album_id' => $hasAlbumLink && $row['album_id'] !== null ? (int) $row['album_id'] : null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        mobileApiJson(['data' => ['albums' => $albums, 'photos' => $photos, 'albums_available' => $hasAlbums, 'photo_album_link_available' => $hasAlbumLink]]);
    }

    $payload = mobileAdminGalleryPayload();
    $action = $payload['action'] ?? null;
    $actions = ['create_album', 'toggle_album', 'delete_album', 'create_photo', 'toggle_photo', 'delete_photo'];
    if (!is_string($action) || !in_array($action, $actions, true)) {
        mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Choose create_album, toggle_album, delete_album, create_photo, toggle_photo or delete_photo.']], 422);
    }

    if (in_array($action, ['create_album', 'toggle_album', 'delete_album'], true) && !tableExists($db, 'gallery_albums')) {
        mobileApiJson(['error' => ['code' => 'feature_unavailable', 'message' => 'Gallery albums are not installed in this database.']], 503);
    }

    if ($action === 'create_album') {
        $title = $payload['title'] ?? null;
        $eventName = $payload['event_name'] ?? '';
        $year = filter_var($payload['album_year'] ?? date('Y'), FILTER_VALIDATE_INT);
        $currentYear = (int) date('Y');
        if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title), 'UTF-8') > 255
            || !is_string($eventName) || mb_strlen(trim($eventName), 'UTF-8') > 255
            || !is_int($year) || $year < 1990 || $year > $currentYear + 1
        ) {
            mobileApiJson(['error' => ['code' => 'invalid_album', 'message' => 'Provide a title, event name up to 255 characters, and album_year from 1990 through next year.']], 422);
        }
        $coverData = $payload['cover_file_data'] ?? '';
        if (!is_string($coverData)) {
            mobileApiJson(['error' => ['code' => 'invalid_album_cover', 'message' => 'cover_file_data must be a JPEG data URL.']], 422);
        }
        $cover = $coverData === '' ? null : saveCroppedJpeg($coverData, 'image_gallery_file', '', 300 * 1024, 1200, 900);
        if ($cover !== null) {
            $newFiles[] = ['image_gallery_file', $cover];
        }
        $db->prepare(
            'INSERT INTO gallery_albums (title, event_name, album_year, cover_file, album_status)
             VALUES (:title, :event_name, :year, :cover, 1)'
        )->execute([
            'title' => trim($title),
            'event_name' => trim($eventName) !== '' ? trim($eventName) : null,
            'year' => $year,
            'cover' => $cover,
        ]);
        mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'created']], 201);
    }

    if ($action === 'create_photo') {
        $caption = $payload['caption'] ?? null;
        $photoData = $payload['image_gallery_file_data'] ?? '';
        $albumId = $payload['album_id'] ?? null;
        if (!is_string($caption) || trim($caption) === '' || mb_strlen(trim($caption), 'UTF-8') > 255
            || !is_string($photoData) || $photoData === ''
        ) {
            mobileApiJson(['error' => ['code' => 'invalid_gallery_photo', 'message' => 'Caption (max 255 characters) and a cropped JPEG data URL are required.']], 422);
        }
        $albumIdValue = 0;
        if ($albumId !== null && $albumId !== '' && $albumId !== '0' && $albumId !== 0) {
            $albumIdValue = mobileAdminGalleryId($albumId);
            if ($albumIdValue < 1 || !columnExists($db, 'image_gallery', 'album_id') || !tableExists($db, 'gallery_albums')) {
                mobileApiJson(['error' => ['code' => 'invalid_album', 'message' => 'This database cannot assign photos to albums.']], 422);
            }
            if (fetchRows($db, 'SELECT id FROM gallery_albums WHERE id = :id LIMIT 1', ['id' => $albumIdValue]) === []) {
                mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Album not found.']], 404);
            }
        }

        $file = saveCroppedJpeg($photoData, 'image_gallery_file', '', 300 * 1024, 1200, 900);
        $newFiles[] = ['image_gallery_file', $file];
        if (columnExists($db, 'image_gallery', 'album_id')) {
            $db->prepare(
                'INSERT INTO image_gallery (image_gallery_caption, image_gallery_file, image_gallery_status, album_id)
                 VALUES (:caption, :file, 1, :album_id)'
            )->execute(['caption' => trim($caption), 'file' => $file, 'album_id' => $albumIdValue > 0 ? $albumIdValue : null]);
        } else {
            $db->prepare(
                'INSERT INTO image_gallery (image_gallery_caption, image_gallery_file, image_gallery_status)
                 VALUES (:caption, :file, 1)'
            )->execute(['caption' => trim($caption), 'file' => $file]);
        }
        mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'created']], 201);
    }

    $recordId = mobileAdminGalleryId($payload['id'] ?? null);
    if ($recordId < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_gallery_item', 'message' => 'A valid record ID is required.']], 422);
    }
    if ($action === 'toggle_album' || $action === 'toggle_photo') {
        $table = $action === 'toggle_album' ? 'gallery_albums' : 'image_gallery';
        $column = $action === 'toggle_album' ? 'album_status' : 'image_gallery_status';
        $statement = $db->prepare("UPDATE `{$table}` SET `{$column}` = IF(`{$column}` = 1, 0, 1) WHERE id = :id");
        $statement->execute(['id' => $recordId]);
        if ($statement->rowCount() !== 1) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Gallery item not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $recordId, 'status' => 'toggled']]);
    }

    if ($action === 'delete_photo') {
        if (!deleteDatabaseRowWithMedia($db, 'image_gallery', $recordId, ['image_gallery_file' => 'image_gallery_file'])) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Gallery photo not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $recordId, 'status' => 'deleted']]);
    }

    $album = fetchRows($db, 'SELECT * FROM gallery_albums WHERE id = :id LIMIT 1', ['id' => $recordId])[0] ?? null;
    if (!is_array($album)) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Gallery album not found.']], 404);
    }
    if (columnExists($db, 'image_gallery', 'album_id')) {
        $db->prepare('UPDATE image_gallery SET album_id = NULL WHERE album_id = :id')->execute(['id' => $recordId]);
    }
    $db->prepare('DELETE FROM gallery_albums WHERE id = :id')->execute(['id' => $recordId]);
    replacePublicMediaFile('image_gallery_file', (string) ($album['cover_file'] ?? ''), '');
    mobileApiJson(['data' => ['id' => $recordId, 'status' => 'deleted', 'photos_preserved' => true]]);
} catch (PDOException $exception) {
    foreach ($newFiles as [$directory, $filename]) {
        deletePublicMediaFile($directory, $filename);
    }
    mobileApiDatabaseFailure($exception, 'admin gallery');
} catch (RuntimeException $exception) {
    foreach ($newFiles as [$directory, $filename]) {
        deletePublicMediaFile($directory, $filename);
    }
    mobileApiJson(['error' => ['code' => 'invalid_gallery_item', 'message' => $exception->getMessage()]], 422);
} catch (Throwable $exception) {
    foreach ($newFiles as [$directory, $filename]) {
        deletePublicMediaFile($directory, $filename);
    }
    error_log('Mobile API admin gallery failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The gallery request could not be completed.']], 500);
}
