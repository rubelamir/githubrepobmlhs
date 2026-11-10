<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\BillingPeriod;
use School\Accounting\Billing\FeeStructureRepository;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::FEES_VIEW_ALL);
$canEdit = $access->can(Capability::FEE_STRUCTURE_MANAGE);
$catalog = accCatalog();
$structures = accStructures();
$basePath = '../../';
$pageTitle = 'শ্রেণীর হার | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$sessionYear = (string) ($input['session_year'] ?? AuditTrail::now()->format('Y'));
if (!in_array($sessionYear, accSessionYears(), true)) {
    $sessionYear = AuditTrail::now()->format('Y');
}
$classWide = (string) ($input['scope'] ?? '') === 'class';
$selection = accClassSelection($access, (int) ($input['cls'] ?? 0), (int) ($input['sections'] ?? 0));
$classId = $selection['class_id'];
$hasSections = $selection['sections'] !== [];
$sectionId = $classWide ? 0 : $selection['section_id'];
$ready = $classId > 0 && ($classWide || !$hasSections || $sectionId > 0);
$query = ['session_year' => $sessionYear, 'cls' => $classId, 'sections' => $selection['section_id']] + ($classWide ? ['scope' => 'class'] : []);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'fee-structure.php?' . http_build_query($query + ['effective' => (string) ($_POST['effective_from'] ?? '')]);
    try {
        if (!$ready) {
            throw new RuntimeException('শ্রেণী ও শাখা নির্বাচন করুন।');
        }
        $changed = $structures->saveGrid(
            $access,
            accAudit(),
            $sessionYear,
            $classId,
            $sectionId,
            (string) ($_POST['effective_from'] ?? ''),
            is_array($_POST['amount'] ?? null) ? $_POST['amount'] : [],
            is_array($_POST['settings'] ?? null) ? $_POST['settings'] : []
        );
        redirectWithMessage($back, 'success', $changed > 0 ? bnDigits((string) $changed) . 'টি হার সংরক্ষিত হয়েছে।' : 'কোনো পরিবর্তন ছিল না।');
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$heads = $catalog->heads(true);
$categories = $catalog->categories(true);
$generalId = $catalog->defaultCategoryId();
$dates = [];
$effective = '';
$grid = [];
$prefilledFrom = '';
$resolved = [];
if ($ready) {
    $dates = $structures->effectiveDates($sessionYear, $classId, $sectionId);
    $effective = (string) ($_GET['effective'] ?? '');
    try {
        $effective = FeeStructureRepository::parseDate($effective, 'তারিখ')->format('Y-m-d');
    } catch (Throwable) {
        $effective = $dates !== [] ? end($dates) : $sessionYear . '-01-01';
    }
    $grid = $structures->grid($sessionYear, $classId, $sectionId, $effective);
    if ($grid === [] && $dates !== []) {
        $earlier = array_values(array_filter($dates, static fn (string $date): bool => $date < $effective));
        $prefilledFrom = $earlier !== [] ? end($earlier) : '';
        if ($prefilledFrom !== '') {
            $grid = $structures->grid($sessionYear, $classId, $sectionId, $prefilledFrom);
        }
    }
    if (!$classWide && $hasSections) {
        $rows = $structures->rowsForClass($sessionYear, $classId, $sectionId);
        $today = accToday()->format('Y-m-d');
        foreach ($heads as $head) {
            foreach ($categories as $category) {
                $resolved[(int) $head['id']][(int) $category['id']] = FeeStructureRepository::resolve(
                    $rows,
                    (int) $head['id'],
                    $sectionId,
                    (int) $category['id'],
                    $generalId,
                    $today
                );
            }
        }
    }
}

$headSetting = static function (array $grid, int $headId, string $field, string $default): string {
    foreach ($grid[$headId] ?? [] as $row) {
        return (string) $row[$field];
    }

    return $default;
};

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">শ্রেণীর হার</h3>
            <p class="text-secondary mb-0">প্রতিটি শ্রেণী/শাখা ও ফি-শ্রেণীর (সাধারণ, উপবৃত্তিপ্রাপ্ত, বৃত্তিপ্রাপ্ত …) জন্য আলাদা হার। কোনো ফি-শ্রেণীর হার ফাঁকা থাকলে সেই শিক্ষার্থীদের সাধারণ হার প্রযোজ্য হবে।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="card mb-3">
              <form method="get" class="card-body" data-student-filter>
                <div class="row g-2 align-items-end">
                  <?= accClassFilterFields($selection, $sessionYear, 'col-sm-6 col-lg-3') ?>
                  <div class="col-sm-6 col-lg-3">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button>
                  </div>
                </div>
                <div class="form-check mt-2" data-sf-ignore>
                  <input class="form-check-input" type="checkbox" name="scope" value="class" id="scope-class" data-acc-class-wide<?= $classWide ? ' checked' : '' ?>>
                  <label class="form-check-label" for="scope-class">শ্রেণীর সব শাখার জন্য সাধারণ হার <span class="text-secondary small">(কোনো শাখার নিজস্ব হার থাকলে সেটিই প্রাধান্য পাবে)</span></label>
                </div>
                <?php if ($selection['notice'] !== '' && !$classWide): ?>
                  <div class="alert alert-info py-2 mt-2 mb-0 small"><?= h($selection['notice']) ?></div>
                <?php endif; ?>
              </form>
            </div>

            <?php if ($ready): ?>
              <div class="card mb-3">
                <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                  <span class="text-secondary small">কার্যকর তারিখ:</span>
                  <?php foreach ($dates as $date): ?>
                    <a class="btn btn-sm <?= $date === $effective ? 'btn-primary' : 'btn-outline-primary' ?>" href="fee-structure.php?<?= h(http_build_query($query + ['effective' => $date])) ?>"><?= h(bnDigits($date)) ?></a>
                  <?php endforeach; ?>
                  <?php if ($dates === []): ?><span class="text-secondary small">এখনো কোনো হার নির্ধারণ করা হয়নি।</span><?php endif; ?>
                  <?php if ($canEdit): ?>
                    <form method="get" class="d-flex gap-1 ms-auto">
                      <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                      <input class="form-control form-control-sm" type="date" name="effective" min="<?= h($sessionYear) ?>-01-01" max="<?= h($sessionYear) ?>-12-31" required aria-label="নতুন কার্যকর তারিখ">
                      <button class="btn btn-sm btn-outline-success text-nowrap" type="submit"><i class="bi bi-plus-lg"></i> নতুন হার</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <form method="post" class="card" data-acc-confirm="<?= h(bnDigits($effective)) ?> তারিখ থেকে এই হার কার্যকর হবে। সংরক্ষণ করবেন?">
                <?= csrfField() ?>
                <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                <input type="hidden" name="effective_from" value="<?= h($effective) ?>">
                <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                  <h3 class="card-title mb-0"><?= h(bnDigits($effective)) ?> থেকে কার্যকর হার <?= $classWide ? '<span class="badge text-bg-info">সব শাখা</span>' : '' ?></h3>
                  <?php if ($prefilledFrom !== ''): ?><span class="small text-secondary">নতুন তারিখ — <?= h(bnDigits($prefilledFrom)) ?>-এর হার পূরণ করা হয়েছে, প্রয়োজনমতো বদলে সংরক্ষণ করুন।</span><?php endif; ?>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0 acc-fee-grid">
                      <thead class="table-light">
                        <tr>
                          <th>ফি হেড</th>
                          <?php foreach ($categories as $category): ?>
                            <th class="text-end"><?= h((string) $category['name_bn']) ?> <span class="text-secondary small">(৳)</span></th>
                          <?php endforeach; ?>
                          <th>পরিশোধের দিন</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($heads as $head): ?>
                          <?php $headId = (int) $head['id']; ?>
                          <tr>
                            <th scope="row">
                              <?= h((string) $head['name_bn']) ?>
                              <div class="small text-secondary fw-normal"><?= h(BillingPeriod::KIND_LABELS[$head['billing_kind']] ?? '') ?></div>
                            </th>
                            <?php foreach ($categories as $category): ?>
                              <?php
                              $categoryId = (int) $category['id'];
                              $cell = $grid[$headId][$categoryId] ?? null;
                              $value = $cell ? Money::toInput(Money::fromDb($cell['amount'])) : '';
                              $applied = $resolved[$headId][$categoryId] ?? null;
                              ?>
                              <td class="text-end">
                                <?php if ($canEdit): ?>
                                  <input class="form-control form-control-sm text-end acc-money" name="amount[<?= $headId ?>][<?= $categoryId ?>]" value="<?= h($value) ?>" inputmode="decimal" maxlength="16" placeholder="<?= $categoryId === $generalId ? '—' : 'সাধারণ হার' ?>" aria-label="<?= h($head['name_bn'] . ' — ' . $category['name_bn']) ?>">
                                <?php else: ?>
                                  <?= $value !== '' ? h(Money::format(Money::fromDb($cell['amount']))) : '<span class="text-secondary">—</span>' ?>
                                <?php endif; ?>
                                <?php if ($applied !== null && ($cell === null || $applied['amount'] !== Money::fromDb($cell['amount']))): ?>
                                  <div class="small text-secondary">আজ প্রযোজ্য: <?= h(Money::format($applied['amount'])) ?></div>
                                <?php endif; ?>
                              </td>
                            <?php endforeach; ?>
                            <?php if ($canEdit): ?>
                              <td><input class="form-control form-control-sm" type="number" min="1" max="31" name="settings[<?= $headId ?>][due_day]" value="<?= h($headSetting($grid, $headId, 'due_day', '10')) ?>" aria-label="পরিশোধের দিন"></td>
                            <?php else: ?>
                              <td><?= h(bnDigits($headSetting($grid, $headId, 'due_day', '—'))) ?></td>
                            <?php endif; ?>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
                  <small class="text-secondary">পরিশোধের দিন: মাসিক ফির ক্ষেত্রে সেই মাসের তারিখ, অন্যগুলোর ক্ষেত্রে ইনভয়েসের তারিখ থেকে দিন। ফাঁকা ঘর = হার নেই। পুরনো হার বদলাতে নতুন কার্যকর তারিখ দিন; আগের ইনভয়েস অপরিবর্তিত থাকে।</small>
                  <?php if ($canEdit): ?><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>সংরক্ষণ</button><?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
