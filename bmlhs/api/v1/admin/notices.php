<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function mobileAdminNoticesPayload(): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType === 'application/json') {
        return mobileApiReadJson(16384);
    }
    if ($contentType === 'multipart/form-data' || $contentType === 'application/x-www-form-urlencoded') {
        return $_POST;
    }
    mobileApiJson(['error' => ['code' => 'unsupported_media_type', 'message' => 'Send JSON or multipart form data.']], 415);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
}

$newFile = '';
try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    if ($identity['account_type'] !== 'staff' || !canAccessModule('notices', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage notices.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $notices = fetchRows(
            $db,
            'SELECT id, main_notice_text AS text, main_notice_file AS file,
                    main_notice_status AS status, main_notice_creation_time AS created_at
             FROM main_notice
             ORDER BY main_notice_creation_time DESC, id DESC
             LIMIT 100'
        );
        foreach ($notices as &$notice) {
            $file = trim((string) ($notice['file'] ?? ''));
            $notice['file_url'] = $file === ''
                ? null
                : publicSiteUrl('main_notice_file/' . basename($file));
            $notice['status'] = (int) ($notice['status'] ?? 0) === 1;
        }
        unset($notice);

        mobileApiJson(['data' => ['notices' => $notices]]);
    }

    $payload = mobileAdminNoticesPayload();
    $action = $payload['action'] ?? null;
    if (!is_string($action)) {
        mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Choose publish, toggle or delete.']], 422);
    }

    if ($action === 'publish') {
        $text = $payload['text'] ?? null;
        if (!is_string($text) || trim($text) === '' || mb_strlen(trim($text), 'UTF-8') > 10000) {
            mobileApiJson(['error' => ['code' => 'invalid_notice', 'message' => 'Notice text is required and must be at most 10,000 characters.']], 422);
        }
        $newFile = storeUploadedFile(
            'main_notice_file',
            'main_notice_file',
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
            10 * 1024 * 1024,
            true
        ) ?? '';
        $statement = $db->prepare(
            'INSERT INTO main_notice (main_notice_text, main_notice_file, main_notice_status)
             VALUES (:text, :file, 1)'
        );
        $statement->bindValue(':text', trim($text), PDO::PARAM_STR);
        $statement->bindValue(':file', $newFile !== '' ? $newFile : null, $newFile !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $statement->execute();
        mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'published']], 201);
    }

    if (!in_array($action, ['toggle', 'delete'], true)) {
        mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Choose publish, toggle or delete.']], 422);
    }
    $noticeId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($noticeId) || $noticeId < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_notice', 'message' => 'A valid notice ID is required.']], 422);
    }

    if ($action === 'toggle') {
        $statement = $db->prepare(
            'UPDATE main_notice
             SET main_notice_status = IF(main_notice_status = 1, 0, 1)
             WHERE id = :id'
        );
        $statement->execute(['id' => $noticeId]);
        if ($statement->rowCount() !== 1) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Notice not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $noticeId, 'status' => 'toggled']]);
    }

    if (!deleteDatabaseRowWithMedia($db, 'main_notice', $noticeId, ['main_notice_file' => 'main_notice_file'])) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Notice not found.']], 404);
    }
    mobileApiJson(['data' => ['id' => $noticeId, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    if ($newFile !== '') {
        deletePublicMediaFile('main_notice_file', $newFile);
    }
    mobileApiDatabaseFailure($exception, 'admin notices');
} catch (RuntimeException $exception) {
    if ($newFile !== '') {
        deletePublicMediaFile('main_notice_file', $newFile);
    }
    mobileApiJson(['error' => ['code' => 'invalid_notice', 'message' => $exception->getMessage()]], 422);
} catch (Throwable $exception) {
    if ($newFile !== '') {
        deletePublicMediaFile('main_notice_file', $newFile);
    }
    error_log('Mobile API admin notices failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The notice request could not be completed.']], 500);
}
