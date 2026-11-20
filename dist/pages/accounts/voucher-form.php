<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
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
$pageTitle = ($voucher === null ? 'বিশেষ এন্ট্রি' : 'বিশেষ এন্ট্রি সংশোধন') . ' | School Admin';

$kindLabels = [
    VoucherService::PAYMENT => 'খরচ',
    VoucherService::RECEIPT => 'আয়',
    VoucherService::CONTRA => 'টাকা এক জায়গা থেকে আরেক জায়গায়',
    VoucherService::JOURNAL => 'হিসাব মেলানো',
];
$form = ['type' => VoucherService::PAYMENT, 'voucher_date' => accToday()->format('Y-m-d'), 'narration' => ''];
$simple = ['account_in' => 0, 'account_out' => 0, 'amount' => '', 'note' => ''];
$replacedLines = [];

$readSimple = static function (array $lines): ?array {
    $debit = null;
    $credit = null;
    foreach ($lines as $line) {
        $in = Money::fromDb($line['debit']);
        $out = Money::fromDb($line['credit']);
        if ($in > 0 && $out === 0 && $debit === null) {
            $debit = ['account_id' => (int) $line['account_id'], 'amount' => $in, 'note' => (string) $line['memo']];
            continue;
        }
        if ($out > 0 && $in === 0 && $credit === null) {
            $credit = ['account_id' => (int) $line['account_id'], 'amount' => $out];
            continue;
        }

        return null;
    }
    if ($debit === null || $credit === null || $debit['amount'] !== $credit['amount'] || $debit['account_id'] === $credit['account_id']) {
        return null;
    }

    return [
        'account_in' => $debit['account_id'],
        'account_out' => $credit['account_id'],
        'amount' => Money::toInput($debit['amount']),
        'note' => $debit['note'],
    ];
};

if ($voucher !== null) {
    $form = ['type' => (string) $voucher['voucher_type'], 'voucher_date' => (string) $voucher['voucher_date'], 'narration' => (string) $voucher['narration']];
    $savedLines = $vouchers->lines($voucherId);
    $parsed = $readSimple($savedLines);
    if ($parsed !== null) {
        $simple = $parsed;
    } else {
        $replacedLines = $savedLines;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = [
        'type' => (string) ($_POST['type'] ?? ''),
        'voucher_date' => (string) ($_POST['voucher_date'] ?? ''),
        'narration' => (string) ($_POST['narration'] ?? ''),
    ];
    $simple = [
        'account_in' => (int) ($_POST['account_in'] ?? 0),
        'account_out' => (int) ($_POST['account_out'] ?? 0),
        'amount' => trim((string) ($_POST['amount'] ?? '')),
        'note' => trim((string) ($_POST['note'] ?? '')),
    ];
    $input = [
        'type' => $form['type'],
        'voucher_date' => $form['voucher_date'],
        'narration' => $form['narration'],
        'lines' => [
            ['account_id' => $simple['account_in'], 'debit' => $simple['amount'], 'credit' => '', 'memo' => $simple['note']],
            ['account_id' => $simple['account_out'], 'debit' => '', 'credit' => $simple['amount'], 'memo' => ''],
        ],
    ];
    try {
        if ($simple['account_in'] < 1 || $simple['account_out'] < 1) {
            throw new RuntimeException('দুইটি হিসাবই বেছে নিন।');
        }
        if ($simple['account_in'] === $simple['account_out']) {
            throw new RuntimeException('দুই জায়গায় একই হিসাব দেওয়া যায় না।');
        }
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
        $submitted = ($_POST['after'] ?? '') === 'submit';
        $message = $result['duplicate'] ? 'এই ভাউচার আগেই সংরক্ষিত হয়েছে।' : ($submitted ? ($access->can(Capability::VOUCHER_APPROVE) ? 'ভাউচার খাতায় উঠে গেছে।' : 'ভাউচার অনুমোদনের জন্য জমা হয়েছে।') : 'খসড়া সংরক্ষিত হয়েছে।');
        if ($uploaded > 0) {
            $message .= ' সংযুক্তি: ' . bnDigits((string) $uploaded) . 'টি।';
        }
        redirectWithMessage(signedRecordUrl('voucher.php', $savedId, 'acc_voucher'), $result['duplicate'] ? 'info' : 'success', $message);
    } catch (Throwable $exception) {
        $flashError = userFacingError($exception);
    }
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
    $html = '<option value="">বেছে নিন</option>';
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
$sideCopy = VoucherService::SIDE_COPY[$form['type']] ?? VoucherService::SIDE_COPY[VoucherService::PAYMENT];

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1"><?= $voucher === null ? 'বিশেষ এন্ট্রি' : 'বিশেষ এন্ট্রি সংশোধন — খসড়া #' . h(bnDigits((string) $voucherId)) ?></h3>
            <p class="text-secondary mb-0" data-acc-vhint><?= h($sideCopy['hint']) ?></p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <?php if (isset($flashError)): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
            <?php if ($replacedLines !== []): ?>
              <div class="alert alert-warning">
                এই খসড়ায় এক টাকার বেশি লাইন আছে। নিচে নতুন করে লিখে সংরক্ষণ করলে আগের লাইন সরে যাবে।
                <ul class="mb-0 mt-2">
                  <?php foreach ($replacedLines as $line): ?>
                    <li><?= h((string) $line['name_bn']) ?> — <?= h(Money::format(max(Money::fromDb($line['debit']), Money::fromDb($line['credit'])))) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="card" data-acc-simple-voucher>
              <?= csrfField() ?>
              <script type="application/json" data-acc-vsides><?= json_encode(VoucherService::SIDE_COPY, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
              <?php if ($voucher !== null): ?><?= signedRecordField($voucherId, 'acc_voucher') ?><?php else: ?><input type="hidden" name="idempotency_key" value="<?= h((string) ($_POST['idempotency_key'] ?? PaymentService::newIdempotencyKey())) ?>"><?php endif; ?>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="vf-type">কী হচ্ছে</label>
                    <select class="form-select" id="vf-type" name="type" required>
                      <?php foreach ($kindLabels as $key => $label): ?><option value="<?= h($key) ?>"<?= $form['type'] === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="vf-date">তারিখ</label>
                    <input class="form-control" type="date" id="vf-date" name="voucher_date" value="<?= h($form['voucher_date']) ?>" max="<?= h(accToday()->format('Y-m-d')) ?>" required>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label" for="vf-narration">বিবরণ</label>
                    <input class="form-control" id="vf-narration" name="narration" value="<?= h($form['narration']) ?>" required minlength="3" maxlength="255" placeholder="যেমন: অক্টোবর মাসের বিদ্যুৎ বিল">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="vf-in" data-acc-side-label="in"><?= h($sideCopy['in']) ?></label>
                    <select class="form-select" id="vf-in" name="account_in" required><?= $accountOptions($simple['account_in']) ?></select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="vf-out" data-acc-side-label="out"><?= h($sideCopy['out']) ?></label>
                    <select class="form-select" id="vf-out" name="account_out" required><?= $accountOptions($simple['account_out']) ?></select>
                  </div>
                  <div class="col-12"><p class="text-danger small mb-0" data-acc-same hidden>দুই জায়গায় একই হিসাব দেওয়া যায় না।</p></div>
                  <div class="col-md-4">
                    <label class="form-label" for="vf-amount">টাকা</label>
                    <input class="form-control" id="vf-amount" name="amount" value="<?= h($simple['amount']) ?>" inputmode="decimal" required placeholder="০">
                  </div>
                  <div class="col-md-8">
                    <label class="form-label" for="vf-note">নোট <span class="text-secondary small">(ঐচ্ছিক)</span></label>
                    <input class="form-control" id="vf-note" name="note" value="<?= h($simple['note']) ?>" maxlength="160">
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="vf-files">বিল / রসিদের কপি <span class="text-secondary small">(ঐচ্ছিক)</span></label>
                    <input class="form-control" type="file" id="vf-files" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" multiple>
                    <div class="form-text">PDF, JPG, PNG বা WebP; প্রতিটি সর্বোচ্চ ৫ MB।</div>
                  </div>
                </div>
              </div>
              <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary" type="submit" name="after" value="draft"><i class="bi bi-save me-1"></i>খসড়া সংরক্ষণ</button>
                <button class="btn btn-primary" type="submit" name="after" value="submit"><i class="bi bi-send me-1"></i><?= $access->can(Capability::VOUCHER_APPROVE) ? 'সংরক্ষণ ও খাতায় তুলুন' : 'সংরক্ষণ ও অনুমোদনের জন্য জমা' ?></button>
                <a class="btn btn-link ms-auto" href="<?= $voucher === null ? 'vouchers.php' : h(signedRecordUrl('voucher.php', $voucherId, 'acc_voucher')) ?>">বাতিল</a>
              </div>
            </form>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
