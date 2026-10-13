<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    mobileApiJson(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
}

try {
    $db = getDatabaseConnection();
    $identity = mobileApiRequireIdentity($db);
    if ($identity['account_type'] !== 'staff' || !canAccessModule('headlines', $identity['account'])) {
        mobileApiJson(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to manage notice headlines.']], 403);
    }
    if ($method === 'POST') {
        mobileApiBeginIdempotentWrite($db, $identity);
    }

    if ($method === 'GET') {
        $rows = fetchRows(
            $db,
            'SELECT id, notice_headlines AS text,
                    notice_headlines_status AS status,
                    notice_headlines_creation_time AS created_at
             FROM notice_headlines
             ORDER BY notice_headlines_creation_time DESC, id DESC
             LIMIT 100'
        );
        foreach ($rows as &$row) {
            $row['status'] = (int) ($row['status'] ?? 0) === 1;
        }
        unset($row);
        mobileApiJson(['data' => ['headlines' => $rows]]);
    }

    $payload = mobileApiReadJson(16384);
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['create', 'toggle', 'delete'], true)) {
        mobileApiJson(['error' => ['code' => 'invalid_action', 'message' => 'Choose create, toggle or delete.']], 422);
    }
    if ($action === 'create') {
        $text = $payload['text'] ?? null;
        if (!is_string($text) || trim($text) === '' || mb_strlen(trim($text), 'UTF-8') > 500) {
            mobileApiJson(['error' => ['code' => 'invalid_headline', 'message' => 'Headline text is required and must be at most 500 characters.']], 422);
        }
        $db->prepare(
            'INSERT INTO notice_headlines (notice_headlines, notice_headlines_status)
             VALUES (:text, 1)'
        )->execute(['text' => trim($text)]);
        mobileApiJson(['data' => ['id' => (int) $db->lastInsertId(), 'status' => 'published']], 201);
    }

    $headlineId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($headlineId) || $headlineId < 1) {
        mobileApiJson(['error' => ['code' => 'invalid_headline', 'message' => 'A valid headline ID is required.']], 422);
    }
    if ($action === 'toggle') {
        $statement = $db->prepare(
            'UPDATE notice_headlines
             SET notice_headlines_status = IF(notice_headlines_status = 1, 0, 1)
             WHERE id = :id'
        );
        $statement->execute(['id' => $headlineId]);
        if ($statement->rowCount() !== 1) {
            mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Headline not found.']], 404);
        }
        mobileApiJson(['data' => ['id' => $headlineId, 'status' => 'toggled']]);
    }

    $statement = $db->prepare('DELETE FROM notice_headlines WHERE id = :id');
    $statement->execute(['id' => $headlineId]);
    if ($statement->rowCount() !== 1) {
        mobileApiJson(['error' => ['code' => 'not_found', 'message' => 'Headline not found.']], 404);
    }
    mobileApiJson(['data' => ['id' => $headlineId, 'status' => 'deleted']]);
} catch (PDOException $exception) {
    mobileApiDatabaseFailure($exception, 'admin headlines');
} catch (Throwable $exception) {
    error_log('Mobile API admin headlines failed: ' . $exception->getMessage());
    mobileApiJson(['error' => ['code' => 'service_unavailable', 'message' => 'The headline request could not be completed.']], 500);
}
