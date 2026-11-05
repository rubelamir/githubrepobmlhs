<?php
declare(strict_types=1);

/**
 * Device endpoint for RFID readers / attendance machines.
 * POST or GET: ?key=DEVICE_KEY&card=CARD_NO[&device=NAME]
 */

require_once dirname(__DIR__, 2) . '/shared/school.php';
require_once dirname(__DIR__, 2) . '/shared/academics.php';
require_once dirname(__DIR__, 2) . '/shared/schema.php';
require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/card-punch.php';

header('Content-Type: application/json; charset=utf-8');

$key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if (!hash_equals(cardPunchDeviceKey(), $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid key']);
    exit;
}

try {
    $db = getDatabaseConnection();
    ensureCardPunchSchema($db);
    $card = (string) ($_GET['card'] ?? $_POST['card'] ?? '');
    $device = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['device'] ?? $_POST['device'] ?? 'device')) ?: 'device';
    $result = cardPunchRecord($db, $card, $device);
    unset($result['student']['photo'], $result['student']['id']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('card-punch: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
