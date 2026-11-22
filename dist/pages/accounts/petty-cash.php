<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';
require_once dirname(__DIR__, 3) . '/shared/petty-cash.php';

$access = accBootPage([Capability::REPORTS_VIEW_ALL, Capability::VOUCHER_CREATE]);
$canWrite = $access->can(Capability::VOUCHER_CREATE) || $access->can(Capability::REPORTS_VIEW_ALL);
$db = getDatabaseConnection();
pettyCashEnsureSchema($db);

$month = pettyCashParseMonth((string) ($_GET['month'] ?? $_POST['month'] ?? ''), accToday());
$monthKey = $month['key'];
$redirectBase = 'petty-cash.php?month=' . rawurlencode($monthKey);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canWrite) {
        redirectWithMessage($redirectBase, 'danger', 'এন্ট্রি করার অনুমতি নেই।');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        $actorId = $access->teacherId();
        if ($action === 'create') {
            $type = (string) ($_POST['entry_type'] ?? '');
            $id = pettyCashInsert(
                $db,
                $type,
                (string) ($_POST['entry_date'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                Money::parse((string) ($_POST['amount'] ?? ''), 'টাকার অংক'),
                $actorId
            );
            $savedMonth = pettyCashParseMonth(substr((string) ($_POST['entry_date'] ?? ''), 0, 7), accToday());
            redirectWithMessage(
                'petty-cash.php?month=' . rawurlencode($savedMonth['key']),
                'success',
                ($type === PETTY_CASH_INCOME ? 'আয়' : 'ব্যয়') . ' এন্ট্রি সংরক্ষিত হয়েছে।'
            );
        }
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $existing = pettyCashFind($db, $id);
            if ($existing === null) {
                throw new RuntimeException('এন্ট্রিটি পাওয়া যায়নি।');
            }
            pettyCashUpdate(
                $db,
                $id,
                (string) ($_POST['entry_date'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                Money::parse((string) ($_POST['amount'] ?? ''), 'টাকার অংক'),
                $actorId
            );
            $savedMonth = pettyCashParseMonth(substr((string) ($_POST['entry_date'] ?? ''), 0, 7), accToday());
            redirectWithMessage(
                'petty-cash.php?month=' . rawurlencode($savedMonth['key']),
                'success',
                'এন্ট্রি আপডেট হয়েছে।'
            );
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        $editId = (int) ($_POST['id'] ?? 0);
        $back = $redirectBase . ($editId > 0 ? '&edit=' . $editId : '');
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$income = pettyCashEntries($db, PETTY_CASH_INCOME, $month['from'], $month['to']);
$expense = pettyCashEntries($db, PETTY_CASH_EXPENSE, $month['from'], $month['to']);
$incomeTotal = pettyCashTotalPaisa($income);
$expenseTotal = pettyCashTotalPaisa($expense);
$diff = $incomeTotal - $expenseTotal;
$tableRows = pettyCashTableRows($income, $expense);

if ((string) ($_GET['format'] ?? '') === 'pdf') {
    pettyCashDownloadPdf($monthKey, $month['from'], $month['to'], $income, $expense, $incomeTotal, $expenseTotal);
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? pettyCashFind($db, $editId) : null;
if ($editRow !== null) {
    $editMonth = pettyCashParseMonth(substr((string) $editRow['entry_date'], 0, 7), accToday());
    if ($editMonth['key'] !== $monthKey) {
        redirectWithMessage('petty-cash.php?month=' . rawurlencode($editMonth['key']) . '&edit=' . $editId, 'info', 'এন্ট্রির মাসে নিয়ে যাওয়া হলো।');
    }
}

$basePath = '../../';
$pageTitle = 'Petty Cash Book | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
$pdfUrl = 'petty-cash.php?month=' . rawurlencode($monthKey) . '&format=pdf';
$defaultDate = accToday()->format('Y-m') === $monthKey ? accToday()->format('Y-m-d') : $month['from'];

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header d-print-none">
          <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
              <div>
                <h3 class="mb-1">Petty Cash Book</h3>
                <p class="text-secondary mb-0">দৈনিক ক্ষুদ্র আয়-ব্যয়ের আলাদা খাতা — মূল হিসাবের খাতার সাথে মেশে না।</p>
              </div>
              <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">← ড্যাশবোর্ড</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">প্রিন্ট</button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= h($pdfUrl) ?>">PDF</a>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <form method="get" class="card card-body mb-3 d-print-none">
              <div class="row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label" for="petty-month">মাস</label>
                  <input class="form-control" type="month" id="petty-month" name="month" value="<?= h($monthKey) ?>">
                </div>
                <div class="col-md-3 d-flex flex-wrap gap-1">
                  <button class="btn btn-primary" type="submit">দেখুন</button>
                  <a class="btn btn-outline-secondary" href="petty-cash.php">চলতি মাস</a>
                </div>
              </div>
            </form>

            <?php if ($editRow !== null && $canWrite): ?>
              <div class="card mb-3 border-primary d-print-none">
                <div class="card-header"><h3 class="card-title mb-0">এন্ট্রি সম্পাদনা — <?= h($editRow['entry_type'] === PETTY_CASH_INCOME ? 'আয়' : 'ব্যয়') ?></h3></div>
                <div class="card-body">
                  <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                    <div class="col-md-2">
                      <label class="form-label" for="edit-date">তারিখ</label>
                      <input class="form-control" type="date" id="edit-date" name="entry_date" required value="<?= h((string) $editRow['entry_date']) ?>">
                    </div>
                    <div class="col-md-5">
                      <label class="form-label" for="edit-description"><?= $editRow['entry_type'] === PETTY_CASH_INCOME ? 'আয়ের খাত / বিবরণ' : 'ব্যয়ের খাত / বিবরণ' ?></label>
                      <input class="form-control" type="text" id="edit-description" name="description" maxlength="255" required value="<?= h((string) $editRow['description']) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label" for="edit-amount">টাকার অংক</label>
                      <input class="form-control" type="text" id="edit-amount" name="amount" inputmode="decimal" required value="<?= h(Money::toInput(Money::fromDb($editRow['amount']))) ?>">
                    </div>
                    <div class="col-md-3 d-flex flex-wrap gap-1">
                      <button class="btn btn-primary" type="submit">আপডেট</button>
                      <a class="btn btn-outline-secondary" href="<?= h($redirectBase) ?>">বাতিল</a>
                    </div>
                  </form>
                </div>
              </div>
            <?php elseif ($canWrite): ?>
              <div class="row g-3 mb-3 d-print-none">
                <div class="col-lg-6">
                  <div class="card h-100">
                    <div class="card-header"><h3 class="card-title mb-0">আয় এন্ট্রি</h3></div>
                    <div class="card-body">
                      <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="entry_type" value="<?= h(PETTY_CASH_INCOME) ?>">
                        <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                        <div class="col-md-4">
                          <label class="form-label" for="income-date">তারিখ</label>
                          <input class="form-control" type="date" id="income-date" name="entry_date" required value="<?= h($defaultDate) ?>">
                        </div>
                        <div class="col-md-8">
                          <label class="form-label" for="income-description">আয়ের খাত / বিবরণ</label>
                          <input class="form-control" type="text" id="income-description" name="description" maxlength="255" required placeholder="যেমন: চা-নাস্তা তহবিল">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="income-amount">টাকার অংক</label>
                          <input class="form-control" type="text" id="income-amount" name="amount" inputmode="decimal" required placeholder="০.০০">
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                          <button class="btn btn-success" type="submit">সাবমিট</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <div class="col-lg-6">
                  <div class="card h-100">
                    <div class="card-header"><h3 class="card-title mb-0">ব্যয় এন্ট্রি</h3></div>
                    <div class="card-body">
                      <form method="post" class="row g-2">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="entry_type" value="<?= h(PETTY_CASH_EXPENSE) ?>">
                        <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                        <div class="col-md-4">
                          <label class="form-label" for="expense-date">তারিখ</label>
                          <input class="form-control" type="date" id="expense-date" name="entry_date" required value="<?= h($defaultDate) ?>">
                        </div>
                        <div class="col-md-8">
                          <label class="form-label" for="expense-description">ব্যয়ের খাত / বিবরণ</label>
                          <input class="form-control" type="text" id="expense-description" name="description" maxlength="255" required placeholder="যেমন: স্টেশনারি">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="expense-amount">টাকার অংক</label>
                          <input class="form-control" type="text" id="expense-amount" name="amount" inputmode="decimal" required placeholder="০.০০">
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                          <button class="btn btn-danger" type="submit">সাবমিট</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="card acc-petty-sheet">
              <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h3 class="card-title mb-0">মাসিক খাতা · <?= h(bnDigits($monthKey)) ?></h3>
                <span class="small text-secondary d-print-none"><?= h(accFormatDate($month['from'])) ?> — <?= h(accFormatDate($month['to'])) ?></span>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm table-bordered align-middle mb-0 acc-petty-table">
                    <thead>
                      <tr>
                        <th colspan="4" class="text-center acc-petty-income-head">আয়ের খাত সমূহ</th>
                        <th colspan="4" class="text-center acc-petty-expense-head">ব্যয়ের খাত সমূহ</th>
                      </tr>
                      <tr>
                        <th class="text-center">তারিখ</th>
                        <th>বিবরণ</th>
                        <th class="text-end">টাকা</th>
                        <th class="text-center d-print-none">সম্পাদনা</th>
                        <th class="text-center">তারিখ</th>
                        <th>বিবরণ</th>
                        <th class="text-end">টাকা</th>
                        <th class="text-center d-print-none">সম্পাদনা</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($tableRows === []): ?>
                        <tr><td colspan="8" class="text-secondary p-3">এই মাসে কোনো এন্ট্রি নেই।</td></tr>
                      <?php endif; ?>
                      <?php foreach ($tableRows as $row): ?>
                        <?php
                        $left = $row['income'];
                        $right = $row['expense'];
                        ?>
                        <tr>
                          <td class="text-nowrap text-center"><?= $left ? h(accFormatDate((string) $left['entry_date'])) : '' ?></td>
                          <td><?= $left ? h((string) $left['description']) : '' ?></td>
                          <td class="text-end text-nowrap"><?= $left ? h(Money::format(Money::fromDb($left['amount']))) : '' ?></td>
                          <td class="text-center d-print-none">
                            <?php if ($left && $canWrite): ?>
                              <a class="btn btn-link btn-sm p-0" href="<?= h($redirectBase . '&edit=' . (int) $left['id']) ?>">সম্পাদনা</a>
                            <?php endif; ?>
                          </td>
                          <td class="text-nowrap text-center"><?= $right ? h(accFormatDate((string) $right['entry_date'])) : '' ?></td>
                          <td><?= $right ? h((string) $right['description']) : '' ?></td>
                          <td class="text-end text-nowrap"><?= $right ? h(Money::format(Money::fromDb($right['amount']))) : '' ?></td>
                          <td class="text-center d-print-none">
                            <?php if ($right && $canWrite): ?>
                              <a class="btn btn-link btn-sm p-0" href="<?= h($redirectBase . '&edit=' . (int) $right['id']) ?>">সম্পাদনা</a>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-semibold">
                        <td colspan="2">মোট আয়</td>
                        <td class="text-end text-nowrap"><?= h(Money::format($incomeTotal)) ?></td>
                        <td class="d-print-none"></td>
                        <td colspan="2">মোট ব্যয়</td>
                        <td class="text-end text-nowrap"><?= h(Money::format($expenseTotal)) ?></td>
                        <td class="d-print-none"></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
              <div class="card-footer">
                <?php if ($diff === 0): ?>
                  <div class="acc-petty-balanced">আয়-ব্যয়ের দুই পাশ মিলেছে।</div>
                <?php else: ?>
                  <div class="acc-petty-diff">
                    পার্থক্য: <?= h(Money::format(abs($diff))) ?>
                    <?= $diff > 0 ? '(আয় বেশি)' : '(ব্যয় বেশি)' ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
