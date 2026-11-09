<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Gateway\SettlementService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$canSubmit = $access->can(Capability::BANK_RECONCILE);
$canVerify = $access->can(Capability::VOUCHER_APPROVE);
if (!$canSubmit && !$canVerify) {
    redirectWithMessage('dashboard.php', 'danger', 'এই পাতার অনুমতি আপনার নেই।');
}
$settlements = accSettlements();
$basePath = '../../';
$pageTitle = 'গেটওয়ে সেটেলমেন্ট | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
$status = (string) ($_GET['status'] ?? ($canVerify ? SettlementService::SUBMITTED : ''));
$banks = accChart()->bankAccounts();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'submit') {
            $result = $settlements->submit($access, accAudit(), (string) ($_POST['idempotency_key'] ?? ''), $_POST);
            redirectWithMessage('settlements.php', $result['duplicate'] ? 'info' : 'success', $result['duplicate']
                ? 'এই সেটেলমেন্ট আগেই জমা হয়েছে।'
                : 'সেটেলমেন্ট জমা হয়েছে। প্রধান শিক্ষক যাচাই করলে খাতায় এন্ট্রি হবে।');
        }
        if ($action === 'verify' || $action === 'reject') {
            $settlements->decide($access, accAudit(), postedRecordId('acc_settlement'), $action === 'verify', (string) ($_POST['remark'] ?? ''));
            redirectWithMessage('settlements.php', 'success', $action === 'verify' ? 'সেটেলমেন্ট যাচাই হয়েছে; ব্যাংক ও গেটওয়ে হিসাব সমন্বয় করা হয়েছে।' : 'সেটেলমেন্ট ফেরত দেওয়া হয়েছে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('settlements.php', 'danger', userFacingError($exception));
    }
}

$list = $settlements->search($status);
$wallets = ['bkash' => 'bKash', 'nagad' => 'Nagad'];

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">গেটওয়ে সেটেলমেন্ট</h3>
            <p class="text-secondary mb-0">bKash/Nagad মার্চেন্ট হিসাব থেকে স্কুলের ব্যাংকে টাকা এলে স্টেটমেন্ট অনুযায়ী লিপিবদ্ধ করুন। মোট থেকে চার্জ বাদ দিয়ে নেট ব্যাংকে যায়; চার্জ আলাদা খরচ (৫১৬০)। প্রধান শিক্ষকের যাচাইয়ের আগে কোনো এন্ট্রি হয় না।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <div class="row g-3 mb-3">
              <?php foreach ($wallets as $code => $label): ?>
                <div class="col-sm-6 col-lg-3">
                  <div class="acc-stat">
                    <div class="acc-stat-label"><?= h($label) ?> সেটেলযোগ্য</div>
                    <div class="acc-stat-value"><?= h(Money::format($settlements->available($code))) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($canSubmit): ?>
              <form method="post" class="card mb-3" data-acc-confirm="স্টেটমেন্টের অঙ্ক মিলিয়ে সেটেলমেন্ট জমা দেবেন?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                <div class="card-header"><h3 class="card-title mb-0">নতুন সেটেলমেন্ট</h3></div>
                <div class="card-body">
                  <?php if ($banks === []): ?>
                    <p class="text-warning-emphasis mb-0">আগে <a href="chart-of-accounts.php">হিসাবের খাতে</a> একটি ব্যাংক হিসাব যোগ করুন।</p>
                  <?php else: ?>
                    <div class="row g-2">
                      <div class="col-sm-4 col-lg-2">
                        <label class="form-label" for="st-gw">গেটওয়ে</label>
                        <select class="form-select" id="st-gw" name="gateway" required>
                          <?php foreach ($wallets as $code => $label): ?><option value="<?= h($code) ?>"><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-sm-4 col-lg-2"><label class="form-label" for="st-date">তারিখ</label><input class="form-control" id="st-date" type="date" name="settlement_date" required value="<?= h(accToday()->format('Y-m-d')) ?>"></div>
                      <div class="col-sm-4 col-lg-2"><label class="form-label" for="st-gross">মোট (গ্রস)</label><input class="form-control" id="st-gross" name="gross" inputmode="decimal" required></div>
                      <div class="col-sm-4 col-lg-2"><label class="form-label" for="st-charge">গেটওয়ে চার্জ</label><input class="form-control" id="st-charge" name="charge" inputmode="decimal" value="0" required></div>
                      <div class="col-sm-8 col-lg-4">
                        <label class="form-label" for="st-bank">ব্যাংক হিসাব</label>
                        <select class="form-select" id="st-bank" name="bank_account_id" required>
                          <?php foreach ($banks as $bank): ?><option value="<?= (int) $bank['id'] ?>"><?= h((string) $bank['code']) ?> — <?= h((string) $bank['name_bn']) ?></option><?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-sm-6"><label class="form-label" for="st-ref">স্টেটমেন্ট রেফারেন্স</label><input class="form-control" id="st-ref" name="reference" maxlength="80" required></div>
                      <div class="col-sm-6"><label class="form-label" for="st-note">মন্তব্য</label><input class="form-control" id="st-note" name="note" maxlength="255"></div>
                    </div>
                  <?php endif; ?>
                </div>
                <?php if ($banks !== []): ?><div class="card-footer"><button class="btn btn-primary" type="submit">জমা দিন</button></div><?php endif; ?>
              </form>
            <?php endif; ?>

            <div class="card">
              <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto">সেটেলমেন্ট</h3>
                <form method="get" class="d-flex gap-1">
                  <select class="form-select form-select-sm" name="status" aria-label="অবস্থা">
                    <option value="">সব</option>
                    <?php foreach (SettlementService::STATUS_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button>
                </form>
              </div>
              <div class="card-body">
                <?php if ($list === []): ?><p class="text-secondary mb-0">কোনো সেটেলমেন্ট নেই।</p><?php endif; ?>
                <?php foreach ($list as $row): ?>
                  <article class="border rounded p-3 mb-2">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                      <div>
                        <strong><?= h((string) $row['settlement_no']) ?></strong>
                        <span class="badge <?= h(SettlementService::STATUS_BADGES[$row['status']] ?? 'text-bg-secondary') ?>"><?= h(SettlementService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span>
                        <div class="small text-secondary"><?= h($wallets[$row['gateway']] ?? (string) $row['gateway']) ?> · <?= h(bnDigits(date('d/m/Y', strtotime((string) $row['settlement_date'])))) ?> · রেফ <?= h((string) $row['reference']) ?> · <?= h((string) $row['bank_code']) ?> <?= h((string) $row['bank_name']) ?></div>
                      </div>
                      <div class="text-end">
                        <div>গ্রস <?= h(Money::format(Money::fromDb($row['gross']))) ?></div>
                        <div class="small">চার্জ <?= h(Money::format(Money::fromDb($row['charge']))) ?> · নেট <?= h(Money::format(Money::fromDb($row['net']))) ?></div>
                      </div>
                    </div>
                    <?php if ($row['note'] !== ''): ?><p class="small mb-1 mt-2"><?= h((string) $row['note']) ?></p><?php endif; ?>
                    <?php if ($row['remark']): ?><p class="small text-secondary mb-1">মন্তব্য: <?= h((string) $row['remark']) ?></p><?php endif; ?>
                    <?php if ($row['voucher_no']): ?><p class="small mb-1">ভাউচার <a href="<?= h(signedRecordUrl('voucher.php', (int) $row['voucher_id'], 'acc_voucher')) ?>"><?= h((string) $row['voucher_no']) ?></a></p><?php endif; ?>
                    <div class="small text-secondary">জমা: <?= h((string) ($row['submitted_by_name'] ?? '')) ?>, <?= h(accFormatDateTime((string) $row['submitted_at'])) ?></div>
                    <?php if ($canVerify && $row['status'] === SettlementService::SUBMITTED): ?>
                      <div class="d-flex flex-wrap gap-2 mt-2">
                        <form method="post" data-acc-confirm="স্টেটমেন্ট মিলিয়ে সেটেলমেন্ট যাচাই করবেন? খাতায় এন্ট্রি হবে।">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="verify">
                          <?= signedRecordField((int) $row['id'], 'acc_settlement') ?>
                          <button class="btn btn-sm btn-success" type="submit">যাচাই</button>
                        </form>
                        <form method="post" class="d-flex flex-wrap gap-1">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="reject">
                          <?= signedRecordField((int) $row['id'], 'acc_settlement') ?>
                          <input class="form-control form-control-sm" name="remark" maxlength="255" required placeholder="ফেরতের কারণ" aria-label="ফেরতের কারণ">
                          <button class="btn btn-sm btn-outline-danger" type="submit">ফেরত</button>
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