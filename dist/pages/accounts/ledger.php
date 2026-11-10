<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::REPORTS_VIEW_ALL);
$chart = accChart();
$basePath = '../../';
$pageTitle = 'খতিয়ান | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$today = accToday();
$validDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false;
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$from = $validDate($from) ? $from : $today->modify('first day of this month')->format('Y-m-d');
$to = $validDate($to) ? $to : $today->format('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$postable = array_values(array_filter($chart->all(), static fn (array $row): bool => (int) $row['is_group'] === 0));
$accountId = (int) ($_GET['account'] ?? 0);
if ($accountId === 0) {
    $accountId = $chart->idOf(ChartOfAccounts::CASH);
}
$account = $chart->account($accountId);
$ledger = null;
if ($account !== null && (int) $account['is_group'] === 0) {
    $ledger = accVouchers()->ledger($accountId, $from, $to);
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">খতিয়ান</h3>
            <p class="text-secondary mb-0">একটি হিসাবের সব অনুমোদিত লেনদেন, প্রারম্ভিক ও সমাপনী স্থিতিসহ। নগদ হিসাব (১১১০) নির্বাচন করলে এটিই ক্যাশ বুক।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <form method="get" class="card card-body mb-3">
              <div class="row g-2 align-items-end">
                <div class="col-md-5">
                  <label class="form-label small mb-1" for="lg-account">হিসাব</label>
                  <select class="form-select form-select-sm" id="lg-account" name="account">
                    <?php foreach ($postable as $row): ?>
                      <option value="<?= (int) $row['id'] ?>"<?= (int) $row['id'] === $accountId ? ' selected' : '' ?>><?= h($row['code'] . ' — ' . $row['name_bn'] . ((int) $row['is_active'] === 1 ? '' : ' (নিষ্ক্রিয়)')) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2"><label class="form-label small mb-1" for="lg-from">শুরু</label><input class="form-control form-control-sm" type="date" id="lg-from" name="from" value="<?= h($from) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label small mb-1" for="lg-to">শেষ</label><input class="form-control form-control-sm" type="date" id="lg-to" name="to" value="<?= h($to) ?>"></div>
                <div class="col-md-3 d-flex gap-1"><button class="btn btn-sm btn-primary" type="submit">দেখুন</button><button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> প্রিন্ট</button></div>
              </div>
            </form>

            <?php if ($ledger === null): ?>
              <div class="alert alert-warning">হিসাবটি পাওয়া যায়নি।</div>
            <?php else: ?>
              <div class="card">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                  <h3 class="card-title mb-0 me-auto"><code><?= h((string) $account['code']) ?></code> <?= h((string) $account['name_bn']) ?> <span class="small text-secondary">(<?= h(bnDigits($from)) ?> — <?= h(bnDigits($to)) ?>)</span></h3>
                  <span class="small text-secondary">প্রারম্ভিক: <strong><?= h(Money::format($ledger['opening'])) ?></strong></span>
                  <span class="small text-secondary">সমাপনী: <strong class="<?= $ledger['closing'] < 0 ? 'text-danger' : '' ?>"><?= h(Money::format($ledger['closing'])) ?></strong></span>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 acc-table-stack">
                      <thead><tr><th>তারিখ</th><th>ভাউচার</th><th>বিবরণ</th><th class="text-end">ডেবিট</th><th class="text-end">ক্রেডিট</th><th class="text-end">স্থিতি</th></tr></thead>
                      <tbody>
                        <tr class="table-light"><td colspan="5">প্রারম্ভিক স্থিতি</td><td class="text-end text-nowrap"><?= h(Money::format($ledger['opening'])) ?></td></tr>
                        <?php if ($ledger['rows'] === []): ?><tr><td colspan="6" class="text-secondary">এই সময়ে কোনো লেনদেন নেই।</td></tr><?php endif; ?>
                        <?php foreach ($ledger['rows'] as $row): ?>
                          <tr>
                            <td data-label="তারিখ" class="text-nowrap"><?= h(bnDigits((string) $row['voucher_date'])) ?></td>
                            <td data-label="ভাউচার"><a href="<?= h(signedRecordUrl('voucher.php', (int) $row['voucher_id'], 'acc_voucher')) ?>"><code><?= h((string) $row['voucher_no']) ?></code></a></td>
                            <td data-label="বিবরণ"><?= h((string) $row['narration']) ?><?= (string) $row['memo'] !== '' ? '<div class="small text-secondary">' . h((string) $row['memo']) . '</div>' : '' ?></td>
                            <td class="text-end text-nowrap" data-label="ডেবিট"><?= Money::fromDb($row['debit']) > 0 ? h(Money::format(Money::fromDb($row['debit']))) : '' ?></td>
                            <td class="text-end text-nowrap" data-label="ক্রেডিট"><?= Money::fromDb($row['credit']) > 0 ? h(Money::format(Money::fromDb($row['credit']))) : '' ?></td>
                            <td class="text-end text-nowrap" data-label="স্থিতি"><?= h(Money::format($row['running'])) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                      <tfoot>
                        <tr class="fw-semibold"><td colspan="3">এই সময়ের মোট</td><td class="text-end text-nowrap"><?= h(Money::format($ledger['debit'])) ?></td><td class="text-end text-nowrap"><?= h(Money::format($ledger['credit'])) ?></td><td class="text-end text-nowrap"><?= h(Money::format($ledger['closing'])) ?></td></tr>
                      </tfoot>
                    </table>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
