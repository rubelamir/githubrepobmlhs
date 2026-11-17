<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Ledger\VoucherService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::VOUCHER_CREATE, Capability::VOUCHER_APPROVE]);
$canCreate = $access->can(Capability::VOUCHER_CREATE);
$canApprove = $access->can(Capability::VOUCHER_APPROVE);
$basePath = '../../';
$pageTitle = 'আয়-খরচ তালিকা | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$validDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
$filter = [
    'status' => (string) ($_GET['status'] ?? ($canApprove && accVouchers()->submittedCount() > 0 ? VoucherService::SUBMITTED : '')),
    'type' => (string) ($_GET['type'] ?? ''),
    'from' => $validDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '',
    'to' => $validDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '',
    'source' => in_array($_GET['source'] ?? '', ['manual', 'system'], true) ? (string) $_GET['source'] : '',
    'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60),
];
$list = accVouchers()->search($filter);
$total = array_sum(array_map(static fn (array $row): int => Money::fromDb($row['total']), $list));

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
              <div>
                <h3 class="mb-1">আয়-খরচ তালিকা</h3>
                <p class="text-secondary mb-0">প্রতিদিনের খরচ, অন্য আয় ও টাকা সরানো «আয় ও খরচ» থেকে হয়। প্রধান শিক্ষক যাচাই করলে তবেই খাতায় ওঠে। ফি আদায় নিজে থেকেই যোগ হয়।</p>
              </div>
              <?php if ($canCreate): ?>
                <div class="d-flex flex-wrap gap-2">
                  <a class="btn btn-primary" href="expense.php"><i class="bi bi-plus-lg me-1"></i>আয় বা খরচ</a>
                  <a class="btn btn-outline-secondary" href="voucher-form.php">বিশেষ এন্ট্রি</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <form method="get" class="card card-body mb-3">
              <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                  <label class="form-label small mb-1" for="vf-status">অবস্থা</label>
                  <select class="form-select form-select-sm" id="vf-status" name="status">
                    <option value="">সব</option>
                    <?php foreach (VoucherService::STATUS_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= $filter['status'] === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label small mb-1" for="vf-type">ধরন</label>
                  <select class="form-select form-select-sm" id="vf-type" name="type">
                    <option value="">সব</option>
                    <?php foreach (VoucherService::TYPES as $key => $label): ?><option value="<?= h($key) ?>"<?= $filter['type'] === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label small mb-1" for="vf-source">উৎস</label>
                  <select class="form-select form-select-sm" id="vf-source" name="source">
                    <option value="">সব</option>
                    <option value="manual"<?= $filter['source'] === 'manual' ? ' selected' : '' ?>>হাতে তৈরি</option>
                    <option value="system"<?= $filter['source'] === 'system' ? ' selected' : '' ?>>স্বয়ংক্রিয়</option>
                  </select>
                </div>
                <div class="col-6 col-md-2"><label class="form-label small mb-1" for="vf-from">শুরু</label><input class="form-control form-control-sm" type="date" id="vf-from" name="from" value="<?= h($filter['from']) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label small mb-1" for="vf-to">শেষ</label><input class="form-control form-control-sm" type="date" id="vf-to" name="to" value="<?= h($filter['to']) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label small mb-1" for="vf-q">নং / বিবরণ</label><input class="form-control form-control-sm" id="vf-q" name="q" value="<?= h($filter['q']) ?>"></div>
                <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">দেখুন</button> <a class="btn btn-sm btn-outline-secondary" href="vouchers.php?status=">সব এন্ট্রি</a></div>
              </div>
            </form>

            <div class="card">
              <div class="card-header d-flex gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto"><?= h(bnDigits((string) count($list))) ?>টি এন্ট্রি</h3>
                <span class="fw-semibold"><?= h(Money::format($total)) ?></span>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm table-hover align-middle mb-0 acc-table-stack">
                    <thead><tr><th>নং</th><th>তারিখ</th><th>ধরন</th><th>বিবরণ</th><th>তৈরি</th><th class="text-end">টাকা</th><th>অবস্থা</th></tr></thead>
                    <tbody>
                      <?php if ($list === []): ?><tr><td colspan="7" class="text-secondary">কোনো এন্ট্রি নেই।</td></tr><?php endif; ?>
                      <?php foreach ($list as $row): ?>
                        <tr>
                          <td data-label="নং"><a href="<?= h(signedRecordUrl('voucher.php', (int) $row['id'], 'acc_voucher')) ?>"><code><?= h($row['voucher_no'] !== null ? (string) $row['voucher_no'] : 'খসড়া #' . bnDigits((string) $row['id'])) ?></code></a></td>
                          <td data-label="তারিখ" class="text-nowrap"><?= h(bnDigits((string) $row['voucher_date'])) ?></td>
                          <td data-label="ধরন" class="small"><?= h(VoucherService::TYPES[$row['voucher_type']] ?? (string) $row['voucher_type']) ?></td>
                          <td data-label="বিবরণ"><?= h((string) $row['narration']) ?><?= $row['source_key'] !== null ? ' <span class="badge text-bg-light">স্বয়ংক্রিয়</span>' : '' ?><?= $row['reversed_by_id'] !== null ? ' <span class="badge text-bg-dark">রিভার্সড</span>' : '' ?></td>
                          <td data-label="তৈরি" class="small"><?= h((string) $row['created_by_name']) ?></td>
                          <td class="text-end text-nowrap" data-label="টাকা"><?= h(Money::format(Money::fromDb($row['total']))) ?></td>
                          <td data-label="অবস্থা"><span class="badge <?= h(VoucherService::STATUS_BADGES[$row['status']] ?? 'text-bg-light') ?>"><?= h(VoucherService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
