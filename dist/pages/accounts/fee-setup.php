<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Billing\BillingPeriod;
use School\Accounting\Ledger\ChartOfAccounts;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::FEE_STRUCTURE_MANAGE, Capability::FEES_VIEW_ALL]);
$canManage = $access->can(Capability::FEE_STRUCTURE_MANAGE);
$catalog = accCatalog();
$basePath = '../../';
$pageTitle = 'ফির নাম | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'save_head':
                $id = postedRecordId('acc_fee_head');
                $catalog->saveHead($access, accAudit(), $id > 0 ? $id : null, $_POST);
                redirectWithMessage('fee-setup.php', 'success', 'ফি হেড সংরক্ষিত হয়েছে।');
                // no break
            case 'toggle_head':
                $catalog->setHeadActive($access, accAudit(), postedRecordId('acc_fee_head'), (string) ($_POST['active'] ?? '') === '1');
                redirectWithMessage('fee-setup.php', 'success', 'ফি হেডের অবস্থা পরিবর্তন হয়েছে।');
                // no break
            case 'save_category':
                $id = postedRecordId('acc_fee_category');
                $catalog->saveCategory($access, accAudit(), $id > 0 ? $id : null, $_POST);
                redirectWithMessage('fee-setup.php#categories', 'success', 'ফি-শ্রেণী সংরক্ষিত হয়েছে।');
                // no break
            case 'toggle_category':
                $catalog->setCategoryActive($access, accAudit(), postedRecordId('acc_fee_category'), (string) ($_POST['active'] ?? '') === '1');
                redirectWithMessage('fee-setup.php#categories', 'success', 'ফি-শ্রেণীর অবস্থা পরিবর্তন হয়েছে।');
                // no break
            default:
                throw new RuntimeException('অজানা অনুরোধ।');
        }
    } catch (Throwable $exception) {
        redirectWithMessage('fee-setup.php', 'danger', userFacingError($exception));
    }
}

$heads = $catalog->heads();
$incomeAccounts = array_values(array_filter(
    accChart()->postable(),
    static fn (array $row): bool => $row['account_type'] === 'income' && $row['code'] !== ChartOfAccounts::LATE_FINE
));
$incomeNames = [];
foreach (accChart()->all() as $account) {
    $incomeNames[(int) $account['id']] = $account['code'] . ' ' . $account['name_bn'];
}
$categories = $catalog->categories();
$editHead = null;
$editCategory = null;
$editHeadId = idFromSignedRecordRef((string) ($_GET['ref'] ?? ''), 'acc_fee_head');
$editCategoryId = idFromSignedRecordRef((string) ($_GET['ref'] ?? ''), 'acc_fee_category');
if ($editHeadId > 0) {
    $editHead = $catalog->head($editHeadId);
}
if ($editCategoryId > 0) {
    $editCategory = $catalog->category($editCategoryId);
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">ফির নাম</h3>
            <p class="text-secondary mb-0">কোন কোন নামে ফি নেওয়া হবে (বেতন, ভর্তি, পরীক্ষা) এবং কাদের আলাদা হার (সাধারণ, উপবৃত্তি, বৃত্তি)। কোনো কিছু মুছে ফেলা যায় না — দরকার না হলে নিষ্ক্রিয় করুন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="row g-3">
              <?php if ($canManage): ?>
              <div class="col-lg-4">
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0"><?= $editHead ? 'ফি হেড সম্পাদনা' : 'নতুন ফি হেড' ?></h3></div>
                  <form method="post" class="card-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_head">
                    <?php if ($editHead): ?><?= signedRecordField((int) $editHead['id'], 'acc_fee_head') ?><?php endif; ?>
                    <div class="mb-2">
                      <label class="form-label" for="head-name">নাম</label>
                      <input class="form-control" id="head-name" name="name_bn" maxlength="120" required value="<?= h((string) ($editHead['name_bn'] ?? '')) ?>" placeholder="যেমন: মাসিক বেতন">
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="head-code">কোড <span class="text-secondary small">(ইংরেজি)</span></label>
                      <input class="form-control text-uppercase" id="head-code" name="code" maxlength="30" required pattern="[A-Za-z][A-Za-z0-9_]{1,29}" value="<?= h((string) ($editHead['code'] ?? '')) ?>" placeholder="TUITION">
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="head-kind">বিলের ধরন</label>
                      <select class="form-select" id="head-kind" name="billing_kind" required>
                        <?php foreach (BillingPeriod::KIND_LABELS as $kind => $label): ?>
                          <option value="<?= h($kind) ?>"<?= ($editHead['billing_kind'] ?? '') === $kind ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="head-income">আয়ের হিসাব (লেজারে কোথায় যাবে)</label>
                      <select class="form-select" id="head-income" name="income_account_id">
                        <option value="">৪১৯০ — অন্যান্য ফি (ডিফল্ট)</option>
                        <?php foreach ($incomeAccounts as $incomeAccount): ?>
                          <option value="<?= (int) $incomeAccount['id'] ?>"<?= (int) ($editHead['income_account_id'] ?? 0) === (int) $incomeAccount['id'] ? ' selected' : '' ?>><?= h($incomeAccount['code'] . ' — ' . $incomeAccount['name_bn']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      </div>
                    <div class="mb-3">
                      <label class="form-label" for="head-sort">ক্রম</label>
                      <input class="form-control" type="number" min="0" max="9999" id="head-sort" name="sort_order" value="<?= (int) ($editHead['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="d-flex gap-2">
                      <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-save me-1"></i>সংরক্ষণ</button>
                      <?php if ($editHead): ?><a class="btn btn-outline-secondary" href="fee-setup.php">বাতিল</a><?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
              <?php endif; ?>
              <div class="<?= $canManage ? 'col-lg-8' : 'col-12' ?>">
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0">ফি হেড</h3></div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table align-middle mb-0 acc-table-stack">
                        <thead><tr><th>নাম</th><th>কোড</th><th>বিলের ধরন</th><th>অবস্থা</th><?php if ($canManage): ?><th class="text-end">কার্যক্রম</th><?php endif; ?></tr></thead>
                        <tbody>
                          <?php foreach ($heads as $head): ?>
                            <?php $active = (int) $head['is_active'] === 1; ?>
                            <tr class="<?= $active ? '' : 'text-secondary' ?>">
                              <td data-label="নাম"><strong><?= h((string) $head['name_bn']) ?></strong></td>
                              <td data-label="কোড"><code><?= h((string) $head['code']) ?></code></td>
                              <td data-label="বিলের ধরন"><?= h(BillingPeriod::KIND_LABELS[$head['billing_kind']] ?? (string) $head['billing_kind']) ?><?php if (isset($incomeNames[(int) $head['income_account_id']])): ?><div class="small text-secondary">আয়: <?= h($incomeNames[(int) $head['income_account_id']]) ?></div><?php endif; ?></td>
                              <td data-label="অবস্থা"><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $active ? 'সক্রিয়' : 'নিষ্ক্রিয়' ?></span></td>
                              <?php if ($canManage): ?>
                              <td class="text-end text-nowrap" data-label="কার্যক্রম">
                                <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('fee-setup.php', (int) $head['id'], 'acc_fee_head')) ?>">সম্পাদনা</a>
                                <form method="post" class="d-inline" data-acc-confirm="<?= h((string) $head['name_bn']) ?> <?= $active ? 'নিষ্ক্রিয়' : 'সক্রিয়' ?> করবেন?">
                                  <?= csrfField() ?><input type="hidden" name="action" value="toggle_head"><input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                                  <?= signedRecordField((int) $head['id'], 'acc_fee_head') ?>
                                  <button class="btn btn-sm <?= $active ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"><?= $active ? 'নিষ্ক্রিয়' : 'সক্রিয়' ?></button>
                                </form>
                              </td>
                              <?php endif; ?>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="row g-3 mt-1" id="categories">
              <?php if ($canManage): ?>
              <div class="col-lg-4">
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0"><?= $editCategory ? 'ফি-শ্রেণী সম্পাদনা' : 'নতুন ফি-শ্রেণী' ?></h3></div>
                  <form method="post" class="card-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_category">
                    <?php if ($editCategory): ?><?= signedRecordField((int) $editCategory['id'], 'acc_fee_category') ?><?php endif; ?>
                    <div class="mb-2">
                      <label class="form-label" for="cat-name">নাম</label>
                      <input class="form-control" id="cat-name" name="name_bn" maxlength="100" required value="<?= h((string) ($editCategory['name_bn'] ?? '')) ?>" placeholder="যেমন: অর্ধ-বেতন">
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="cat-code">কোড <span class="text-secondary small">(ইংরেজি)</span></label>
                      <input class="form-control" id="cat-code" name="code" maxlength="30" required pattern="[a-z][a-z0-9_]{1,29}" value="<?= h((string) ($editCategory['code'] ?? '')) ?>" placeholder="half_free"<?= $editCategory && (int) $editCategory['is_default'] === 1 ? ' readonly' : '' ?>>
                    </div>
                    <div class="mb-2">
                      <label class="form-label" for="cat-desc">বিবরণ</label>
                      <input class="form-control" id="cat-desc" name="description" maxlength="255" value="<?= h((string) ($editCategory['description'] ?? '')) ?>">
                    </div>
                    <div class="row g-2 mb-3">
                      <div class="col-6">
                        <label class="form-label" for="cat-sort">ক্রম</label>
                        <input class="form-control" type="number" min="0" max="9999" id="cat-sort" name="sort_order" value="<?= (int) ($editCategory['sort_order'] ?? 0) ?>">
                      </div>
                      <div class="col-6 d-flex align-items-end">
                        <div class="form-check mb-2">
                          <input class="form-check-input" type="checkbox" id="cat-ref" name="requires_reference" value="1"<?= $editCategory && (int) $editCategory['requires_reference'] === 1 ? ' checked' : '' ?>>
                          <label class="form-check-label" for="cat-ref">আদেশ নম্বর বাধ্যতামূলক</label>
                        </div>
                      </div>
                    </div>
                    <div class="d-flex gap-2">
                      <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-save me-1"></i>সংরক্ষণ</button>
                      <?php if ($editCategory): ?><a class="btn btn-outline-secondary" href="fee-setup.php#categories">বাতিল</a><?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
              <?php endif; ?>
              <div class="<?= $canManage ? 'col-lg-8' : 'col-12' ?>">
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0">ফি-শ্রেণী</h3></div>
                  <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table align-middle mb-0 acc-table-stack">
                        <thead><tr><th>নাম</th><th>কোড</th><th>আদেশ নম্বর</th><th>অবস্থা</th><?php if ($canManage): ?><th class="text-end">কার্যক্রম</th><?php endif; ?></tr></thead>
                        <tbody>
                          <?php foreach ($categories as $category): ?>
                            <?php $active = (int) $category['is_active'] === 1; $isDefault = (int) $category['is_default'] === 1; ?>
                            <tr class="<?= $active ? '' : 'text-secondary' ?>">
                              <td data-label="নাম">
                                <strong><?= h((string) $category['name_bn']) ?></strong>
                                <?php if ($isDefault): ?><span class="badge text-bg-primary ms-1">ডিফল্ট</span><?php endif; ?>
                                <?php if ((string) $category['description'] !== ''): ?><div class="small text-secondary"><?= h((string) $category['description']) ?></div><?php endif; ?>
                              </td>
                              <td data-label="কোড"><code><?= h((string) $category['code']) ?></code></td>
                              <td data-label="আদেশ নম্বর"><?= (int) $category['requires_reference'] === 1 ? 'বাধ্যতামূলক' : '—' ?></td>
                              <td data-label="অবস্থা"><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $active ? 'সক্রিয়' : 'নিষ্ক্রিয়' ?></span></td>
                              <?php if ($canManage): ?>
                              <td class="text-end text-nowrap" data-label="কার্যক্রম">
                                <a class="btn btn-sm btn-outline-primary" href="<?= h(signedRecordUrl('fee-setup.php', (int) $category['id'], 'acc_fee_category')) ?>#categories">সম্পাদনা</a>
                                <?php if (!$isDefault): ?>
                                <form method="post" class="d-inline" data-acc-confirm="<?= h((string) $category['name_bn']) ?> <?= $active ? 'নিষ্ক্রিয়' : 'সক্রিয়' ?> করবেন?">
                                  <?= csrfField() ?><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                                  <?= signedRecordField((int) $category['id'], 'acc_fee_category') ?>
                                  <button class="btn btn-sm <?= $active ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"><?= $active ? 'নিষ্ক্রিয়' : 'সক্রিয়' ?></button>
                                </form>
                                <?php endif; ?>
                              </td>
                              <?php endif; ?>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
