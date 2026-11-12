<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::REPORTS_VIEW_ALL, Capability::VOUCHER_CREATE]);
$canWrite = $access->can(Capability::VOUCHER_CREATE);
$today = accToday()->format('Y-m-d');
$purses = accChart()->postable(ChartOfAccounts::SPENDABLE_KINDS);
if ($purses === []) {
    redirectWithMessage('dashboard.php', 'danger', 'নগদ বা ব্যাংক হিসাব পাওয়া যায়নি।');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canWrite) {
        redirectWithMessage('books.php', 'danger', 'এন্ট্রি হিসাব রক্ষক করেন।');
    }
    $action = (string) ($_POST['action'] ?? '');
    $date = (string) ($_POST['entry_date'] ?? '');
    $amount = (string) ($_POST['amount'] ?? '');
    $key = (string) ($_POST['idempotency_key'] ?? '');
    try {
        if ($action === 'transfer') {
            $result = accBooks()->transfer(
                $access,
                accAudit(),
                $date,
                (int) ($_POST['from_account'] ?? 0),
                (int) ($_POST['to_account'] ?? 0),
                $amount,
                (string) ($_POST['narration'] ?? ''),
                $key
            );
            redirectWithMessage(
                signedRecordUrl('voucher.php', $result['voucher_id'], 'acc_voucher'),
                'success',
                $result['duplicate'] ? 'এই স্থানান্তর আগেই জমা হয়েছে।' : 'স্থানান্তর জমা হয়েছে। প্রধান শিক্ষক যাচাই করলে খাতায় উঠবে।'
            );
        }
        if ($action === 'opening') {
            $accountId = (int) ($_POST['money_account'] ?? 0);
            accBooks()->openingBalance($access, accAudit(), $date, $accountId, $amount, $key);
            redirectWithMessage('books.php?account=' . $accountId, 'success', 'প্রারম্ভিক স্থিতি খাতায় বসেছে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('books.php', 'danger', userFacingError($exception));
    }
}

$selected = (int) ($_GET['account'] ?? 0);
$ids = array_map(static fn (array $row): int => (int) $row['id'], $purses);
if (!in_array($selected, $ids, true)) {
    $selected = (int) $purses[0]['id'];
    foreach ($purses as $purse) {
        if ((string) $purse['code'] === ChartOfAccounts::CASH) {
            $selected = (int) $purse['id'];
            break;
        }
    }
}
$from = (string) ($_GET['from'] ?? accToday()->format('Y-m-01'));
$to = (string) ($_GET['to'] ?? accToday()->format('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $from = accToday()->format('Y-m-01');
    $to = accToday()->format('Y-m-t');
}
$book = accVouchers()->ledger($selected, $from, $to);
$selectedName = '';
foreach ($purses as $purse) {
    if ((int) $purse['id'] === $selected) {
        $selectedName = (string) $purse['name_bn'];
    }
}

$basePath = '../../';
$pageTitle = 'ক্যাশ ও ব্যাংক | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
include __DIR__ . '/../../includes/layout-head.php';

$options = static function (array $rows, int $skip = 0): string {
    $html = '';
    foreach ($rows as $row) {
        if ((int) $row['id'] === $skip) {
            continue;
        }
        $html .= '<option value="' . (int) $row['id'] . '">' . h((string) $row['name_bn']) . '</option>';
    }

    return $html;
};
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid d-flex flex-wrap justify-content-between gap-2">
            <div>
              <h3 class="mb-1">ক্যাশ ও ব্যাংক</h3>
              <p class="text-secondary mb-0">নগদ, বিকাশ, নগদ (Nagad) ও ব্যাংক আলাদা। পুরোনো পরীক্ষামূলক লেনদেন সরানো হয়েছে, তাই শুরুর টাকা একবার প্রারম্ভিক স্থিতি হিসেবে দিন।</p>
            </div>
            <a class="btn btn-outline-secondary align-self-start" href="bank.php">ব্যাংক স্টেটমেন্ট মিলান</a>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="row g-3 mb-3">
              <?php foreach ($purses as $purse): ?>
                <?php $balance = accChart()->balance((int) $purse['id']); ?>
                <div class="col-6 col-lg-3">
                  <a class="acc-stat d-block text-decoration-none text-body<?= (int) $purse['id'] === $selected ? ' border border-primary' : '' ?>" href="books.php?account=<?= (int) $purse['id'] ?>&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>">
                    <div class="acc-stat-label"><?= h((string) $purse['name_bn']) ?></div>
                    <div class="acc-stat-value"><?= h(Money::format($balance)) ?></div>
                  </a>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="card mb-3">
              <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                <h3 class="card-title mb-0"><?= h($selectedName) ?></h3>
                <form method="get" class="d-flex flex-wrap gap-2">
                  <input type="hidden" name="account" value="<?= $selected ?>">
                  <input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>" aria-label="শুরু">
                  <input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>" aria-label="শেষ">
                  <button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button>
                </form>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>তারিখ</th><th>বিবরণ</th><th class="text-end">টাকা এসেছে</th><th class="text-end">টাকা গেছে</th><th class="text-end">স্থিতি</th></tr></thead>
                  <tbody>
                    <tr class="text-secondary"><td colspan="4">শুরুর স্থিতি</td><td class="text-end"><?= h(Money::format($book['opening'])) ?></td></tr>
                    <?php if ($book['rows'] === []): ?>
                      <tr><td colspan="5" class="text-secondary">এই সময়ে কোনো লেনদেন নেই।</td></tr>
                    <?php endif; ?>
                    <?php foreach ($book['rows'] as $row): ?>
                      <tr>
                        <td class="text-nowrap"><?= h(bnDigits((string) $row['voucher_date'])) ?></td>
                        <td><a href="<?= h(signedRecordUrl('voucher.php', (int) $row['voucher_id'], 'acc_voucher')) ?>"><?= h((string) $row['narration']) ?></a></td>
                        <td class="text-end text-nowrap"><?= Money::fromDb($row['debit']) > 0 ? h(Money::format(Money::fromDb($row['debit']))) : '' ?></td>
                        <td class="text-end text-nowrap"><?= Money::fromDb($row['credit']) > 0 ? h(Money::format(Money::fromDb($row['credit']))) : '' ?></td>
                        <td class="text-end text-nowrap"><?= h(Money::format((int) $row['running'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot><tr class="fw-semibold"><td colspan="4">শেষ স্থিতি</td><td class="text-end"><?= h(Money::format($book['closing'])) ?></td></tr></tfoot>
                </table>
              </div>
            </div>

            <?php if ($canWrite): ?>
            <div class="row g-3">
              <div class="col-lg-6">
                <form method="post" class="card" data-acc-confirm="টাকা এক জায়গা থেকে অন্য জায়গায় সরাবেন? যাচাইয়ের পর খাতায় উঠবে।">
                  <div class="card-header"><h3 class="card-title mb-0">টাকা সরান</h3></div>
                  <div class="card-body">
                    <?= csrfField() ?><input type="hidden" name="action" value="transfer">
                    <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                    <div class="mb-2"><label class="form-label" for="tr-date">তারিখ</label><input class="form-control" type="date" id="tr-date" name="entry_date" value="<?= h($today) ?>" required></div>
                    <div class="mb-2"><label class="form-label" for="tr-from">কোথা থেকে</label><select class="form-select" id="tr-from" name="from_account" required><?= $options($purses) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="tr-to">কোথায়</label><select class="form-select" id="tr-to" name="to_account" required><?= $options($purses) ?></select></div>
                    <div class="mb-2"><label class="form-label" for="tr-amount">টাকা</label><input class="form-control" id="tr-amount" name="amount" inputmode="decimal" required placeholder="০"></div>
                    <div class="mb-0"><label class="form-label" for="tr-note">বিবরণ</label><input class="form-control" id="tr-note" name="narration" maxlength="255" required placeholder="যেমন: নগদ ব্যাংকে জমা"></div>
                  </div>
                  <div class="card-footer"><button class="btn btn-primary" type="submit">সরানোর জন্য জমা দিন</button></div>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" class="card" data-acc-confirm="প্রারম্ভিক স্থিতি সাথে সাথে খাতায় বসবে। এক হিসাবে একবার।">
                  <div class="card-header"><h3 class="card-title mb-0">প্রারম্ভিক স্থিতি</h3></div>
                  <div class="card-body">
                    <?= csrfField() ?><input type="hidden" name="action" value="opening">
                    <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                    <p class="small text-secondary">আজকের হাতে থাকা টাকা। পরে আদায় বা খরচ এর সাথে যোগ করবেন না।</p>
                    <div class="mb-2"><label class="form-label" for="op-date">তারিখ</label><input class="form-control" type="date" id="op-date" name="entry_date" value="<?= h($today) ?>" required></div>
                    <div class="mb-2"><label class="form-label" for="op-account">কোন হিসাব</label><select class="form-select" id="op-account" name="money_account" required><?= $options($purses) ?></select></div>
                    <div class="mb-0"><label class="form-label" for="op-amount">টাকা</label><input class="form-control" id="op-amount" name="amount" inputmode="decimal" required placeholder="০"></div>
                  </div>
                  <div class="card-footer"><button class="btn btn-outline-primary" type="submit">স্থিতি বসান</button></div>
                </form>
              </div>
            </div>
            <?php else: ?>
              <p class="text-secondary">প্রারম্ভিক স্থিতি ও টাকা সরানো হিসাব রক্ষক করেন। আপনি স্থিতি দেখে যাচাই করতে পারেন।</p>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
