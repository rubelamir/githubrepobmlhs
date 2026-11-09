<?php
declare(strict_types=1);

/**
 * Local stand-in for the bKash Tokenized Checkout v2 endpoints used by
 * BkashGateway. Behaviour of the next execute/query/refund is read from
 * the mode file (rewritten by the test between calls). Nothing here is a
 * guess at undocumented Nagad or live bKash fields beyond what BkashGateway
 * already sends and reads.
 */

$stateFile = getenv('BKASH_MOCK_STATE') ?: '';
$modeFile = getenv('BKASH_MOCK_MODE') ?: '';
if ($stateFile === '' || $modeFile === '') {
    http_response_code(500);
    echo 'mock files not configured';
    exit;
}

$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state += ['grants' => 0, 'executes' => [], 'payments' => []];
$mode = is_file($modeFile) ? (json_decode((string) file_get_contents($modeFile), true) ?: []) : [];
$mode += ['execute' => 'completed', 'query' => 'initiated', 'refund' => 'completed', 'query_verified' => false];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
header('Content-Type: application/json');

$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};

if (str_ends_with($path, '/auth/grant-token') || str_ends_with($path, '/auth/refresh-token')) {
    $state['grants']++;
    $save();
    echo json_encode([
        'id_token' => 'sandbox-id-token',
        'refresh_token' => 'sandbox-refresh-token',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ]);
    exit;
}

if (str_ends_with($path, '/payment/create')) {
    $id = 'PAY' . str_pad((string) (count($state['payments']) + 1), 6, '0', STR_PAD_LEFT);
    $state['payments'][$id] = [
        'amount' => (string) ($body['amount'] ?? ''),
        'invoice' => (string) ($body['merchantInvoiceNumber'] ?? ''),
        'payer' => (string) ($body['payerReference'] ?? ''),
    ];
    $state['executes'][$id] = 0;
    $save();
    echo json_encode([
        'paymentId' => $id,
        'bkashURL' => 'http://127.0.0.1:' . (getenv('BKASH_MOCK_PORT') ?: '8099') . '/checkout/' . $id,
        'transactionStatus' => 'Initiated',
        'amount' => $body['amount'] ?? '',
        'merchantInvoiceNumber' => $body['merchantInvoiceNumber'] ?? '',
    ]);
    exit;
}

$paymentId = (string) ($body['paymentId'] ?? '');
$payment = $state['payments'][$paymentId] ?? null;

if (str_ends_with($path, '/payment/execute')) {
    $state['executes'][$paymentId] = ($state['executes'][$paymentId] ?? 0) + 1;
    $save();
    if (($mode['execute'] ?? '') === 'timeout') {
        http_response_code(200);
        echo ' ';
        exit;
    }
    if (($mode['execute'] ?? '') === 'mismatch') {
        echo json_encode(completed($paymentId, $payment, '1.00'));
        exit;
    }
    echo json_encode(completed($paymentId, $payment, (string) ($payment['amount'] ?? '0.00')));
    exit;
}

if (str_ends_with($path, '/query/payment')) {
    if (($mode['query'] ?? '') === 'completed') {
        echo json_encode(completed($paymentId, $payment, (string) ($payment['amount'] ?? '0.00'), 'merchantInvoice'));
        exit;
    }
    echo json_encode([
        'paymentId' => $paymentId,
        'transactionStatus' => 'Initiated',
        'verificationStatus' => !empty($mode['query_verified']) ? 'Complete' : 'Incomplete',
        'amount' => $payment['amount'] ?? '',
        'merchantInvoice' => $payment['invoice'] ?? '',
    ]);
    exit;
}

if (str_ends_with($path, '/refund/payment/transaction')) {
    if (($mode['refund'] ?? '') === 'error') {
        echo json_encode(['externalCode' => '2048', 'errorMessageEn' => 'Refund not allowed']);
        exit;
    }
    if (($mode['refund'] ?? '') === 'silent') {
        echo ' ';
        exit;
    }
    $trx = 'RFD' . $paymentId;
    $state['refunds'][$paymentId][] = ['trx' => $trx, 'amount' => (string) ($body['refundAmount'] ?? '')];
    $save();
    echo json_encode([
        'refundTransactionStatus' => 'Completed',
        'refundTrxId' => $trx,
        'refundAmount' => $body['refundAmount'] ?? '',
        'paymentId' => $paymentId,
    ]);
    exit;
}

if (str_ends_with($path, '/refund/payment/status')) {
    $rows = [];
    foreach ($state['refunds'][$paymentId] ?? [] as $refund) {
        $rows[] = [
            'refundTrxId' => $refund['trx'],
            'refundAmount' => $refund['amount'],
            'refundTransactionStatus' => 'Completed',
        ];
    }
    echo json_encode(['refundTransactions' => $rows]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'unknown path', 'path' => $path]);

/** @param array<string, string>|null $payment */
function completed(string $paymentId, ?array $payment, string $amount, string $invoiceKey = 'merchantInvoiceNumber'): array
{
    return [
        'paymentId' => $paymentId,
        'trxId' => 'TRX' . $paymentId,
        'transactionStatus' => 'Completed',
        'amount' => $amount,
        $invoiceKey => $payment['invoice'] ?? '',
        'merchantInvoiceNumber' => $payment['invoice'] ?? '',
        'payerAccount' => '01712345678',
    ];
}
