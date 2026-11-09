<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Gateway\GatewayConfig;
use School\Accounting\Gateway\OnlinePaymentService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$canSettings = $access->can(Capability::GATEWAY_SETTINGS);
$canRefund = $access->can(Capability::REFUND_REQUEST);
if (!$canSettings && !$canRefund) {
    redirectWithMessage('dashboard.php', 'danger', 'এই পাতার অনুমতি আপনার নেই।');
}
$online = accOnline();
$basePath = '../../';
$pageTitle = 'অনলাইন পেমেন্ট | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $back = 'online-payments.php';
    try {
        if ($action === 'settings') {
            $code = (string) ($_POST['gateway'] ?? '');
            $online->saveSettings($access, accAudit(), $code, $_POST);
            redirectWithMessage($back, 'success', $online->gateway($code)->label() . ' সেটিংস সংরক্ষিত হয়েছে।');
        }
        $txnId = postedRecordId('acc_gateway_txn');
        $back = $txnId > 0 ? signedRecordUrl('online-payments.php', $txnId, 'acc_gateway_txn') : $back;
        if ($action === 'recheck') {
            $status = $online->recheck($access, $txnId);
            redirectWithMessage($back, 'info', 'গেটওয়ে থেকে আবার যাচাই করা হয়েছে। বর্তমান অবস্থা: ' . (OnlinePaymentService::STATUS_LABELS[$status] ?? $status) . '।');
        }
        if ($action === 'refund') {
            $result = accRefunds()->request($access, accAudit(), $txnId, (string) ($_POST['amount'] ?? ''), (string) ($_POST['reason'] ?? ''), (string) ($_POST['idempotency_key'] ?? ''));
            redirectWithMessage('refunds.php', $result['duplicate'] ? 'info' : 'success', $result['duplicate']
                ? 'এই রিফান্ড অনুরোধ আগেই জমা হয়েছে।'
                : 'রিফান্ড অনুরোধ জমা হয়েছে। প্রধান শিক্ষক অনুমোদন করলে গেটওয়েতে পাঠানো হবে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$filter = [
    'status' => (string) ($_GET['status'] ?? ''),
    'gateway' => (string) ($_GET['gateway'] ?? ''),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$txnId = requestRecordId('acc_gateway_txn');
$txn = $txnId > 0 ? $online->transaction($txnId) : null;
$list = $txn === null ? $online->search($filter) : [];
$summary = $online->statusSummary(accToday()->modify('-30 days')->format('Y-m-d'), accToday()->format('Y-m-d'));

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">অনলাইন পেমেন্ট</h3>
            <p class="text-secondary mb-0">bKash ও Nagad-এর মাধ্যমে ফি আদায়। সফল হয়েছে কি না তা কলব্যাকের ঠিকানা দেখে নয়, সার্ভার থেকে গেটওয়ের Execute/Query দিয়ে যাচাই করা হয়।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <?php if ($canSettings): ?>
              <div class="row g-3 mb-3">
                <?php foreach ($online->gateways() as $code => $gateway): ?>
                  <?php
                  $settings = $online->settings($code);
                  $config = GatewayConfig::for($code);
                  $missing = $config->missingKeys();
                  ?>
                  <div class="col-lg-6">
                    <form method="post" class="card h-100">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="settings">
                      <input type="hidden" name="gateway" value="<?= h($code) ?>">
                      <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><?= h($gateway->label()) ?></h3>
                        <span class="badge <?= $online->isAvailable($code) ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $online->isAvailable($code) ? 'চালু' : 'বন্ধ' ?></span>
                      </div>
                      <div class="card-body">
                        <p class="small mb-2">
                          সার্ভার: <code><?= h($config->host() !== '' ? $config->host() : 'সেট করা নেই') ?></code>
                          <?php if ($config->host() !== ''): ?><span class="badge <?= $config->isSandbox() ? 'text-bg-warning' : 'text-bg-danger' ?>"><?= $config->isSandbox() ? 'sandbox' : 'live' ?></span><?php endif; ?>
                        </p>
                        <?php if ($missing !== []): ?>
                          <p class="small text-danger mb-2">প্রাইভেট কনফিগে যা নেই: <?= h(implode(', ', $missing)) ?>। মান কখনো এই পাতায় দেখানো হয় না।</p>
                        <?php elseif (!$gateway->isReady()): ?>
                          <p class="small text-warning-emphasis mb-2">ক্রেডেনশিয়াল আছে, কিন্তু এই গেটওয়ের অ্যাডাপ্টার এখনো যুক্ত হয়নি।</p>
                        <?php else: ?>
                          <p class="small text-success mb-2">ক্রেডেনশিয়াল সম্পূর্ণ।</p>
                        <?php endif; ?>
                        <div class="form-check mb-2">
                          <input class="form-check-input" type="checkbox" name="enabled" value="1" id="gw-on-<?= h($code) ?>"<?= $settings['enabled'] ? ' checked' : '' ?>>
                          <label class="form-check-label" for="gw-on-<?= h($code) ?>">অভিভাবক পোর্টালে চালু</label>
                        </div>
                        <div class="row g-2">
                          <div class="col-sm-7">
                            <label class="form-label" for="gw-bearer-<?= h($code) ?>">গেটওয়ে চার্জ কে বহন করবে</label>
                            <select class="form-select form-select-sm" id="gw-bearer-<?= h($code) ?>" name="charge_bearer">
                              <?php foreach (OnlinePaymentService::BEARERS as $bearer => $label): ?>
                                <option value="<?= h($bearer) ?>"<?= $settings['charge_bearer'] === $bearer ? ' selected' : '' ?>><?= h($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-sm-5">
                            <label class="form-label" for="gw-rate-<?= h($code) ?>">চার্জের হার (%)</label>
                            <input class="form-control form-control-sm" id="gw-rate-<?= h($code) ?>" name="charge_rate" inputmode="decimal" value="<?= h(Money::toInput($settings['charge_rate_bp'])) ?>">
                          </div>
                        </div>
                        <p class="small text-secondary mt-2 mb-0">অভিভাবক বহন করলে চার্জ আলাদা আদায় (আয় নয়) হিসেবে খাতায় ওঠে। স্কুল বহন করলে চার্জ সেটেলমেন্টের সময় খরচ হয়।</p>
                      </div>
                      <div class="card-footer"><button class="btn btn-sm btn-primary" type="submit">সংরক্ষণ</button></div>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="card mb-3">
                <div class="card-body">
                  <h3 class="h6">পেন্ডিং পেমেন্ট মিলানো (cron)</h3>
                  <p class="small text-secondary mb-2">প্রতি ৫ মিনিটে cPanel cron-এ চালান। কীটি শুধু প্রধান শিক্ষক দেখতে পান; URL-এর <code>key</code> না মিললে কিছুই চলে না।</p>
                  <p class="small mb-1"><code>php <?= h(dirname(__DIR__, 3) . '/dist/cron/accounting-gateway.php') ?></code></p>
                  <p class="small mb-0">অথবা HTTP: <code><?= h(adminUrl('cron/accounting-gateway.php')) ?>?key=<?= h(accGatewayCronKey()) ?></code></p>
                </div>
              </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
              <?php foreach (OnlinePaymentService::STATUS_LABELS as $status => $label): ?>
                <?php $row = $summary[$status] ?? ['count' => 0, 'amount' => 0]; ?>
                <span class="badge <?= h(OnlinePaymentService::STATUS_BADGES[$status]) ?>"><?= h($label) ?> <?= h(bnDigits((string) $row['count'])) ?> · <?= h(Money::format($row['amount'])) ?></span>
              <?php endforeach; ?>
              <span class="small text-secondary align-self-center">গত ৩০ দিন</span>
            </div>

            <?php if ($txn !== null): ?>
              <?php
              $refundable = accRefunds()->refundableFor($txn);
              $refunds = accRefunds()->forTransaction((int) $txn['id']);
              ?>
              <div class="card mb-3">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                  <h3 class="card-title mb-0 me-auto"><?= h((string) $txn['merchant_invoice_no']) ?></h3>
                  <span class="badge <?= h(OnlinePaymentService::STATUS_BADGES[$txn['status']] ?? 'text-bg-secondary') ?>"><?= h(OnlinePaymentService::STATUS_LABELS[$txn['status']] ?? (string) $txn['status']) ?></span>
                  <a class="btn btn-sm btn-outline-secondary" href="online-payments.php">তালিকায় ফিরুন</a>
                </div>
                <div class="card-body">
                  <dl class="row mb-0">
                    <dt class="col-sm-3">শিক্ষার্থী</dt>
                    <dd class="col-sm-9"><?= h(trim((string) ($txn['name_ban'] ?? '')) ?: (string) ($txn['name_eng'] ?? '')) ?> — <?= h((string) $txn['class_name_ban']) ?><?= trim((string) $txn['section_name_ban']) !== '' ? ' / ' . h((string) $txn['section_name_ban']) : '' ?>, রোল <?= h(bnDigits((string) $txn['cls_roll'])) ?></dd>
                    <dt class="col-sm-3">ফি / চার্জ / মোট</dt>
                    <dd class="col-sm-9"><?= h(Money::format(Money::fromDb($txn['fee_amount']))) ?> / <?= h(Money::format(Money::fromDb($txn['charge_amount']))) ?> (<?= h(OnlinePaymentService::BEARERS[$txn['charge_bearer']] ?? (string) $txn['charge_bearer']) ?>) / <?= h(Money::format(Money::fromDb($txn['payable_amount']))) ?></dd>
                    <dt class="col-sm-3">গেটওয়ে</dt>
                    <dd class="col-sm-9"><?= h($online->gateway((string) $txn['gateway'])->label()) ?><?= $txn['trx_id'] ? ' · TrxID ' . h((string) $txn['trx_id']) : '' ?><?= $txn['payer_account_masked'] ? ' · ' . h((string) $txn['payer_account_masked']) : '' ?></dd>
                    <dt class="col-sm-3">রসিদ</dt>
                    <dd class="col-sm-9"><?php if ($txn['payment_id']): ?><a href="<?= h(signedRecordUrl('receipt.php', (int) $txn['payment_id'], 'acc_payment')) ?>"><?= h((string) $txn['receipt_no']) ?></a><?php else: ?>—<?php endif; ?><?= $txn['error_message'] ? ' · ' . h((string) $txn['error_message']) : '' ?></dd>
                    <dt class="col-sm-3">সময়</dt>
                    <dd class="col-sm-9">শুরু <?= h(accFormatDateTime((string) $txn['created_at'])) ?><?= $txn['completed_at'] ? ' · সম্পন্ন ' . h(accFormatDateTime((string) $txn['completed_at'])) : '' ?> · কোয়েরি <?= h(bnDigits((string) $txn['query_attempts'])) ?> বার</dd>
                  </dl>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                  <?php if (in_array($txn['status'], [OnlinePaymentService::PENDING, OnlinePaymentService::FAILED, OnlinePaymentService::CANCELLED, OnlinePaymentService::EXPIRED], true) && $txn['gateway_payment_id'] !== null): ?>
                    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="recheck"><?= signedRecordField((int) $txn['id'], 'acc_gateway_txn') ?><button class="btn btn-sm btn-outline-primary" type="submit">গেটওয়ে থেকে আবার যাচাই</button></form>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($canRefund && $txn['status'] === OnlinePaymentService::SUCCESS): ?>
                <form method="post" class="card mb-3" data-acc-confirm="রিফান্ড অনুরোধ জমা দেবেন? প্রধান শিক্ষকের অনুমোদনের পর গেটওয়েতে পাঠানো হবে।">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="refund">
                  <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                  <?= signedRecordField((int) $txn['id'], 'acc_gateway_txn') ?>
                  <div class="card-header"><h3 class="card-title mb-0">রিফান্ড অনুরোধ</h3></div>
                  <div class="card-body">
                    <?php if ($refundable < 1): ?>
                      <p class="mb-0 text-secondary">এই রসিদে আর রিফান্ডযোগ্য টাকা নেই।</p>
                    <?php else: ?>
                      <p class="small text-secondary">সর্বোচ্চ <?= h(Money::format($refundable)) ?> (পেমেন্টের ৬০ দিনের মধ্যে)।</p>
                      <div class="row g-2">
                        <div class="col-sm-3"><label class="form-label" for="rf-amount">পরিমাণ</label><input class="form-control" id="rf-amount" name="amount" inputmode="decimal" required value="<?= h(Money::toInput($refundable)) ?>"></div>
                        <div class="col-sm-9"><label class="form-label" for="rf-reason">কারণ</label><input class="form-control" id="rf-reason" name="reason" maxlength="200" required></div>
                      </div>
                    <?php endif; ?>
                  </div>
                  <?php if ($refundable > 0): ?><div class="card-footer"><button class="btn btn-warning" type="submit">অনুরোধ জমা দিন</button></div><?php endif; ?>
                </form>
              <?php endif; ?>

              <?php if ($refunds !== []): ?>
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title mb-0">এই পেমেন্টের রিফান্ড</h3></div>
                  <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                      <thead><tr><th>নম্বর</th><th>অবস্থা</th><th class="text-end">টাকা</th><th>কারণ</th></tr></thead>
                      <tbody>
                        <?php foreach ($refunds as $refund): ?>
                          <tr>
                            <td><a href="refunds.php"><?= h((string) $refund['refund_no']) ?></a></td>
                            <td><span class="badge <?= h(\School\Accounting\Gateway\RefundService::STATUS_BADGES[$refund['status']] ?? 'text-bg-secondary') ?>"><?= h(\School\Accounting\Gateway\RefundService::STATUS_LABELS[$refund['status']] ?? (string) $refund['status']) ?></span></td>
                            <td class="text-end"><?= h(Money::format(Money::fromDb($refund['amount']))) ?></td>
                            <td><?= h((string) $refund['reason']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endif; ?>

              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">গেটওয়ে লগ (সংবেদনশীল তথ্য মাস্ক করা)</h3></div>
                <div class="card-body">
                  <?php $logs = $online->logs((int) $txn['id']); ?>
                  <?php if ($logs === []): ?><p class="text-secondary mb-0">কোনো লগ নেই।</p><?php endif; ?>
                  <?php foreach ($logs as $log): ?>
                    <details class="mb-2">
                      <summary><?= h(accFormatDateTime((string) $log['created_at'])) ?> · <?= h((string) $log['operation']) ?> · HTTP <?= h((string) $log['http_status']) ?> · <?= h(bnDigits((string) $log['duration_ms'])) ?> ms<?= $log['error'] ? ' · ' . h((string) $log['error']) : '' ?></summary>
                      <pre class="small bg-body-tertiary p-2 mt-2 mb-0" style="white-space:pre-wrap"><?= h((string) $log['request_masked']) . "\n" . h((string) $log['response_masked']) ?></pre>
                    </details>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php else: ?>
              <form method="get" class="card mb-3">
                <div class="card-body">
                  <div class="row g-2 align-items-end">
                    <div class="col-sm-2"><label class="form-label" for="op-status">অবস্থা</label><select class="form-select form-select-sm" id="op-status" name="status"><option value="">সব</option><?php foreach (OnlinePaymentService::STATUS_LABELS as $status => $label): ?><option value="<?= h($status) ?>"<?= $filter['status'] === $status ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-2"><label class="form-label" for="op-gw">গেটওয়ে</label><select class="form-select form-select-sm" id="op-gw" name="gateway"><option value="">সব</option><?php foreach ($online->gateways() as $code => $gateway): ?><option value="<?= h($code) ?>"<?= $filter['gateway'] === $code ? ' selected' : '' ?>><?= h($gateway->label()) ?></option><?php endforeach; ?></select></div>
                    <div class="col-sm-2"><label class="form-label" for="op-from">থেকে</label><input class="form-control form-control-sm" id="op-from" type="date" name="from" value="<?= h($filter['from']) ?>"></div>
                    <div class="col-sm-2"><label class="form-label" for="op-to">পর্যন্ত</label><input class="form-control form-control-sm" id="op-to" type="date" name="to" value="<?= h($filter['to']) ?>"></div>
                    <div class="col-sm-3"><label class="form-label" for="op-q">ইনভয়েস / TrxID / রসিদ</label><input class="form-control form-control-sm" id="op-q" name="q" value="<?= h($filter['q']) ?>"></div>
                    <div class="col-sm-1"><button class="btn btn-sm btn-outline-primary w-100" type="submit">খুঁজুন</button></div>
                  </div>
                </div>
              </form>
              <div class="card">
                <div class="card-body p-0">
                  <?php if ($list === []): ?>
                    <p class="text-secondary p-3 mb-0">কোনো অনলাইন পেমেন্ট নেই।</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0 acc-table-stack">
                        <thead><tr><th>সময়</th><th>ইনভয়েস</th><th>শিক্ষার্থী</th><th>অবস্থা</th><th>TrxID</th><th class="text-end">মোট</th></tr></thead>
                        <tbody>
                          <?php foreach ($list as $row): ?>
                            <tr>
                              <td data-label="সময়"><?= h(accFormatDateTime((string) $row['created_at'])) ?></td>
                              <td data-label="ইনভয়েস"><a href="<?= h(signedRecordUrl('online-payments.php', (int) $row['id'], 'acc_gateway_txn')) ?>"><code><?= h((string) $row['merchant_invoice_no']) ?></code></a></td>
                              <td data-label="শিক্ষার্থী"><?= h(trim((string) ($row['name_ban'] ?? '')) ?: (string) ($row['name_eng'] ?? '')) ?> <span class="small text-secondary"><?= h((string) $row['class_name_ban']) ?>, রোল <?= h(bnDigits((string) $row['cls_roll'])) ?></span></td>
                              <td data-label="অবস্থা"><span class="badge <?= h(OnlinePaymentService::STATUS_BADGES[$row['status']] ?? 'text-bg-secondary') ?>"><?= h(OnlinePaymentService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span></td>
                              <td data-label="TrxID"><?= h((string) ($row['trx_id'] ?? '—')) ?></td>
                              <td class="text-end" data-label="মোট"><?= h(Money::format(Money::fromDb($row['payable_amount']))) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
