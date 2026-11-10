<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Ledger\BankService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$canRecord = $access->can(Capability::BANK_RECONCILE);
$canVerify = $access->can(Capability::VOUCHER_APPROVE);
if (!$canRecord && !$canVerify) {
    redirectWithMessage('dashboard.php', 'danger', 'এই পাতার অনুমতি আপনার নেই।');
}
$bank = accBank();
$chart = accChart();
$basePath = '../../';
$pageTitle = 'ব্যাংক জমা | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$bankAccounts = $chart->bankAccounts(false);
$validDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
$reconAccount = (int) ($_GET['account'] ?? ($bankAccounts[0]['id'] ?? 0));
$from = $validDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : accToday()->modify('first day of this month')->format('Y-m-d');
$to = $validDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : accToday()->format('Y-m-d');
$reconQuery = 'account=' . $reconAccount . '&from=' . $from . '&to=' . $to;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'deposit') {
            accAttachments()->assertValid($_FILES['slip'] ?? []);
            $result = $bank->submitDeposit($access, accAudit(), (string) ($_POST['idempotency_key'] ?? ''), $_POST);
            $deposit = $bank->deposit($result['deposit_id']);
            $message = $result['duplicate'] ? 'এই জমা আগেই রেকর্ড হয়েছে (' . $deposit['deposit_no'] . ')।' : 'ব্যাংক জমা ' . $deposit['deposit_no'] . ' রেকর্ড হয়েছে; প্রধান শিক্ষক স্লিপ দেখে যাচাই করবেন।';
            if (!$result['duplicate'] && isset($_FILES['slip'])) {
                try {
                    accAttachments()->storeUploads($access, accAudit(), 'deposit', $result['deposit_id'], $_FILES['slip']);
                } catch (RuntimeException $exception) {
                    redirectWithMessage('bank.php', 'warning', $message . ' কিন্তু স্লিপের ছবি যোগ হয়নি: ' . $exception->getMessage());
                }
            }
            redirectWithMessage('bank.php', $result['duplicate'] ? 'info' : 'success', $message);
        }
        if ($action === 'verify' || $action === 'reject') {
            $bank->decideDeposit($access, accAudit(), postedRecordId('acc_deposit'), $action === 'verify', (string) ($_POST['remark'] ?? ''));
            redirectWithMessage('bank.php', 'success', $action === 'verify' ? 'জমা যাচাই হয়েছে; নগদ থেকে ব্যাংকে কন্ট্রা ভাউচার পোস্ট হয়েছে।' : 'জমা ফেরত দেওয়া হয়েছে।');
        }
        if ($action === 'slip') {
            $depositId = postedRecordId('acc_deposit');
            $count = accAttachments()->storeUploads($access, accAudit(), 'deposit', $depositId, $_FILES['slip'] ?? []);
            redirectWithMessage('bank.php', $count > 0 ? 'success' : 'warning', $count > 0 ? 'স্লিপ যোগ হয়েছে।' : 'কোনো ফাইল নির্বাচন করা হয়নি।');
        }
        if ($action === 'reconcile' || $action === 'unreconcile') {
            $lineIds = array_map('intval', is_array($_POST['lines'] ?? null) ? $_POST['lines'] : []);
            $count = $bank->reconcile($access, accAudit(), (int) ($_POST['account'] ?? 0), $lineIds, (string) ($_POST['statement_date'] ?? ''), $action === 'reconcile');
            redirectWithMessage('bank.php?' . $reconQuery . '#reconcile', 'success', bnDigits((string) $count) . 'টি লেনদেন ' . ($action === 'reconcile' ? 'মিলানো হয়েছে।' : 'অমিলানো করা হয়েছে।'));
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage(str_starts_with($action, 'reconcile') || $action === 'unreconcile' ? 'bank.php?' . $reconQuery . '#reconcile' : 'bank.php', 'danger', userFacingError($exception));
    }
}

$status = (string) ($_GET['status'] ?? '');
$deposits = $bank->deposits($status);
$available = $bank->availableCash();
$activeBanks = array_values(array_filter($bankAccounts, static fn (array $row): bool => (int) $row['is_active'] === 1));
$book = null;
if ($reconAccount > 0 && $canRecord) {
    try {
        $book = $bank->bankBook($reconAccount, $from, $to);
    } catch (RuntimeException) {
        $book = null;
    }
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">ব্যাংক জমা</h3>
            <p class="text-secondary mb-0">অফিসের নগদ টাকা ব্যাংকে জমা দিয়ে স্লিপসহ রেকর্ড করুন; প্রধান শিক্ষক যাচাই করলে হিসাবে নগদ কমে ব্যাংক বাড়বে। ব্যাংক স্টেটমেন্টের সাথে প্রতিটি লেনদেন মিলিয়ে টিক দিন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">অফিসে নগদ (জমাযোগ্য)</div><div class="acc-stat-value"><?= h(Money::format($available)) ?></div></div></div>
              <?php foreach ($activeBanks as $account): ?>
                <div class="col-6 col-lg-3"><a class="acc-stat d-block text-decoration-none text-body" href="ledger.php?account=<?= (int) $account['id'] ?>"><div class="acc-stat-label"><?= h($account['code'] . ' ' . $account['name_bn']) ?></div><div class="acc-stat-value"><?= h(Money::format($chart->balance((int) $account['id']))) ?></div></a></div>
              <?php endforeach; ?>
            </div>

            <?php if ($activeBanks === []): ?>
              <div class="alert alert-warning">এখনো কোনো ব্যাংক হিসাব খোলা হয়নি। প্রধান শিক্ষক "হিসাবের খাত" পাতায় "১১৫০ — ব্যাংক হিসাবসমূহ" গ্রুপের ভেতরে ব্যাংক হিসাব (যেমন কোড ১১৫১) যোগ করবেন।</div>
            <?php elseif ($canRecord): ?>
              <form method="post" enctype="multipart/form-data" class="card mb-3" data-acc-confirm="ব্যাংক জমার তথ্য ঠিক আছে? জমা রেকর্ড করবেন?">
                <?= csrfField() ?><input type="hidden" name="action" value="deposit">
                <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                <div class="card-header"><h3 class="card-title mb-0">নতুন ব্যাংক জমা</h3></div>
                <div class="card-body">
                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label" for="dp-bank">ব্যাংক হিসাব</label>
                      <select class="form-select" id="dp-bank" name="bank_account_id" required>
                        <?php foreach ($activeBanks as $account): ?><option value="<?= (int) $account['id'] ?>"><?= h($account['code'] . ' — ' . $account['name_bn']) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-6 col-md-2"><label class="form-label" for="dp-date">জমার তারিখ</label><input class="form-control" type="date" id="dp-date" name="deposit_date" value="<?= h(accToday()->format('Y-m-d')) ?>" max="<?= h(accToday()->format('Y-m-d')) ?>" required></div>
                    <div class="col-6 col-md-2"><label class="form-label" for="dp-amount">টাকা</label><input class="form-control" id="dp-amount" name="amount" inputmode="decimal" required></div>
                    <div class="col-md-4"><label class="form-label" for="dp-slip">স্লিপ নং</label><input class="form-control" id="dp-slip" name="slip_no" maxlength="60" required></div>
                    <div class="col-md-6"><label class="form-label" for="dp-file">স্লিপের ছবি/PDF</label><input class="form-control" type="file" id="dp-file" name="slip[]" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
                    <div class="col-md-6"><label class="form-label" for="dp-note">মন্তব্য (ঐচ্ছিক)</label><input class="form-control" id="dp-note" name="note" maxlength="255"></div>
                  </div>
                </div>
                <div class="card-footer"><button class="btn btn-primary" type="submit"><i class="bi bi-bank me-1"></i>জমা রেকর্ড করুন</button></div>
              </form>
            <?php endif; ?>

            <div class="card mb-3">
              <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto">ব্যাংক জমার তালিকা</h3>
                <form method="get" class="d-flex gap-1">
                  <select class="form-select form-select-sm" name="status" aria-label="অবস্থা">
                    <option value="">সব</option>
                    <?php foreach (BankService::STATUS_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button>
                </form>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 acc-table-stack">
                    <thead><tr><th>নং</th><th>তারিখ</th><th>ব্যাংক</th><th>স্লিপ</th><th class="text-end">টাকা</th><th>অবস্থা</th><th></th></tr></thead>
                    <tbody>
                      <?php if ($deposits === []): ?><tr><td colspan="7" class="text-secondary">কোনো ব্যাংক জমা নেই।</td></tr><?php endif; ?>
                      <?php foreach ($deposits as $deposit): ?>
                        <?php $depositId = (int) $deposit['id']; $files = accAttachments()->forOwner('deposit', $depositId); ?>
                        <tr>
                          <td data-label="নং"><code><?= h((string) $deposit['deposit_no']) ?></code><div class="small text-secondary"><?= h((string) $deposit['submitted_by_name']) ?></div></td>
                          <td data-label="তারিখ" class="text-nowrap"><?= h(bnDigits((string) $deposit['deposit_date'])) ?></td>
                          <td data-label="ব্যাংক"><?= h((string) $deposit['bank_name']) ?></td>
                          <td data-label="স্লিপ"><?= h((string) $deposit['slip_no']) ?>
                            <?php foreach ($files as $file): ?> <a href="<?= h(signedRecordUrl('attachment.php', (int) $file['id'], 'acc_attachment')) ?>" target="_blank" rel="noopener" title="<?= h((string) $file['original_name']) ?>"><i class="bi bi-paperclip"></i></a><?php endforeach; ?>
                          </td>
                          <td class="text-end text-nowrap" data-label="টাকা"><?= h(Money::format(Money::fromDb($deposit['amount']))) ?></td>
                          <td data-label="অবস্থা"><span class="badge <?= h(BankService::STATUS_BADGES[$deposit['status']] ?? 'text-bg-light') ?>"><?= h(BankService::STATUS_LABELS[$deposit['status']] ?? (string) $deposit['status']) ?></span>
                            <?php if ($deposit['voucher_no']): ?><a class="small" href="<?= h(signedRecordUrl('voucher.php', (int) $deposit['voucher_id'], 'acc_voucher')) ?>"><?= h((string) $deposit['voucher_no']) ?></a><?php endif; ?>
                            <?php if ((string) $deposit['remark'] !== ''): ?><div class="small text-secondary"><?= h((string) $deposit['remark']) ?></div><?php endif; ?>
                          </td>
                          <td class="text-end" data-label="">
                            <?php if ($deposit['status'] === BankService::SUBMITTED): ?>
                              <?php if ($canVerify): ?>
                                <form method="post" class="d-inline" data-acc-confirm="স্লিপ ও টাকা মিলিয়েছেন? জমাটি যাচাই করবেন?" data-acc-confirm-variant="success"><?= csrfField() ?><input type="hidden" name="action" value="verify"><?= signedRecordField($depositId, 'acc_deposit') ?><button class="btn btn-sm btn-success" type="submit">যাচাই</button></form>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#dp-reject-<?= $depositId ?>">ফেরত</button>
                              <?php endif; ?>
                              <?php if ($canRecord && $files === []): ?>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#dp-slip-<?= $depositId ?>"><i class="bi bi-paperclip"></i></button>
                              <?php endif; ?>
                            <?php endif; ?>
                          </td>
                        </tr>
                        <?php if ($deposit['status'] === BankService::SUBMITTED): ?>
                          <tr class="collapse" id="dp-reject-<?= $depositId ?>"><td colspan="7">
                            <form method="post" class="d-flex gap-1" data-acc-confirm="জমাটি ফেরত দেবেন?" data-acc-confirm-variant="danger"><?= csrfField() ?><input type="hidden" name="action" value="reject"><?= signedRecordField($depositId, 'acc_deposit') ?>
                              <input class="form-control form-control-sm" name="remark" required minlength="3" maxlength="255" placeholder="ফেরতের কারণ" aria-label="ফেরতের কারণ"><button class="btn btn-sm btn-danger" type="submit">ফেরত দিন</button></form>
                          </td></tr>
                          <tr class="collapse" id="dp-slip-<?= $depositId ?>"><td colspan="7">
                            <form method="post" enctype="multipart/form-data" class="d-flex gap-1"><?= csrfField() ?><input type="hidden" name="action" value="slip"><?= signedRecordField($depositId, 'acc_deposit') ?>
                              <input class="form-control form-control-sm" type="file" name="slip[]" accept=".pdf,.jpg,.jpeg,.png,.webp" required aria-label="স্লিপ"><button class="btn btn-sm btn-primary" type="submit">যোগ করুন</button></form>
                          </td></tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <?php if ($canRecord && $bankAccounts !== []): ?>
              <div class="card" id="reconcile">
                <div class="card-header"><h3 class="card-title mb-0">ব্যাংক মিলকরণ (রিকনসিলিয়েশন)</h3></div>
                <div class="card-body border-bottom">
                  <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label small mb-1" for="rc-account">ব্যাংক হিসাব</label>
                      <select class="form-select form-select-sm" id="rc-account" name="account"><?php foreach ($bankAccounts as $account): ?><option value="<?= (int) $account['id'] ?>"<?= (int) $account['id'] === $reconAccount ? ' selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name_bn']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="rc-from">শুরু</label><input class="form-control form-control-sm" type="date" id="rc-from" name="from" value="<?= h($from) ?>"></div>
                    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="rc-to">শেষ</label><input class="form-control form-control-sm" type="date" id="rc-to" name="to" value="<?= h($to) ?>"></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button></div>
                  </form>
                </div>
                <?php if ($book !== null): ?>
                  <div class="card-body border-bottom">
                    <div class="row g-2 small">
                      <div class="col-6 col-md-3">হিসাব বই অনুযায়ী স্থিতি<div class="fw-semibold fs-6"><?= h(Money::format($book['book'])) ?></div></div>
                      <div class="col-6 col-md-3">মিলানো লেনদেনের স্থিতি<div class="fw-semibold fs-6"><?= h(Money::format($book['reconciled'])) ?></div></div>
                      <div class="col-6 col-md-3">অমিলানো জমা (ব্যাংকে এখনো ওঠেনি)<div class="fw-semibold fs-6 text-warning-emphasis"><?= h(Money::format($book['uncleared_in'])) ?></div></div>
                      <div class="col-6 col-md-3">অমিলানো উত্তোলন/চেক<div class="fw-semibold fs-6 text-warning-emphasis"><?= h(Money::format($book['uncleared_out'])) ?></div></div>
                    </div>
                    <div class="form-text">মিলানো লেনদেনের স্থিতি ব্যাংক স্টেটমেন্টের স্থিতির সমান হওয়া উচিত।</div>
                  </div>
                  <form method="post">
                    <?= csrfField() ?><input type="hidden" name="account" value="<?= $reconAccount ?>">
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0 acc-table-stack">
                        <thead><tr><th style="width:2.5rem"><input class="form-check-input" type="checkbox" data-acc-check-all="lines[]" aria-label="সব"></th><th>তারিখ</th><th>ভাউচার</th><th>বিবরণ</th><th class="text-end">জমা</th><th class="text-end">উত্তোলন</th><th>মিলেছে</th></tr></thead>
                        <tbody>
                          <?php if ($book['rows'] === []): ?><tr><td colspan="7" class="text-secondary">এই সময়ে কোনো লেনদেন নেই।</td></tr><?php endif; ?>
                          <?php foreach ($book['rows'] as $row): ?>
                            <tr>
                              <td data-label="নির্বাচন"><input class="form-check-input" type="checkbox" name="lines[]" value="<?= (int) $row['line_id'] ?>" aria-label="নির্বাচন"></td>
                              <td data-label="তারিখ" class="text-nowrap"><?= h(bnDigits((string) $row['voucher_date'])) ?></td>
                              <td data-label="ভাউচার"><a href="<?= h(signedRecordUrl('voucher.php', (int) $row['voucher_id'], 'acc_voucher')) ?>"><code><?= h((string) $row['voucher_no']) ?></code></a></td>
                              <td data-label="বিবরণ"><?= h((string) $row['narration']) ?></td>
                              <td class="text-end text-nowrap" data-label="জমা"><?= Money::fromDb($row['debit']) > 0 ? h(Money::format(Money::fromDb($row['debit']))) : '' ?></td>
                              <td class="text-end text-nowrap" data-label="উত্তোলন"><?= Money::fromDb($row['credit']) > 0 ? h(Money::format(Money::fromDb($row['credit']))) : '' ?></td>
                              <td data-label="মিলেছে"><?= $row['statement_date'] ? '<span class="badge text-bg-success">' . h(bnDigits((string) $row['statement_date'])) . '</span>' : '<span class="badge border text-secondary">না</span>' ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php if ($book['rows'] !== []): ?>
                      <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                        <label class="small" for="rc-statement">স্টেটমেন্টের তারিখ</label>
                        <input class="form-control form-control-sm" style="max-width:11rem" type="date" id="rc-statement" name="statement_date" value="<?= h(accToday()->format('Y-m-d')) ?>" max="<?= h(accToday()->format('Y-m-d')) ?>">
                        <button class="btn btn-sm btn-success" type="submit" name="action" value="reconcile"><i class="bi bi-check2-all"></i> নির্বাচিতগুলো মিলেছে</button>
                        <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="unreconcile">মিল বাতিল</button>
                      </div>
                    <?php endif; ?>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
