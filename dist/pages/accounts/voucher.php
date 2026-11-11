<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Ledger\VoucherService;
use School\Accounting\Support\AmountInWords;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::VOUCHER_CREATE, Capability::VOUCHER_APPROVE]);
$vouchers = accVouchers();
$canApprove = $access->can(Capability::VOUCHER_APPROVE);
$voucherId = postedRecordId('acc_voucher');
$voucher = $voucherId > 0 ? $vouchers->voucher($voucherId) : null;
if ($voucher === null) {
    redirectWithMessage('vouchers.php', 'danger', 'ভাউচারটি পাওয়া যায়নি।');
}
$self = signedRecordUrl('voucher.php', $voucherId, 'acc_voucher');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'submit':
                $vouchers->submit($access, accAudit(), $voucherId);
                redirectWithMessage($self, 'success', 'ভাউচার অনুমোদনের জন্য জমা হয়েছে।');
            case 'approve':
                $number = $vouchers->approve($access, accAudit(), $voucherId);
                redirectWithMessage($self, 'success', 'ভাউচার অনুমোদিত হয়েছে — নং ' . $number . '।');
            case 'reject':
                $vouchers->reject($access, accAudit(), $voucherId, (string) ($_POST['remark'] ?? ''));
                redirectWithMessage($self, 'success', 'ভাউচার সংশোধনের জন্য ফেরত পাঠানো হয়েছে।');
            case 'cancel':
                $vouchers->cancel($access, accAudit(), $voucherId);
                redirectWithMessage($self, 'success', 'ভাউচার বাতিল হয়েছে (রেকর্ড সংরক্ষিত থাকবে)।');
            case 'reverse':
                $reversalId = $vouchers->reverse($access, accAudit(), $voucherId, (string) ($_POST['reason'] ?? ''));
                redirectWithMessage(signedRecordUrl('voucher.php', $reversalId, 'acc_voucher'), 'success', 'রিভার্সাল ভাউচার তৈরি হয়েছে।');
            case 'attach':
                if (!in_array($voucher['status'], [VoucherService::DRAFT, VoucherService::REJECTED, VoucherService::SUBMITTED], true) && !$canApprove) {
                    throw new RuntimeException('অনুমোদিত ভাউচারে শুধু প্রধান শিক্ষক সংযুক্তি যোগ করতে পারেন।');
                }
                $count = accAttachments()->storeUploads($access, accAudit(), 'voucher', $voucherId, $_FILES['attachments'] ?? []);
                redirectWithMessage($self, $count > 0 ? 'success' : 'warning', $count > 0 ? bnDigits((string) $count) . 'টি সংযুক্তি যোগ হয়েছে।' : 'কোনো ফাইল নির্বাচন করা হয়নি।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage($self, 'danger', userFacingError($exception));
    }
}

$lines = $vouchers->lines($voucherId);
$attachments = accAttachments()->forOwner('voucher', $voucherId);
$status = (string) $voucher['status'];
$isSystem = $voucher['source_key'] !== null;
$isOwner = (int) $voucher['created_by'] === $access->teacherId();
$editable = !$isSystem && in_array($status, [VoucherService::DRAFT, VoucherService::REJECTED], true) && ($isOwner || $canApprove);
$total = Money::fromDb($voucher['total']);
$debitTotal = array_sum(array_map(static fn (array $line): int => Money::fromDb($line['debit']), $lines));
$creditTotal = array_sum(array_map(static fn (array $line): int => Money::fromDb($line['credit']), $lines));
$title = $voucher['voucher_no'] !== null ? (string) $voucher['voucher_no'] : 'খসড়া #' . bnDigits((string) $voucherId);
$sourceLink = null;
if ($isSystem && preg_match('/^payment:(\d+)/', (string) $voucher['source_key'], $match) === 1) {
    $sourceLink = ['রসিদ দেখুন', signedRecordUrl('receipt.php', (int) $match[1], 'acc_payment')];
} elseif ($isSystem && str_starts_with((string) $voucher['source_key'], 'handover:')) {
    $sourceLink = ['হস্তান্তর তালিকা', 'handovers.php?status=received'];
} elseif ($isSystem && str_starts_with((string) $voucher['source_key'], 'deposit:')) {
    $sourceLink = ['ব্যাংক জমা', 'bank.php'];
}
$school = schoolSettings(getDatabaseConnection());

$basePath = '../../';
$pageTitle = 'ভাউচার ' . $title . ' | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header d-print-none">
          <div class="container-fluid d-flex flex-wrap gap-2 align-items-start">
            <div class="me-auto">
              <h3 class="mb-1">ভাউচার <code><?= h($title) ?></code></h3>
              <p class="text-secondary mb-0"><?= h(VoucherService::TYPES[$voucher['voucher_type']] ?? '') ?><?= $isSystem ? ' · স্বয়ংক্রিয়ভাবে তৈরি' : '' ?></p>
            </div>
            <a class="btn btn-outline-secondary" href="vouchers.php"><i class="bi bi-arrow-left"></i> তালিকা</a>
            <?php if ($sourceLink !== null): ?><a class="btn btn-outline-secondary" href="<?= h($sourceLink[1]) ?>"><?= h($sourceLink[0]) ?></a><?php endif; ?>
            <button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> প্রিন্ট</button>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <div class="d-print-none"><?php include __DIR__ . '/../../includes/flash.php'; ?></div>

            <div class="card mb-3 acc-voucher-print">
              <div class="card-body">
                <div class="d-none d-print-block text-center mb-3">
                  <div class="fs-4 fw-bold"><?= h((string) ($school['school_name_bn'] ?? '')) ?></div>
                  <div class="small"><?= h(trim((string) ($school['address'] ?? ''))) ?></div>
                  <div class="fw-semibold mt-1"><?= h(VoucherService::TYPES[$voucher['voucher_type']] ?? '') ?></div>
                </div>
                <div class="row g-2 mb-3">
                  <div class="col-sm-4"><span class="text-secondary small">ভাউচার নং</span><div class="fw-semibold"><code><?= h($title) ?></code></div></div>
                  <div class="col-sm-4"><span class="text-secondary small">তারিখ</span><div class="fw-semibold"><?= h(bnDigits((string) $voucher['voucher_date'])) ?></div></div>
                  <div class="col-sm-4"><span class="text-secondary small">অবস্থা</span><div><span class="badge <?= h(VoucherService::STATUS_BADGES[$status] ?? 'text-bg-light') ?>"><?= h(VoucherService::STATUS_LABELS[$status] ?? $status) ?></span>
                    <?php if ($voucher['reversed_by_id'] !== null): ?><a class="badge text-bg-dark text-decoration-none" href="<?= h(signedRecordUrl('voucher.php', (int) $voucher['reversed_by_id'], 'acc_voucher')) ?>">রিভার্সড → <?= h((string) $voucher['reversed_by_no']) ?></a><?php endif; ?>
                    <?php if ($voucher['reversal_of_id'] !== null): ?><a class="badge text-bg-dark text-decoration-none" href="<?= h(signedRecordUrl('voucher.php', (int) $voucher['reversal_of_id'], 'acc_voucher')) ?>">রিভার্সাল: <?= h((string) $voucher['reversal_of_no']) ?></a><?php endif; ?>
                  </div></div>
                  <div class="col-12"><span class="text-secondary small">বিবরণ</span><div><?= h((string) $voucher['narration']) ?></div></div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm table-bordered align-middle mb-2">
                    <thead><tr><th>কোড</th><th>হিসাব</th><th>নোট</th><th class="text-end">ডেবিট</th><th class="text-end">ক্রেডিট</th></tr></thead>
                    <tbody>
                      <?php foreach ($lines as $line): ?>
                        <tr>
                          <td><code><?= h((string) $line['code']) ?></code></td>
                          <td><?= h((string) $line['name_bn']) ?><?= $line['party_teacher'] ? ' <span class="small text-secondary">(' . h((string) $line['party_teacher']) . ')</span>' : '' ?></td>
                          <td class="small"><?= h((string) $line['memo']) ?></td>
                          <td class="text-end text-nowrap"><?= Money::fromDb($line['debit']) > 0 ? h(Money::format(Money::fromDb($line['debit']))) : '' ?></td>
                          <td class="text-end text-nowrap"><?= Money::fromDb($line['credit']) > 0 ? h(Money::format(Money::fromDb($line['credit']))) : '' ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="3">মোট</td><td class="text-end text-nowrap"><?= h(Money::format($debitTotal)) ?></td><td class="text-end text-nowrap"><?= h(Money::format($creditTotal)) ?></td></tr></tfoot>
                  </table>
                </div>
                <div class="small mb-3">কথায়: <?= h(AmountInWords::taka($total)) ?></div>
                <div class="row small text-secondary g-2">
                  <div class="col-sm-6">প্রস্তুতকারী: <strong class="text-body"><?= h((string) $voucher['created_by_name']) ?></strong> — <?= h(accFormatDateTime((string) $voucher['created_at'])) ?></div>
                  <?php if ($voucher['decided_by_name']): ?>
                    <div class="col-sm-6"><?= $status === VoucherService::REJECTED ? 'ফেরত দিয়েছেন' : 'অনুমোদনকারী' ?>: <strong class="text-body"><?= h((string) $voucher['decided_by_name']) ?></strong> — <?= h(accFormatDateTime((string) $voucher['decided_at'])) ?></div>
                  <?php endif; ?>
                  <?php if ((string) $voucher['decision_remark'] !== ''): ?><div class="col-12 text-danger-emphasis">ফেরতের কারণ: <?= h((string) $voucher['decision_remark']) ?></div><?php endif; ?>
                  <?php if ((string) $voucher['reversal_reason'] !== ''): ?><div class="col-12">রিভার্সালের কারণ: <?= h((string) $voucher['reversal_reason']) ?></div><?php endif; ?>
                </div>
                <div class="d-none d-print-flex justify-content-between mt-5 pt-4 small">
                  <span class="border-top px-4">প্রস্তুতকারী</span><span class="border-top px-4">হিসাবরক্ষক</span><span class="border-top px-4">প্রধান শিক্ষক</span>
                </div>
              </div>
            </div>

            <div class="row g-3 d-print-none">
              <div class="col-lg-6">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">সংযুক্তি (বিল/রসিদ)</h3></div>
                  <ul class="list-group list-group-flush">
                    <?php if ($attachments === []): ?><li class="list-group-item text-secondary">কোনো সংযুক্তি নেই।</li><?php endif; ?>
                    <?php foreach ($attachments as $file): ?>
                      <li class="list-group-item d-flex gap-2 align-items-center">
                        <i class="bi <?= $file['mime'] === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' ?>"></i>
                        <a class="me-auto" href="<?= h(signedRecordUrl('attachment.php', (int) $file['id'], 'acc_attachment')) ?>" target="_blank" rel="noopener"><?= h((string) $file['original_name']) ?></a>
                        <span class="small text-secondary"><?= h(bnDigits(number_format((int) $file['size_bytes'] / 1024, 0))) ?> KB · <?= h((string) $file['uploaded_by_name']) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <?php if (!$isSystem && ($status !== VoucherService::APPROVED || $canApprove) && $status !== VoucherService::CANCELLED): ?>
                    <form method="post" enctype="multipart/form-data" class="card-footer d-flex gap-2">
                      <?= csrfField() ?><input type="hidden" name="action" value="attach">
                      <input class="form-control form-control-sm" type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required aria-label="ফাইল">
                      <button class="btn btn-sm btn-outline-primary" type="submit">যোগ করুন</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">কার্যক্রম</h3></div>
                  <div class="card-body d-flex flex-column gap-2">
                    <?php if ($isSystem): ?>
                      <p class="text-secondary mb-0">এটি স্বয়ংক্রিয় ভাউচার। ভুল হলে মূল লেনদেনটি (রসিদ রিভার্স বা ব্যাংক জমা) থেকে সংশোধন করুন; তখন বিপরীত ভাউচার নিজে থেকেই তৈরি হবে।</p>
                    <?php endif; ?>
                    <?php if ($editable): ?>
                      <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-primary" href="<?= h(signedRecordUrl('voucher-form.php', $voucherId, 'acc_voucher')) ?>"><i class="bi bi-pencil"></i> সংশোধন</a>
                        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="submit"><button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> অনুমোদনের জন্য জমা</button></form>
                        <form method="post" data-acc-confirm="ভাউচারটি বাতিল করবেন? রেকর্ড থেকে যাবে, কিন্তু হিসাবে যোগ হবে না।" data-acc-confirm-variant="danger"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger" type="submit">বাতিল</button></form>
                      </div>
                    <?php endif; ?>
                    <?php if ($canApprove && !$isSystem && ($status === VoucherService::SUBMITTED || ($status === VoucherService::DRAFT && $isOwner))): ?>
                      <form method="post" data-acc-confirm="ভাউচারটি অনুমোদন করবেন? অনুমোদনের পর আর পরিবর্তন করা যাবে না।" data-acc-confirm-variant="success">
                        <?= csrfField() ?><input type="hidden" name="action" value="approve">
                        <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i> অনুমোদন</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($canApprove && !$isSystem && $status === VoucherService::SUBMITTED): ?>
                      <form method="post" class="d-flex flex-wrap gap-1" data-acc-confirm="ভাউচারটি সংশোধনের জন্য ফেরত দেবেন?" data-acc-confirm-variant="danger">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject">
                        <input class="form-control form-control-sm flex-grow-1" name="remark" required minlength="3" maxlength="255" placeholder="ফেরতের কারণ" aria-label="ফেরতের কারণ">
                        <button class="btn btn-sm btn-outline-danger" type="submit">ফেরত</button>
                      </form>
                      <form method="post" data-acc-confirm="ভাউচারটি বাতিল করবেন?" data-acc-confirm-variant="danger"><?= csrfField() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-link text-danger p-0" type="submit">বাতিল করুন</button></form>
                    <?php endif; ?>
                    <?php if ($canApprove && !$isSystem && $status === VoucherService::APPROVED && $voucher['reversed_by_id'] === null && $voucher['reversal_of_id'] === null): ?>
                      <form method="post" class="d-flex flex-wrap gap-1" data-acc-confirm="এই ভাউচারের বিপরীত (রিভার্সাল) ভাউচার আজকের তারিখে তৈরি হবে। চালিয়ে যাবেন?" data-acc-confirm-variant="danger">
                        <?= csrfField() ?><input type="hidden" name="action" value="reverse">
                        <input class="form-control form-control-sm flex-grow-1" name="reason" required minlength="5" maxlength="255" placeholder="রিভার্সালের কারণ" aria-label="রিভার্সালের কারণ">
                        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-arrow-counterclockwise"></i> রিভার্স</button>
                      </form>
                    <?php endif; ?>
                    <?php if (!$editable && !$canApprove && $status === VoucherService::SUBMITTED): ?>
                      <p class="text-secondary mb-0">প্রধান শিক্ষকের অনুমোদনের অপেক্ষায়।</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
