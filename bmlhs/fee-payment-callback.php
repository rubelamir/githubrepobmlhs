<?php
declare(strict_types=1);

/**
 * bKash returns the payer here with ?paymentID=…&status=success|failure|cancel.
 * The status is only a hint: the server executes/queries the payment with
 * bKash before anything is recorded.
 */

require_once __DIR__ . '/includes/fee-portal.php';

$callbackRef = (string) ($_GET['paymentID'] ?? $_GET['paymentId'] ?? '');
$callbackStatus = (string) ($_GET['status'] ?? '');
$callbackTxn = 0;
try {
    $callbackTxn = feePortalServices()['online']->handleCallback('bkash', $callbackRef, $callbackStatus);
} catch (Throwable $exception) {
    error_log('bKash callback failed: ' . $exception->getMessage());
    $known = preg_match('/^[A-Za-z0-9]{1,80}$/', $callbackRef) === 1
        ? fetchRows(publicDatabase(), "SELECT id FROM acc_gateway_transactions WHERE gateway = 'bkash' AND gateway_payment_id = :ref", ['ref' => $callbackRef])
        : [];
    $callbackTxn = (int) ($known[0]['id'] ?? 0);
}

if ($callbackTxn < 1) {
    feePortalRedirect('fee-payment.php', 'danger', 'পেমেন্টটি শনাক্ত করা যায়নি। টাকা কাটা হয়ে থাকলে TrxID সহ বিদ্যালয়ে যোগাযোগ করুন।');
}
feePortalRedirect(signedRecordUrl('fee-payment-result.php', $callbackTxn, 'acc_portal_txn'));
