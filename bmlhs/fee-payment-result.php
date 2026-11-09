<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/fee-portal.php';

use School\Accounting\Gateway\GuardianPortal;
use School\Accounting\Gateway\OnlinePaymentService;
use School\Accounting\Support\Money;

$resultTxnId = requestRecordId('acc_portal_txn');
$resultTxn = $resultTxnId > 0 ? feePortalServices()['online']->transaction($resultTxnId) : null;
if ($resultTxn === null) {
    feePortalRedirect('fee-payment.php', 'danger', 'পেমেন্টের তথ্য পাওয়া যায়নি।');
}
$resultOwner = GuardianPortal::sessionStudentId() === (int) $resultTxn['student_id'];
$resultStatus = (string) $resultTxn['status'];
$resultMessage = match ($resultStatus) {
    OnlinePaymentService::SUCCESS => 'পেমেন্ট সফল হয়েছে এবং রসিদ তৈরি হয়েছে।',
    OnlinePaymentService::PENDING, OnlinePaymentService::INITIATED => 'পেমেন্টটি যাচাই হচ্ছে। টাকা কাটা হয়ে থাকলে কিছুক্ষণের মধ্যে সার্ভার নিজে থেকেই নিশ্চিত করবে; আবার পেমেন্ট করবেন না।',
    OnlinePaymentService::CANCELLED => 'পেমেন্টটি বাতিল করা হয়েছে। কোনো টাকা কাটা হয়নি।',
    OnlinePaymentService::REVIEW => 'টাকা গ্রহণ হয়েছে, তবে হিসাব মেলাতে অফিসের যাচাই প্রয়োজন। বিদ্যালয় শীঘ্রই রসিদ নিশ্চিত করবে।',
    default => 'পেমেন্ট সম্পন্ন হয়নি। কোনো টাকা কাটা হয়নি; চাইলে আবার চেষ্টা করুন।',
};
$resultAlert = match ($resultStatus) {
    OnlinePaymentService::SUCCESS => 'alert-success',
    OnlinePaymentService::PENDING, OnlinePaymentService::INITIATED, OnlinePaymentService::REVIEW => 'alert-warning',
    default => 'alert-secondary',
};

$pageAssets = [];
$pageStylesheets = ['css/fee-portal.css'];
$pageMeta = ['title' => 'পেমেন্টের ফলাফল', 'description' => 'অনলাইন ফি পরিশোধের ফলাফল', 'noindex' => true];
include 'header.php';
include 'navbar&marqueetag.php';
schoolPageHero('পেমেন্টের ফলাফল', '', 'অভিভাবক পোর্টাল');
?>
<div class="fee-portal container py-4 py-lg-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="alert <?= h($resultAlert) ?>" role="status"><?= h($resultMessage) ?></div>
            <section class="fee-portal-card">
                <dl class="row mb-0">
                    <dt class="col-sm-5">অবস্থা</dt>
                    <dd class="col-sm-7"><span class="badge <?= h(OnlinePaymentService::STATUS_BADGES[$resultStatus] ?? 'text-bg-secondary') ?>"><?= h(OnlinePaymentService::STATUS_LABELS[$resultStatus] ?? $resultStatus) ?></span></dd>
                    <dt class="col-sm-5">ইনভয়েস নম্বর</dt>
                    <dd class="col-sm-7"><?= h((string) $resultTxn['merchant_invoice_no']) ?></dd>
                    <dt class="col-sm-5">পরিমাণ</dt>
                    <dd class="col-sm-7"><?= h(Money::format(Money::fromDb($resultTxn['payable_amount']))) ?></dd>
                    <?php if ($resultTxn['trx_id']): ?>
                    <dt class="col-sm-5">bKash TrxID</dt>
                    <dd class="col-sm-7"><?= h((string) $resultTxn['trx_id']) ?></dd>
                    <?php endif; ?>
                    <?php if ($resultTxn['receipt_no']): ?>
                    <dt class="col-sm-5">রসিদ নম্বর</dt>
                    <dd class="col-sm-7"><?= h((string) $resultTxn['receipt_no']) ?></dd>
                    <?php endif; ?>
                    <?php if ($resultOwner): ?>
                    <dt class="col-sm-5">শিক্ষার্থী</dt>
                    <dd class="col-sm-7"><?= h(trim((string) ($resultTxn['name_ban'] ?? '')) ?: (string) ($resultTxn['name_eng'] ?? '')) ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-5">সময়</dt>
                    <dd class="col-sm-7"><?= h(accFormatDateTime((string) ($resultTxn['completed_at'] ?? $resultTxn['created_at']))) ?></dd>
                </dl>
            </section>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($resultOwner && $resultTxn['payment_id']): ?>
                <a class="btn btn-success" href="<?= h(signedRecordUrl('fee-receipt.php', (int) $resultTxn['payment_id'], 'acc_portal_receipt')) ?>">রসিদ দেখুন / প্রিন্ট</a>
                <?php endif; ?>
                <?php if (in_array($resultStatus, [OnlinePaymentService::PENDING, OnlinePaymentService::INITIATED], true)): ?>
                <a class="btn btn-outline-primary" href="<?= h(signedRecordUrl('fee-payment-result.php', (int) $resultTxn['id'], 'acc_portal_txn')) ?>">আবার দেখুন</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="fee-payment.php">পোর্টালে ফিরুন</a>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
