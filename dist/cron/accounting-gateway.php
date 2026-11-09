<?php
declare(strict_types=1);

/**
 * Re-checks pending online payments and unconfirmed refunds with the gateway.
 * Run every 5 minutes from cPanel cron, either:
 *   php /path/to/dist/cron/accounting-gateway.php
 * or
 *   curl -s "https://example.com/dist/cron/accounting-gateway.php?key=KEY"
 * (the key is shown to the head on the accounts "অনলাইন পেমেন্ট" page).
 */

require_once dirname(__DIR__, 2) . '/bmlhs/includes/site.php';
require_once dirname(__DIR__, 2) . '/shared/Accounting/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    if ($key === '' || !hash_equals(accGatewayCronKey(), $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}

$db = publicDatabase();
$lock = $db->query("SELECT GET_LOCK('acc_gateway_cron', 1)")->fetchColumn();
if ((int) $lock !== 1) {
    echo json_encode(['ok' => true, 'ran' => false, 'reason' => 'already_running']) . ($cli ? PHP_EOL : '');
    exit;
}

try {
    $services = accStandaloneServices($db, 'স্বয়ংক্রিয় যাচাই (cron)');
    $payments = $services['online']->reconcilePending(50);
    $refunds = $services['refunds']->reconcileProcessing($services['audit'], 20);
    echo json_encode(['ok' => true, 'ran' => true, 'payments' => $payments, 'refunds' => $refunds, 'at' => date('c')], JSON_UNESCAPED_UNICODE) . ($cli ? PHP_EOL : '');
} catch (Throwable $exception) {
    error_log('Gateway cron failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'failed']) . ($cli ? PHP_EOL : '');
} finally {
    $db->query("SELECT RELEASE_LOCK('acc_gateway_cron')");
}
