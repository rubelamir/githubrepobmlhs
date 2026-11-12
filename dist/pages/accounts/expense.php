<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\SimpleBooks;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::VOUCHER_CREATE);
$today = accToday()->format('Y-m-d');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $date = (string) ($_POST['entry_date'] ?? '');
    $amount = (string) ($_POST['amount'] ?? '');
    $narration = (string) ($_POST['narration'] ?? '');
    $key = (string) ($_POST['idempotency_key'] ?? '');
    $moneyId = (int) ($_POST['money_account'] ?? 0);
    try {
        $result = match ($action) {
            'spend' => accBooks()->spend($access, accAudit(), $date, (int) ($_POST['expense_account'] ?? 0), $moneyId, $amount, $narration, $key),
            'income' => accBooks()->otherIncome($access, accAudit(), $date, (int) ($_POST['income_account'] ?? 0), $moneyId, $amount, $narration, $key),
            'advance' => accBooks()->staffAdvance($access, accAudit(), $date, $moneyId, $amount, $narration, $key),
            'adjust' => accBooks()->adjustAdvance($access, accAudit(), $date, $amount, $narration, $key),
            default => throw new RuntimeException('অজানা অনুরোধ।'),
        };
        $url = signedRecordUrl('voucher.php', $result['voucher_id'], 'acc_voucher');
        $message = $result['duplicate']
            ? 'এই এন্ট্রি আগেই জমা হয়েছে।'
            : 'জমা হয়েছে। প্রধান শিক্ষক যাচাই করলে খাতায় উঠবে।';
        if (!$result['duplicate'] && isset($_FILES['attachments'])) {
            try {
                accAttachments()->storeUploads($access, accAudit(), 'voucher', $result['voucher_id'], $_FILES['attachments']);
            } catch (Throwable $exception) {
                redirectWithMessage($url, 'warning', $message . ' ফাইল যোগ হয়নি: ' . userFacingError($exception));
            }
        }
        redirectWithMessage($url, 'success', $message);
    } catch (Throwable $exception) {
        redirectWithMessage('expense.php', 'danger', userFacingError($exception));
    }
}

$expenses = array_values(array_filter(
    accChart()->postable(),
    static fn (array $row): bool => (string) $row['account_type'] === 'expense'
));
$incomes = array_values(array_filter(
    accChart()->postable(),
    static fn (array $row): bool => (string) $row['account_type'] === 'income' && !in_array((string) $row['code'], SimpleBooks::FEE_INCOME_CODES, true)
));
$purses = accChart()->postable(ChartOfAccounts::SPENDABLE_KINDS);
$advance = accChart()->balance(accChart()->idOf(SimpleBooks::STAFF_ADVANCE));

$basePath = '../../';
$pageTitle = 'আয় ও খরচ | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
include __DIR__ . '/../../includes/layout-head.php';

$moneyOptions = static function (array $rows): string {
    $html = '';
    foreach ($rows as $row) {
        $html .= '<option value="' . (int) $row['id'] . '">' . h((string) $row['name_bn']) . '</option>';
    }

    return $html;
};
$namedOptions = static function (array $rows): string {
    $html = '';
    foreach ($rows as $row) {
        $html .= '<option value="' . (int) $row['id'] . '">' . h((string) $row['name_bn']) . '</option>';
    }

    return $html;
};
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">আয় ও খরচ</h3>
            <p class="text-secondary mb-0">ফি আদায় আলাদা পাতায়। এখানে অফিসের খরচ, দান বা অনুদান, আর কর্মচারী অগ্রিম। জমা দিলে প্রধান শিক্ষক যাচাই করেন। ভুল এন্ট্রি মুছে ফেলা হয় না; কারণ লিখে বাতিল হয়।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="row g-3">
              <div class="col-lg-6">
                <form method="post" enctype="multipart/form-data" class="card h-100" data-acc-confirm="এই খরচ যাচাইয়ের জন্য জমা দেবেন?">
                  <div class="card-header"><h3 class="card-title mb-0">খরচ যোগ</h3></div>
                  <div class="card-body">
                    <?= csrfField() ?><input type="hidden" name="action" value="spend">
                    <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                    <div class="mb-2"><label class="form-label" for="spend-date">তারিখ</label><input class="form-control" type="date" id="spend-date" name="entry_date" value="<?= h($today) ?>" required></div>
                    <div class="mb-2"><label class="form-label" for="spend-head">কীসের খরচ</label><select class="form-select" id="spend-head" name="expense_account" required><?= $namedOptions($expenses) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="spend-from">টাকা গেছে কোথা থেকে</label><select class="form-select" id="spend-from" name="money_account" required><?= $moneyOptions($purses) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="spend-amount">টাকা</label><input class="form-control" id="spend-amount" name="amount" inputmode="decimal" required placeholder="০"></div>
                    <div class="mb-2"><label class="form-label" for="spend-note">বিবরণ</label><input class="form-control" id="spend-note" name="narration" maxlength="255" required placeholder="যেমন: মার্চের বিদ্যুৎ বিল"></div>
                    <div class="mb-0"><label class="form-label" for="spend-file">বিলের ছবি বা PDF (ঐচ্ছিক)</label><input class="form-control" id="spend-file" type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
                  </div>
                  <div class="card-footer"><button class="btn btn-primary" type="submit">খরচ জমা দিন</button></div>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" enctype="multipart/form-data" class="card h-100" data-acc-confirm="এই আয় যাচাইয়ের জন্য জমা দেবেন?">
                  <div class="card-header"><h3 class="card-title mb-0">অন্য আয়</h3></div>
                  <div class="card-body">
                    <?= csrfField() ?><input type="hidden" name="action" value="income">
                    <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                    <p class="small text-secondary">শিক্ষার্থীর ফি এখানে লিখবেন না। সেটি ফি আদায় থেকে রসিদ হয়।</p>
                    <div class="mb-2"><label class="form-label" for="in-date">তারিখ</label><input class="form-control" type="date" id="in-date" name="entry_date" value="<?= h($today) ?>" required></div>
                    <div class="mb-2"><label class="form-label" for="in-head">আয়ের খাত</label><select class="form-select" id="in-head" name="income_account" required><?= $namedOptions($incomes) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="in-to">টাকা এসেছে কোথায়</label><select class="form-select" id="in-to" name="money_account" required><?= $moneyOptions($purses) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="in-amount">টাকা</label><input class="form-control" id="in-amount" name="amount" inputmode="decimal" required placeholder="০"></div>
                    <div class="mb-2"><label class="form-label" for="in-note">বিবরণ</label><input class="form-control" id="in-note" name="narration" maxlength="255" required placeholder="যেমন: স্থানীয় দান"></div>
                    <div class="mb-0"><label class="form-label" for="in-file">কাগজ (ঐচ্ছিক)</label><input class="form-control" id="in-file" type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
                  </div>
                  <div class="card-footer"><button class="btn btn-primary" type="submit">আয় জমা দিন</button></div>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" class="card h-100" data-acc-confirm="এই অগ্রিম যাচাইয়ের জন্য জমা দেবেন?">
                  <div class="card-header d-flex justify-content-between"><h3 class="card-title mb-0">কর্মচারী অগ্রিম</h3><span class="small text-secondary">বকেয়া <?= h(\School\Accounting\Support\Money::format($advance)) ?></span></div>
                  <div class="card-body">
                    <?= csrfField() ?><input type="hidden" name="action" value="advance">
                    <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                    <div class="mb-2"><label class="form-label" for="adv-date">তারিখ</label><input class="form-control" type="date" id="adv-date" name="entry_date" value="<?= h($today) ?>" required></div>
                    <div class="mb-2"><label class="form-label" for="adv-from">টাকা গেছে কোথা থেকে</label><select class="form-select" id="adv-from" name="money_account" required><?= $moneyOptions($purses) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="adv-amount">টাকা</label><input class="form-control" id="adv-amount" name="amount" inputmode="decimal" required placeholder="০"></div>
                    <div class="mb-0"><label class="form-label" for="adv-note">কার অগ্রিম</label><input class="form-control" id="adv-note" name="narration" maxlength="255" required placeholder="নাম লিখুন"></div>
                  </div>
                  <div class="card-footer"><button class="btn btn-outline-primary" type="submit">অগ্রিম জমা দিন</button></div>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" class="card h-100" data-acc-confirm="অগ্রিম বেতনের সাথে মেলাতে জমা দেবেন? নগদ টাকা আর নড়ে না।">
                  <div class="card-header"><h3 class="card-title mb-0">অগ্রিম সমন্বয়</h3></div>
                  <div class="card-body">
                    <?= csrfField() ?><input type="hidden" name="action" value="adjust">
                    <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                    <p class="small text-secondary">বেতন দেওয়ার সময় অগ্রিম কেটে নিলে এখানে লিখুন। টাকা আবার বের হয় না।</p>
                    <div class="mb-2"><label class="form-label" for="adj-date">তারিখ</label><input class="form-control" type="date" id="adj-date" name="entry_date" value="<?= h($today) ?>" required></div>
                    <div class="mb-2"><label class="form-label" for="adj-amount">যে টাকা কাটা হলো</label><input class="form-control" id="adj-amount" name="amount" inputmode="decimal" required placeholder="০"></div>
                    <div class="mb-0"><label class="form-label" for="adj-note">কার বেতন থেকে</label><input class="form-control" id="adj-note" name="narration" maxlength="255" required placeholder="নাম লিখুন"></div>
                  </div>
                  <div class="card-footer"><button class="btn btn-outline-primary" type="submit">সমন্বয় জমা দিন</button></div>
                </form>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
