<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Ledger\AttachmentStore;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\VoucherService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::VOUCHER_CREATE);
$vouchers = accVouchers();
$basePath = '../../';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$voucherId = isset($_GET['ref']) || isset($_POST['ref']) ? postedRecordId('acc_voucher') : null;
$voucher = $voucherId !== null ? $vouchers->voucher($voucherId) : null;
if ($voucherId !== null) {
    try {
        if ($voucher === null) {
            throw new RuntimeException('ভাউচারটি পাওয়া যায়নি।');
        }
        $vouchers->assertEditable($access, $voucher);
    } catch (RuntimeException $exception) {
        redirectWithMessage('vouchers.php', 'danger', $exception->getMessage());
    }
}
$pageTitle = ($voucher === null ? 'নতুন ভাউচার' : 'ভাউচার সংশোধন') . ' | School Admin';

$formLines = [];
$form = ['type' => VoucherService::PAYMENT, 'voucher_date' => accToday()->format('Y-m-d'), 'narration' => ''];
if ($voucher !== null) {
    $form = ['type' => (string) $voucher['voucher_type'], 'voucher_date' => (string) $voucher['voucher_date'], 'narration' => (string) $voucher['narration']];
    foreach ($vouchers->lines($voucherId) as $line) {
        $formLines[] = [
            'account_id' => (int) $line['account_id'],
            'debit' => Money::fromDb($line['debit']) > 0 ? Money::toInput(Money::fromDb($line['debit'])) : '',
            'credit' => Money::fromDb($line['credit']) > 0 ? Money::toInput(Money::fromDb($line['credit'])) : '',
            'memo' => (string) $line['memo'],
        ];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = [
        'type' => (string) ($_POST['type'] ?? ''),
        'voucher_date' => (string) ($_POST['voucher_date'] ?? ''),
        'narration' => (string) ($_POST['narration'] ?? ''),
        'lines' => is_array($_POST['lines'] ?? null) ? array_values($_POST['lines']) : [],
    ];
    try {
        accAttachments()->assertValid($_FILES['attachments'] ?? []);
        $result = $vouchers->saveDraft($access, accAudit(), $voucherId, $input, (string) ($_POST['idempotency_key'] ?? ''));
        $savedId = $result['voucher_id'];
        $uploaded = 0;
        if (!$result['duplicate'] && isset($_FILES['attachments'])) {
            try {
                $uploaded = accAttachments()->storeUploads($access, accAudit(), 'voucher', $savedId, $_FILES['attachments']);
            } catch (RuntimeException $exception) {
                redirectWithMessage(signedRecordUrl('voucher.php', $savedId, 'acc_voucher'), 'warning', 'ভাউচার সংরক্ষিত হয়েছে, কিন্তু সংযুক্তি যোগ হয়নি: ' . $exception->getMessage());
            }
        }
        if (!$result['duplicate'] && ($_POST['after'] ?? '') === 'submit') {
            $vouchers->submit($access, accAudit(), $savedId);
        }
        $message = $result['duplicate'] ? 'এই ভাউচার আগেই সংরক্ষিত হয়েছে।' : (($_POST['after'] ?? '') === 'submit' ? 'ভাউচার অনুমোদনের জন্য জমা হয়েছে।' : 'খসড়া সংরক্ষিত হয়েছে।');
        if ($uploaded > 0) {
            $message .= ' সংযুক্তি: ' . bnDigits((string) $uploaded) . 'টি।';
        }
        redirectWithMessage(signedRecordUrl('voucher.php', $savedId, 'acc_voucher'), $result['duplicate'] ? 'info' : 'success', $message);
    } catch (Throwable $exception) {
        $flashError = userFacingError($exception);
        $form = ['type' => $input['type'], 'voucher_date' => $input['voucher_date'], 'narration' => $input['narration']];
        $formLines = array_map(static fn ($line): array => [
            'account_id' => (int) ($line['account_id'] ?? 0),
            'debit' => (string) ($line['debit'] ?? ''),
            'credit' => (string) ($line['credit'] ?? ''),
            'memo' => (string) ($line['memo'] ?? ''),
        ], array_filter($input['lines'], 'is_array'));
    }
}
while (count($formLines) < 2) {
    $formLines[] = ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => ''];
}

$accounts = accChart()->postable();
$byType = [];
foreach ($accounts as $account) {
    if ((string) $account['money_kind'] === 'teacher_custody') {
        continue;
    }
    $byType[(string) $account['account_type']][] = $account;
}
$accountOptions = static function (int $selected) use ($byType): string {
    $html = '<option value="">হিসাব নির্বাচন</option>';
    foreach (ChartOfAccounts::TYPES as $type => $label) {
        if (!isset($byType[$type])) {
            continue;
        }
        $html .= '<optgroup label="' . h($label) . '">';
        foreach ($byType[$type] as $account) {
            $money = in_array((string) $account['money_kind'], ChartOfAccounts::SPENDABLE_KINDS, true);
            $html .= '<option value="' . (int) $account['id'] . '"' . ($money ? ' data-acc-money="1"' : '') . ((int) $account['id'] === $selected ? ' selected' : '') . '>'
                . h($account['code'] . ' — ' . $account['name_bn']) . '</option>';
        }
        $html .= '</optgroup>';
    }

    return $html;
};
$lineRow = static function (int|string $index, array $line) use ($accountOptions): string {
    return '<tr data-acc-vline>'
        . '<td data-label="হিসাব"><select class="form-select form-select-sm" name="lines[' . $index . '][account_id]" aria-label="হিসাব">' . $accountOptions((int) $line['account_id']) . '</select></td>'
        . '<td data-label="ডেবিট"><input class="form-control form-control-sm text-end" name="lines[' . $index . '][debit]" value="' . h((string) $line['debit']) . '" inputmode="decimal" data-acc-vdebit aria-label="ডেবিট"></td>'
        . '<td data-label="ক্রেডিট"><input class="form-control form-control-sm text-end" name="lines[' . $index . '][credit]" value="' . h((string) $line['credit']) . '" inputmode="decimal" data-acc-vcredit aria-label="ক্রেডিট"></td>'
        . '<td data-label="নোট"><input class="form-control form-control-sm" name="lines[' . $index . '][memo]" value="' . h((string) $line['memo']) . '" maxlength="160" aria-label="নোট"></td>'
        . '<td class="text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-acc-vremove aria-label="লাইন মুছুন"><i class="bi bi-x-lg"></i></button></td>'
        . '</tr>';
};

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1"><?= $voucher === null ? 'নতুন ভাউচার' : 'ভাউচার সংশোধন — খসড়া #' . h(bnDigits((string) $voucherId)) ?></h3>
            <p class="text-secondary mb-0">প্রতিটি লাইনে একটি হিসাব এবং ডেবিট অথবা ক্রেডিটে টাকা দিন। মোট ডেবিট ও মোট ক্রেডিট সমান হতে হবে। উদাহরণ — বিদ্যুৎ বিল নগদে পরিশোধ: "৫১২০ বিদ্যুৎ ও পানি" ডেবিট, "১১১০ নগদ তহবিল" ক্রেডিট।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <?php if (isset($flashError)): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="card" data-acc-voucher-form>
              <?= csrfField() ?>
              <?php if ($voucher !== null): ?><?= signedRecordField($voucherId, 'acc_voucher') ?><?php else: ?><input type="hidden" name="idempotency_key" value="<?= h((string) ($_POST['idempotency_key'] ?? PaymentService::newIdempotencyKey())) ?>"><?php endif; ?>
              <div class="card-body">
                <div class="row g-2 mb-3">
                  <div class="col-md-4">
                    <label class="form-label" for="vf-type">ধরন</label>
                    <select class="form-select" id="vf-type" name="type" required>
                      <?php foreach (VoucherService::TYPES as $key => $label): ?><option value="<?= h($key) ?>"<?= $form['type'] === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="vf-date">তারিখ</label>
                    <input class="form-control" type="date" id="vf-date" name="voucher_date" value="<?= h($form['voucher_date']) ?>" max="<?= h(accToday()->format('Y-m-d')) ?>" required>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label" for="vf-narration">বিবরণ</label>
                    <input class="form-control" id="vf-narration" name="narration" value="<?= h($form['narration']) ?>" required minlength="3" maxlength="255" placeholder="যেমন: অক্টোবর মাসের বিদ্যুৎ বিল (বিল নং …)">
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle acc-table-stack acc-voucher-lines">
                    <thead><tr><th style="min-width:16rem">হিসাব</th><th style="width:9rem" class="text-end">ডেবিট</th><th style="width:9rem" class="text-end">ক্রেডিট</th><th>নোট</th><th style="width:3rem"></th></tr></thead>
                    <tbody data-acc-vlines>
                      <?php foreach (array_values($formLines) as $index => $line): ?><?= $lineRow($index, $line) ?><?php endforeach; ?>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td><button class="btn btn-sm btn-outline-primary" type="button" data-acc-vadd><i class="bi bi-plus-lg"></i> লাইন যোগ</button></td>
                        <td class="text-end fw-semibold" data-acc-vtotal-debit>৳ ০.০০</td>
                        <td class="text-end fw-semibold" data-acc-vtotal-credit>৳ ০.০০</td>
                        <td colspan="2" data-acc-vstatus class="small"></td>
                      </tr>
                    </tfoot>
                  </table>
                  <template data-acc-vtemplate><?= $lineRow('__i__', ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => '']) ?></template>
                </div>
                <div class="mb-1">
                  <label class="form-label" for="vf-files">বিল / রসিদের কপি (ঐচ্ছিক)</label>
                  <input class="form-control" type="file" id="vf-files" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" multiple>
                  <div class="form-text">PDF, JPG, PNG বা WebP; প্রতিটি সর্বোচ্চ ৫ MB। ফাইল সুরক্ষিত ফোল্ডারে থাকে এবং মুছে ফেলা যায় না।</div>
                </div>
              </div>
              <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary" type="submit" name="after" value="draft"><i class="bi bi-save me-1"></i>খসড়া সংরক্ষণ</button>
                <button class="btn btn-primary" type="submit" name="after" value="submit"><i class="bi bi-send me-1"></i>সংরক্ষণ ও অনুমোদনের জন্য জমা</button>
                <a class="btn btn-link ms-auto" href="<?= $voucher === null ? 'vouchers.php' : h(signedRecordUrl('voucher.php', $voucherId, 'acc_voucher')) ?>">বাতিল</a>
              </div>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
