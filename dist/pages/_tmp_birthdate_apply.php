<?php
declare(strict_types=1);

/**
 * One-shot maintenance endpoint. Deletes itself after a successful apply.
 * Remove this file from the repository after production use.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/shared/student-birthdate-fix.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

const BIRTHDATE_FIX_ONCE_TOKEN = '1d9253c93b3e4c90100c531fc78aa43b671807a0b870aec1ff97804cc8b3fe16';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) ($payload['token'] ?? '');
if ($token === '' || !hash_equals(BIRTHDATE_FIX_ONCE_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDatabaseConnection();
    if (function_exists('ensureStudentRegistrationColumn')) {
        ensureStudentRegistrationColumn($db);
    }
    if (function_exists('ensureSchoolSchema')) {
        ensureSchoolSchema($db);
    }

    $inputRows = $payload['rows'] ?? null;
    if (!is_array($inputRows) || $inputRows === []) {
        throw new RuntimeException('No rows provided.');
    }

    $matrix = [['registration_no', 'birth_date']];
    foreach ($inputRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $matrix[] = [
            (string) ($row['reg_no'] ?? ''),
            (string) ($row['birth_date'] ?? ''),
        ];
    }

    $preview = studentBirthdateFixBuildPreview($db, $matrix);
    if ((int) $preview['blocking'] > 0) {
        $sample = [];
        foreach ($preview['rows'] as $row) {
            if ($row['errors'] === []) {
                continue;
            }
            $sample[] = [
                'line' => $row['line'],
                'reg_no' => $row['reg_no'],
                'errors' => $row['errors'],
            ];
            if (count($sample) >= 12) {
                break;
            }
        }
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'Preview has blocking rows.',
            'blocking' => $preview['blocking'],
            'ready' => $preview['ready'],
            'unchanged' => $preview['unchanged'],
            'sample' => $sample,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updated = studentBirthdateFixApply($db, $preview['rows']);
    @unlink(__FILE__);

    echo json_encode([
        'ok' => true,
        'updated' => $updated,
        'unchanged' => $preview['unchanged'],
        'total' => count($preview['rows']),
        'removed' => !is_file(__FILE__),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => userFacingError($exception),
    ], JSON_UNESCAPED_UNICODE);
}
