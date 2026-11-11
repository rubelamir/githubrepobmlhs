<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\AmountInWords;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::FEES_VIEW_CLASS);
$paymentId = idFromSignedRecordRef((string) ($_GET['ref'] ?? ''), 'acc_payment');
$payment = $paymentId > 0 ? accPayments()->payment($paymentId) : null;
if ($payment === null) {
    redirectWithMessage('payments.php', 'danger', 'রসিদটি পাওয়া যায়নি।');
}
try {
    accStudents()->requireInScope($access, Capability::FEES_VIEW_CLASS, (int) $payment['student_id']);
} catch (Throwable $exception) {
    redirectWithMessage('payments.php', 'danger', userFacingError($exception));
}

$db = getDatabaseConnection();
$settings = schoolSettings($db);
$logo = !empty($settings['logo']) ? publicSiteUrl((string) $settings['logo']) : '';
$schoolName = trim((string) ($settings['school_name_bn'] ?? ''));
$address = trim((string) ($settings['address'] ?? ''));
$eiin = trim((string) ($settings['eiin'] ?? ''));
$receipt = accPayments()->receiptLines($paymentId);
$amount = Money::fromDb($payment['amount']);
$reversed = $payment['status'] === PaymentService::REVERSED;
[$flashType, $flashMessage] = consumeFlash();
$back = signedRecordUrl('collect.php', (int) $payment['student_id'], 'acc_student');
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>রসিদ <?= h((string) $payment['receipt_no']) ?></title>
  <link rel="stylesheet" href="<?= h(accAssetUrl('../../', 'css/accounts-receipt.css')) ?>">
</head>
<body>
  <div class="receipt-toolbar">
    <a href="<?= h($back) ?>">← আদায়ে ফিরুন</a>
    <a href="payments.php">আদায় তালিকা</a>
    <button type="button" onclick="window.print()">প্রিন্ট / PDF</button>
  </div>
  <?php if ($flashMessage !== ''): ?>
    <div class="receipt-flash receipt-flash-<?= h($flashType) ?>"><?= h($flashMessage) ?></div>
  <?php endif; ?>
  <main class="receipt-sheet">
    <?php foreach (['অভিভাবক কপি', 'অফিস কপি'] as $copy): ?>
      <section class="receipt<?= $reversed ? ' is-reversed' : '' ?>">
        <?php if ($reversed): ?><div class="receipt-stamp">রিভার্সড / বাতিল</div><?php endif; ?>
        <header class="receipt-head">
          <?php if ($logo !== ''): ?><img src="<?= h($logo) ?>" alt="" class="receipt-logo"><?php endif; ?>
          <div>
            <div class="receipt-school"><?= h($schoolName) ?></div>
            <div class="receipt-address"><?= h($address) ?><?= $eiin !== '' ? ' · EIIN: ' . h(bnDigits($eiin)) : '' ?></div>
          </div>
          <div class="receipt-copy"><?= h($copy) ?></div>
        </header>
        <h1 class="receipt-title">ফি আদায়ের রসিদ</h1>
        <dl class="receipt-meta">
          <div><dt>রসিদ নং</dt><dd><strong><?= h((string) $payment['receipt_no']) ?></strong></dd></div>
          <div><dt>তারিখ</dt><dd><?= h(accFormatDateTime((string) $payment['collected_at'])) ?></dd></div>
          <div><dt>শিক্ষার্থী</dt><dd><?= h((string) $payment['name_snapshot']) ?></dd></div>
          <div><dt>শ্রেণী / শাখা</dt><dd><?= h((string) $payment['class_name_ban']) ?><?= trim((string) $payment['section_name_ban']) !== '' ? ' — ' . h((string) $payment['section_name_ban']) : '' ?></dd></div>
          <div><dt>রোল</dt><dd><?= h(bnDigits((string) $payment['roll_snapshot'])) ?></dd></div>
          <div><dt>সেশন</dt><dd><?= h(bnDigits((string) $payment['session_year'])) ?></dd></div>
        </dl>
        <table class="receipt-lines">
          <thead><tr><th>বিবরণ</th><th>ইনভয়েস</th><th class="num">টাকা</th></tr></thead>
          <tbody>
            <?php foreach ($receipt['lines'] as $line): ?>
              <tr>
                <td><?= h((string) $line['description']) ?> — <?= h((string) $line['billing_label']) ?></td>
                <td><?= h((string) $line['invoice_no']) ?></td>
                <td class="num"><?= h(Money::format(Money::fromDb($line['amount']), false)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($receipt['advance'] > 0): ?>
              <tr><td>অগ্রিম জমা (পরবর্তী ফিতে সমন্বয় হবে)</td><td>—</td><td class="num"><?= h(Money::format($receipt['advance'], false)) ?></td></tr>
            <?php endif; ?>
          </tbody>
          <tfoot><tr><th colspan="2">মোট (<?= h(PaymentService::METHOD_LABELS[$payment['method']] ?? (string) $payment['method']) ?>)</th><th class="num"><?= h(Money::format($amount)) ?></th></tr></tfoot>
        </table>
        <p class="receipt-words">কথায়: <?= h(AmountInWords::taka($amount)) ?></p>
        <?php if ((string) $payment['payer_name'] !== '' || (string) $payment['note'] !== ''): ?>
          <p class="receipt-note"><?= (string) $payment['payer_name'] !== '' ? 'প্রদানকারী: ' . h((string) $payment['payer_name']) : '' ?><?= (string) $payment['note'] !== '' ? ' · ' . h((string) $payment['note']) : '' ?></p>
        <?php endif; ?>
        <?php if ($reversed): ?>
          <p class="receipt-note">রিভার্সের কারণ: <?= h((string) $payment['reversal_reason']) ?> (<?= h(accFormatDateTime((string) $payment['reversed_at'])) ?>)</p>
        <?php endif; ?>
        <footer class="receipt-foot">
          <div>গ্রহণকারী: <?= $payment['gateway_txn_id'] ? 'অনলাইন (' . h(PaymentService::METHOD_LABELS[$payment['method']] ?? (string) $payment['method']) . ')' : h((string) $payment['collected_by_name']) ?></div>
          <div class="receipt-sign">স্বাক্ষর</div>
        </footer>
        <div class="receipt-fine">কম্পিউটারে তৈরি রসিদ। রসিদ নম্বর দিয়ে অফিসে যাচাই করা যাবে।</div>
      </section>
    <?php endforeach; ?>
  </main>
  <script>
    (function () {
      var sheet = document.querySelector('.receipt-sheet');
      if (!sheet || sheet.querySelectorAll('.receipt').length < 2) {
        return;
      }
      function layout() {
        sheet.classList.remove('is-split', 'is-long', 'is-fitted');
        var pageHeight = sheet.getBoundingClientRect().width / 210 * 290;
        var longCopy = false;
        sheet.querySelectorAll('.receipt').forEach(function (copy) {
          if (copy.offsetHeight > pageHeight) {
            longCopy = true;
          }
        });
        if (longCopy) {
          sheet.classList.add('is-long', 'is-split');
        } else if (sheet.scrollHeight > pageHeight + 1) {
          sheet.classList.add('is-split', 'is-fitted');
        } else {
          sheet.classList.add('is-fitted');
        }
      }
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(layout);
      }
      window.addEventListener('load', layout);
      window.addEventListener('beforeprint', layout);
    }());
  </script>
</body>
</html>
