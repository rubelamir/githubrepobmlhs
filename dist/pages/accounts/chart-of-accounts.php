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
$canManage = $access->can(Capability::CHART_MANAGE);
$basePath = '../../';
$pageTitle = 'হিসাবের খাত | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $id = $chart->save($access, accAudit(), null, $_POST);
            redirectWithMessage('chart-of-accounts.php#acct-' . $id, 'success', 'নতুন হিসাব যোগ হয়েছে।');
        }
        if ($action === 'rename') {
            $chart->save($access, accAudit(), postedRecordId('acc_account'), $_POST);
            redirectWithMessage('chart-of-accounts.php', 'success', 'হিসাবের নাম হালনাগাদ হয়েছে।');
        }
        if ($action === 'activate' || $action === 'deactivate') {
            $chart->setActive($access, accAudit(), postedRecordId('acc_account'), $action === 'activate');
            redirectWithMessage('chart-of-accounts.php', 'success', $action === 'activate' ? 'হিসাব সক্রিয় হয়েছে।' : 'হিসাব নিষ্ক্রিয় হয়েছে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('chart-of-accounts.php', 'danger', userFacingError($exception));
    }
}

$asOfInput = (string) ($_GET['as_of'] ?? '');
$asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfInput) === 1 ? $asOfInput : accToday()->format('Y-m-d');
$accounts = $chart->all();
$totals = $chart->totals(null, $asOf);
$children = [];
foreach ($accounts as $account) {
    $children[(int) ($account['parent_id'] ?? 0)][] = $account;
}
$balanceOf = static function (array $account) use (&$balanceOf, $totals, $children): int {
    $id = (int) $account['id'];
    $net = ($totals[$id]['debit'] ?? 0) - ($totals[$id]['credit'] ?? 0);
    $own = ChartOfAccounts::isDebitNormal((string) $account['account_type']) ? $net : -$net;
    foreach ($children[$id] ?? [] as $child) {
        $own += $balanceOf($child);
    }

    return $own;
};
$rows = [];
$walk = static function (int $parentId, int $depth) use (&$walk, &$rows, $children, $balanceOf): void {
    foreach ($children[$parentId] ?? [] as $account) {
        $account['depth'] = $depth;
        $account['balance'] = $balanceOf($account);
        $rows[] = $account;
        $walk((int) $account['id'], $depth + 1);
    }
};
$walk(0, 0);
$groups = array_values(array_filter($accounts, static fn (array $account): bool => (int) $account['is_group'] === 1 && (int) $account['is_active'] === 1));
$trial = $chart->trialBalance($asOf);

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">হিসাবের খাত (চার্ট অব অ্যাকাউন্টস)</h3>
            <p class="text-secondary mb-0">সব আয়, ব্যয়, নগদ, ব্যাংক ও দায়ের হিসাব। স্থিতি শুধু অনুমোদিত ভাউচার থেকে গণনা হয়। <i class="bi bi-lock-fill"></i> চিহ্নিত সিস্টেম হিসাব স্বয়ংক্রিয় এন্ট্রিতে ব্যবহৃত হয়, তাই এগুলো নিষ্ক্রিয় করা যায় না।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
              <div>
                <label class="form-label small mb-1" for="coa-as-of">যে তারিখ পর্যন্ত স্থিতি</label>
                <input class="form-control form-control-sm" type="date" id="coa-as-of" name="as_of" value="<?= h($asOf) ?>">
              </div>
              <button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button>
            </form>

            <div class="row g-3">
              <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>">
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title mb-0">হিসাবের তালিকা</h3></div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0 acc-coa-table">
                        <thead><tr><th>কোড</th><th>হিসাব</th><th>ধরন</th><th class="text-end">স্থিতি</th><th></th></tr></thead>
                        <tbody>
                          <?php foreach ($rows as $account): ?>
                            <?php $accountId = (int) $account['id']; $isGroup = (int) $account['is_group'] === 1; $active = (int) $account['is_active'] === 1; ?>
                            <tr id="acct-<?= $accountId ?>" class="<?= $isGroup ? 'acc-coa-group' : '' ?><?= $active ? '' : ' text-secondary' ?>">
                              <td><code><?= h((string) $account['code']) ?></code></td>
                              <td style="padding-left: <?= 0.5 + 1.25 * (int) $account['depth'] ?>rem">
                                <?= h((string) $account['name_bn']) ?>
                                <?php if ((int) $account['is_system'] === 1): ?><i class="bi bi-lock-fill text-secondary small" title="সিস্টেম হিসাব"></i><?php endif; ?>
                                <?php if (!$active): ?><span class="badge text-bg-light">নিষ্ক্রিয়</span><?php endif; ?>
                                <?php if ((string) $account['money_kind'] !== 'none' && !$isGroup): ?><span class="badge text-bg-info-subtle text-info-emphasis"><?= h(ChartOfAccounts::MONEY_KINDS[$account['money_kind']] ?? '') ?></span><?php endif; ?>
                              </td>
                              <td class="small text-secondary"><?= h(ChartOfAccounts::TYPES[$account['account_type']] ?? '') ?><?= $isGroup ? ' (গ্রুপ)' : '' ?></td>
                              <td class="text-end text-nowrap<?= $account['balance'] < 0 ? ' text-danger' : '' ?>"><?= $account['balance'] !== 0 || !$isGroup ? h(Money::format($account['balance'])) : '' ?></td>
                              <td class="text-end text-nowrap">
                                <?php if (!$isGroup): ?><a class="btn btn-sm btn-outline-secondary" href="ledger.php?account=<?= $accountId ?>" title="খতিয়ান"><i class="bi bi-journal-bookmark"></i></a><?php endif; ?>
                                <?php if ($canManage): ?>
                                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#acct-edit-<?= $accountId ?>" title="নাম পরিবর্তন"><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php if ($canManage): ?>
                              <tr class="collapse" id="acct-edit-<?= $accountId ?>">
                                <td colspan="5" class="bg-body-tertiary">
                                  <div class="d-flex flex-wrap gap-2">
                                    <form method="post" class="d-flex flex-wrap gap-1 flex-grow-1">
                                      <?= csrfField() ?><input type="hidden" name="action" value="rename"><?= signedRecordField($accountId, 'acc_account') ?>
                                      <input class="form-control form-control-sm" style="max-width:18rem" name="name_bn" value="<?= h((string) $account['name_bn']) ?>" required maxlength="120" aria-label="হিসাবের নাম">
                                      <input class="form-control form-control-sm" style="max-width:18rem" name="description" value="<?= h((string) $account['description']) ?>" maxlength="255" placeholder="বিবরণ (ঐচ্ছিক)" aria-label="বিবরণ">
                                      <button class="btn btn-sm btn-primary" type="submit">সংরক্ষণ</button>
                                    </form>
                                    <?php if ((int) $account['is_system'] !== 1): ?>
                                      <form method="post" data-acc-confirm="<?= $active ? 'হিসাবটি নিষ্ক্রিয় করবেন? নতুন ভাউচারে আর ব্যবহার করা যাবে না।' : 'হিসাবটি আবার সক্রিয় করবেন?' ?>">
                                        <?= csrfField() ?><input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>"><?= signedRecordField($accountId, 'acc_account') ?>
                                        <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $active ? 'নিষ্ক্রিয়' : 'সক্রিয়' ?></button>
                                      </form>
                                    <?php endif; ?>
                                  </div>
                                </td>
                              </tr>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>

              <?php if ($canManage): ?>
              <div class="col-xl-4">
                <form method="post" class="card mb-3">
                  <?= csrfField() ?><input type="hidden" name="action" value="create">
                  <div class="card-header"><h3 class="card-title mb-0">নতুন হিসাব</h3></div>
                  <div class="card-body">
                    <div class="mb-2">
                      <label class="form-label" for="coa-parent">মূল গ্রুপ</label>
                      <select class="form-select" id="coa-parent" name="parent_id" required>
                        <option value="">নির্বাচন করুন</option>
                        <?php foreach ($groups as $group): ?>
                          <option value="<?= (int) $group['id'] ?>"><?= h($group['code'] . ' — ' . $group['name_bn']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="form-text">নতুন ব্যাংক হিসাব খুলতে "১১৫০ — ব্যাংক হিসাবসমূহ" নির্বাচন করুন।</div>
                    </div>
                    <div class="row g-2 mb-2">
                      <div class="col-4">
                        <label class="form-label" for="coa-code">কোড</label>
                        <input class="form-control" id="coa-code" name="code" inputmode="numeric" pattern="[1-5][0-9]{3,5}" required placeholder="1151">
                      </div>
                      <div class="col-8">
                        <label class="form-label" for="coa-name">নাম</label>
                        <input class="form-control" id="coa-name" name="name_bn" required maxlength="120" placeholder="যেমন: সোনালী ব্যাংক — হিসাব নং …">
                      </div>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="coa-money">অর্থের ধরন (শুধু সম্পদ হিসাবের জন্য)</label>
                      <select class="form-select" id="coa-money" name="money_kind">
                        <option value="none">নগদ/ব্যাংক নয় (যেমন: অগ্রিম, আসবাব)</option>
                        <option value="cash">নগদ (যেমন: পেটি ক্যাশ)</option>
                        <option value="bank">ব্যাংক</option>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="coa-desc">বিবরণ (ঐচ্ছিক)</label>
                      <input class="form-control" id="coa-desc" name="description" maxlength="255">
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="coa-group" name="is_group" value="1">
                      <label class="form-check-label" for="coa-group">এটি একটি গ্রুপ (ভেতরে উপ-হিসাব থাকবে, সরাসরি এন্ট্রি হবে না)</label>
                    </div>
                  </div>
                  <div class="card-footer"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>যোগ করুন</button></div>
                </form>
              </div>
              <?php endif; ?>
            </div>

            <div class="card" id="trial-balance">
              <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto">রেওয়ামিল (ট্রায়াল ব্যালান্স) — <?= h(bnDigits($asOf)) ?> পর্যন্ত</h3>
                <?php if ($trial['debit'] === $trial['credit']): ?>
                  <span class="badge text-bg-success"><i class="bi bi-check2"></i> ডেবিট = ক্রেডিট</span>
                <?php else: ?>
                  <span class="badge text-bg-danger">মেলেনি</span>
                <?php endif; ?>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <thead><tr><th>কোড</th><th>হিসাব</th><th class="text-end">ডেবিট</th><th class="text-end">ক্রেডিট</th></tr></thead>
                    <tbody>
                      <?php if ($trial['rows'] === []): ?><tr><td colspan="4" class="text-secondary">এখনো কোনো অনুমোদিত লেনদেন নেই।</td></tr><?php endif; ?>
                      <?php foreach ($trial['rows'] as $row): ?>
                        <tr>
                          <td><code><?= h((string) $row['code']) ?></code></td>
                          <td><a href="ledger.php?account=<?= (int) $row['id'] ?>&amp;to=<?= h($asOf) ?>"><?= h((string) $row['name_bn']) ?></a></td>
                          <td class="text-end text-nowrap"><?= $row['tb_debit'] > 0 ? h(Money::format($row['tb_debit'])) : '' ?></td>
                          <td class="text-end text-nowrap"><?= $row['tb_credit'] > 0 ? h(Money::format($row['tb_credit'])) : '' ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="2">মোট</td><td class="text-end text-nowrap"><?= h(Money::format($trial['debit'])) ?></td><td class="text-end text-nowrap"><?= h(Money::format($trial['credit'])) ?></td></tr></tfoot>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
