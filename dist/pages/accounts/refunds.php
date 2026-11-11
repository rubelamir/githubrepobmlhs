<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Gateway\RefundService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$canRequest = $access->can(Capability::REFUND_REQUEST);
$canApprove = $access->can(Capability::REFUND_APPROVE);
if (!$canRequest && !$canApprove) {
    redirectWithMessage('dashboard.php', 'danger', 'এই পাতার অনুমতি আপনার নেই।');
}
$refunds = accRefunds();
$basePath = '../../';
$pageTitle = 'টাকা ফেরত | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
$status = (string) ($_GET['status'] ?? ($canApprove ? RefundService::REQUESTED : ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'approve' || $action === 'reject' || $action === 'retry') {
            $result = $refunds->decide($access, accAudit(), postedRecordId('acc_refund'), $action !== 'reject', (string) ($_POST['remark'] ?? ''));
            $message = match ($result) {
                RefundService::COMPLETED => 'রিফান্ড সম্পন্ন হয়েছে এবং খাতায় সংশোধনী এন্ট্রি হয়েছে।',
                RefundService::PROCESSING => 'গেটওয়ে এখনো নিশ্চিত করেনি। cron পরে Refund Status দিয়ে মিলিয়ে নেবে।',
                RefundService::FAILED => 'গেটওয়ে রিফান্ড প্রত্যাখ্যান করেছে। কারণ দেখে আবার চেষ্টা করা যাবে।',
                RefundService::REJECTED => 'রিফান্ড নামঞ্জুর করা হয়েছে।',
                default => 'রিফান্ডের অবস্থা: ' . (RefundService::STATUS_LABELS[$result] ?? $result),
            };
            redirectWithMessage('refunds.php?status=' . rawurlencode($result), $result === RefundService::FAILED ? 'warning' : 'success', $message);
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('refunds.php', 'danger', userFacingError($exception));
    }
}

$list = $refunds->search($status);

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">টাকা ফেরত</h3>
            <p class="text-secondary mb-0">অনলাইন পেমেন্ট ফেরত: হিসাব রক্ষক অনুরোধ করেন, প্রধান শিক্ষক অনুমোদন করলে গেটওয়েতে যায়। গেটওয়ে নিশ্চিত করার আগে খাতায় কোনো এন্ট্রি হয় না। ভুল এন্ট্রি মুছে ফেলা হয় না — রিফান্ডই সংশোধন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="card">
              <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto">রিফান্ড তালিকা</h3>
                <form method="get" class="d-flex gap-1">
                  <select class="form-select form-select-sm" name="status" aria-label="অবস্থা">
                    <option value="">সব</option>
                    <?php foreach (RefundService::STATUS_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button>
                </form>
              </div>
              <div class="card-body">
                <?php if ($list === []): ?><p class="text-secondary mb-0">কোনো রিফান্ড নেই। অনুরোধ অনলাইন পেমেন্টের বিস্তারিত থেকে করা হয়।</p><?php endif; ?>
                <?php foreach ($list as $row): ?>
                  <article class="border rounded p-3 mb-2">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                      <div>
                        <strong><?= h((string) $row['refund_no']) ?></strong>
                        <span class="badge <?= h(RefundService::STATUS_BADGES[$row['status']] ?? 'text-bg-secondary') ?>"><?= h(RefundService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span>
                        <div class="small text-secondary"><?= h((string) $row['name_snapshot']) ?> · রসিদ <?= h((string) $row['receipt_no']) ?> · <?= h((string) $row['merchant_invoice_no']) ?><?= $row['trx_id'] ? ' · TrxID ' . h((string) $row['trx_id']) : '' ?></div>
                      </div>
                      <div class="text-end">
                        <div class="fw-semibold"><?= h(Money::format(Money::fromDb($row['amount']))) ?></div>
                        <div class="small text-secondary"><?= h(accFormatDateTime((string) $row['requested_at'])) ?> · <?= h((string) ($row['requested_by_name'] ?? '')) ?></div>
                      </div>
                    </div>
                    <p class="mb-1 mt-2"><?= h((string) $row['reason']) ?></p>
                    <?php if ($row['gateway_error']): ?><p class="small text-danger mb-1">গেটওয়ে: <?= h((string) $row['gateway_error']) ?></p><?php endif; ?>
                    <?php if ($row['remark']): ?><p class="small text-secondary mb-1">মন্তব্য: <?= h((string) $row['remark']) ?></p><?php endif; ?>
                    <?php if ($row['voucher_no']): ?><p class="small mb-1">ভাউচার <a href="<?= h(signedRecordUrl('voucher.php', (int) $row['voucher_id'], 'acc_voucher')) ?>"><?= h((string) $row['voucher_no']) ?></a></p><?php endif; ?>
                    <?php if ($canApprove && in_array($row['status'], [RefundService::REQUESTED, RefundService::FAILED], true)): ?>
                      <div class="d-flex flex-wrap gap-2 mt-2">
                        <form method="post" class="d-flex flex-wrap gap-1" data-acc-confirm="<?= $row['status'] === RefundService::FAILED ? 'ব্যর্থ রিফান্ড আবার গেটওয়েতে পাঠাবেন?' : 'রিফান্ড অনুমোদন করে গেটওয়েতে পাঠাবেন?' ?>">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="<?= $row['status'] === RefundService::FAILED ? 'retry' : 'approve' ?>">
                          <?= signedRecordField((int) $row['id'], 'acc_refund') ?>
                          <input class="form-control form-control-sm" name="remark" maxlength="255" placeholder="মন্তব্য (ঐচ্ছিক)" aria-label="মন্তব্য">
                          <button class="btn btn-sm btn-success" type="submit"><?= $row['status'] === RefundService::FAILED ? 'আবার চেষ্টা' : 'অনুমোদন' ?></button>
                        </form>
                        <form method="post" class="d-flex flex-wrap gap-1">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="reject">
                          <?= signedRecordField((int) $row['id'], 'acc_refund') ?>
                          <input class="form-control form-control-sm" name="remark" maxlength="255" required placeholder="নামঞ্জুরের কারণ" aria-label="নামঞ্জুরের কারণ">
                          <button class="btn btn-sm btn-outline-danger" type="submit">নামঞ্জুর</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
