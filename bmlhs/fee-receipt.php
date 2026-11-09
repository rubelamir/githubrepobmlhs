<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/fee-portal.php';

use School\Accounting\Collection\PaymentService;
use School\Accounting\Gateway\GuardianPortal;
use School\Accounting\Support\AmountInWords;
use School\Accounting\Support\Money;

$receiptServices = feePortalServices();
$receiptPaymentId = requestRecordId('acc_portal_receipt');
$receiptPayment = $receiptPaymentId > 0 ? $receiptServices['payments']->payment($receiptPaymentId) : null;
if ($receiptPayment === null || (int) $receiptPayment['student_id'] !== GuardianPortal::sessionStudentId()) {
    feePortalRedirect('fee-payment.php', 'warning', 'রসিদ দেখতে আগে পোর্টালে প্রবেশ করুন।');
}

$receiptDb = publicDatabase();
$receiptSettings = schoolSettings($receiptDb);
$receiptLogo = !empty($receiptSettings['logo']) ? publicSiteUrl((string) $receiptSettings['logo']) : '';
$receiptLines = $receiptServices['payments']->receiptLines($receiptPaymentId);
$receiptAmount = Money::fromDb($receiptPayment['amount']);
$receiptReversed = $receiptPayment['status'] === PaymentService::REVERSED;
$receiptTxn = $receiptPayment['gateway_txn_id'] ? $receiptServices['online']->transaction((int) $receiptPayment['gateway_txn_id']) : null;
$receiptRefunded = $receiptTxn !== null ? Money::fromDb($receiptTxn['refunded_amount']) : 0;
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>রসিদ <?= h((string) $receiptPayment['receipt_no']) ?></title>
  <link rel="stylesheet" href="<?= h(adminUrl('css/accounts-receipt.css')) ?>?v=<?= (int) @filemtime(dirname(__DIR__) . '/dist/css/accounts-receipt.css') ?>">
</head>
<body>
  <div class="receipt-toolbar">
    <a href="fee-payment.php">← পোর্টালে ফিরুন</a>
    <button type="button" onclick="window.print()">প্রিন্ট / PDF</button>
  </div>
  <main class="receipt-sheet">
    <section class="receipt<?= $receiptReversed ? ' is-reversed' : '' ?>">
      <?php if ($receiptReversed): ?><div class="receipt-stamp">রিভার্সড / বাতিল</div><?php endif; ?>
      <header class="receipt-head">
        <?php if ($receiptLogo !== ''): ?><img src="<?= h($receiptLogo) ?>" alt="" class="receipt-logo"><?php endif; ?>
        <div>
          <div class="receipt-school"><?= h(trim((string) ($receiptSettings['school_name_bn'] ?? ''))) ?></div>
          <div class="receipt-address"><?= h(trim((string) ($receiptSettings['address'] ?? ''))) ?></div>
        </div>
        <div class="receipt-copy">অভিভাবক কপি</div>
      </header>
      <h1 class="receipt-title">ফি আদায়ের রসিদ</h1>
      <dl class="receipt-meta">
        <div><dt>রসিদ নং</dt><dd><strong><?= h((string) $receiptPayment['receipt_no']) ?></strong></dd></div>
        <div><dt>তারিখ</dt><dd><?= h(accFormatDateTime((string) $receiptPayment['collected_at'])) ?></dd></div>
        <div><dt>শিক্ষার্থী</dt><dd><?= h((string) $receiptPayment['name_snapshot']) ?></dd></div>
        <div><dt>শ্রেণী / শাখা</dt><dd><?= h((string) $receiptPayment['class_name_ban']) ?><?= trim((string) $receiptPayment['section_name_ban']) !== '' ? ' — ' . h((string) $receiptPayment['section_name_ban']) : '' ?></dd></div>
        <div><dt>রোল</dt><dd><?= h(bnDigits((string) $receiptPayment['roll_snapshot'])) ?></dd></div>
        <?php if ($receiptTxn !== null): ?>
        <div><dt>TrxID</dt><dd><?= h((string) $receiptTxn['trx_id']) ?></dd></div>
        <?php endif; ?>
      </dl>
      <table class="receipt-lines">
        <thead><tr><th>বিবরণ</th><th>ইনভয়েস</th><th class="num">টাকা</th></tr></thead>
        <tbody>
          <?php foreach ($receiptLines['lines'] as $line): ?>
          <tr>
            <td><?= h((string) $line['description']) ?> — <?= h((string) $line['billing_label']) ?></td>
            <td><?= h((string) $line['invoice_no']) ?></td>
            <td class="num"><?= h(Money::format(Money::fromDb($line['amount']), false)) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if ($receiptLines['advance'] > 0): ?>
          <tr><td>অগ্রিম জমা (পরবর্তী ফিতে সমন্বয় হবে)</td><td>—</td><td class="num"><?= h(Money::format($receiptLines['advance'], false)) ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot><tr><th colspan="2">মোট (<?= h(PaymentService::METHOD_LABELS[$receiptPayment['method']] ?? (string) $receiptPayment['method']) ?>)</th><th class="num"><?= h(Money::format($receiptAmount)) ?></th></tr></tfoot>
      </table>
      <p class="receipt-words">কথায়: <?= h(AmountInWords::taka($receiptAmount)) ?></p>
      <?php if ($receiptTxn !== null && Money::fromDb($receiptTxn['charge_amount']) > 0 && $receiptTxn['charge_bearer'] === 'payer'): ?>
      <p class="receipt-note">গেটওয়ে চার্জ <?= h(Money::format(Money::fromDb($receiptTxn['charge_amount']))) ?> আলাদাভাবে পরিশোধিত (মোট <?= h(Money::format(Money::fromDb($receiptTxn['payable_amount']))) ?>)।</p>
      <?php endif; ?>
      <?php if ($receiptRefunded > 0): ?>
      <p class="receipt-note">এই রসিদ থেকে <?= h(Money::format($receiptRefunded)) ?> ফেরত (রিফান্ড) দেওয়া হয়েছে।</p>
      <?php endif; ?>
      <footer class="receipt-foot">
        <div>গ্রহণ: <?= $receiptTxn !== null ? 'অনলাইন' : h((string) $receiptPayment['collected_by_name']) ?></div>
      </footer>
      <div class="receipt-fine">কম্পিউটারে তৈরি রসিদ, স্বাক্ষরের প্রয়োজন নেই। রসিদ নম্বর দিয়ে অফিসে যাচাই করা যাবে।</div>
    </section>
  </main>
</body>
</html>
