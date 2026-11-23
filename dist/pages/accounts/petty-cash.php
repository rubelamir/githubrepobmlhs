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

// First visit after deploy: pull Jan-21…May-26 Excel history once.
if ($canWrite && function_exists('schemaMigrationApplied')
    && !schemaMigrationApplied($db, pettyCashImportMigrationName())
    && is_file(pettyCashImportJsonPath())) {
    try {
        set_time_limit(180);
        $import = pettyCashImportFromBundledJson($db, $access->teacherId(), false);
        if (!$import['skipped']) {
            redirectWithMessage('petty-cash.php?month=2021-01', 'success', $import['message']);
        }
    } catch (Throwable $exception) {
        redirectWithMessage('petty-cash.php', 'danger', userFacingError($exception));
    }
}

$today = accToday();
$month = pettyCashParseMonth((string) ($_GET['month'] ?? $_POST['month'] ?? ''), $today);
$monthKey = $month['key'];
$monthLabel = $month['label'];
$isCurrentMonth = $monthKey === $today->format('Y-m');
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$redirectBase = 'petty-cash.php?month=' . rawurlencode($monthKey);
if ($searchQuery !== '') {
    $redirectBase .= '&q=' . rawurlencode($searchQuery);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canWrite) {
        redirectWithMessage($redirectBase, 'danger', 'এন্ট্রি করার অনুমতি নেই।');
    }
    $action = (string) ($_POST['action'] ?? '');
    $postMonth = pettyCashParseMonth((string) ($_POST['month'] ?? $monthKey), $today);
    try {
        $actorId = $access->teacherId();
        if ($action === 'create') {
            $type = (string) ($_POST['entry_type'] ?? '');
            pettyCashInsert(
                $db,
                $type,
                (string) ($_POST['entry_date'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                pettyCashParseAmount((string) ($_POST['amount'] ?? '')),
                $actorId,
                $postMonth['key']
            );
            redirectWithMessage(
                'petty-cash.php?month=' . rawurlencode($postMonth['key']),
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
                pettyCashParseAmount((string) ($_POST['amount'] ?? '')),
                $actorId,
                $postMonth['key']
            );
            $savedMonth = pettyCashParseMonth(substr((string) ($_POST['entry_date'] ?? ''), 0, 7), $today);
            redirectWithMessage(
                'petty-cash.php?month=' . rawurlencode($savedMonth['key']),
                'success',
                'এন্ট্রি আপডেট হয়েছে।'
            );
        }
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $existing = pettyCashFind($db, $id);
            if ($existing === null) {
                throw new RuntimeException('এন্ট্রিটি পাওয়া যায়নি।');
            }
            $savedMonth = pettyCashParseMonth(substr((string) $existing['entry_date'], 0, 7), $today);
            pettyCashDelete($db, $id);
            redirectWithMessage(
                'petty-cash.php?month=' . rawurlencode($savedMonth['key']),
                'success',
                'এন্ট্রি মুছে ফেলা হয়েছে।'
            );
        }
        if ($action === 'reorder') {
            $type = (string) ($_POST['entry_type'] ?? '');
            $idsRaw = (string) ($_POST['ordered_ids'] ?? '');
            $ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', $idsRaw) ?: []));
            pettyCashReorder($db, $type, $postMonth['from'], $postMonth['to'], array_values($ids));
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirectWithMessage(
                'petty-cash.php?month=' . rawurlencode($postMonth['key']),
                'success',
                'সাজানো সংরক্ষিত হয়েছে।'
            );
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        if ($action === 'reorder'
            && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => userFacingError($exception)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $editId = (int) ($_POST['id'] ?? 0);
        $back = 'petty-cash.php?month=' . rawurlencode($postMonth['key']);
        if ($editId > 0 && (string) ($_POST['action'] ?? '') === 'update') {
            $back .= '&edit=' . $editId;
        }
        $flashType = str_contains($exception->getMessage(), 'নির্বাচিত মাস') ? 'warning' : 'danger';
        redirectWithMessage($back, $flashType, userFacingError($exception));
    }
}

$income = pettyCashEntries($db, PETTY_CASH_INCOME, $month['from'], $month['to']);
$expense = pettyCashEntries($db, PETTY_CASH_EXPENSE, $month['from'], $month['to']);
$incomeTotal = pettyCashTotalPaisa($income);
$expenseTotal = pettyCashTotalPaisa($expense);
$diff = $incomeTotal - $expenseTotal;
$searchHits = $searchQuery !== '' ? pettyCashSearch($db, $searchQuery, 100) : [];

$format = (string) ($_GET['format'] ?? '');
if ($format === 'pdf') {
    pettyCashDownloadPdf($monthKey, $monthLabel, $month['from'], $month['to'], $income, $expense, $incomeTotal, $expenseTotal);
}
if ($format === 'print') {
    pettyCashRenderBrowserPrint($monthKey, $monthLabel, $month['from'], $month['to'], $income, $expense, $incomeTotal, $expenseTotal);
}

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? pettyCashFind($db, $editId) : null;
if ($editRow !== null) {
    $editMonth = pettyCashParseMonth(substr((string) $editRow['entry_date'], 0, 7), $today);
    if ($editMonth['key'] !== $monthKey) {
        redirectWithMessage('petty-cash.php?month=' . rawurlencode($editMonth['key']) . '&edit=' . $editId, 'info', 'এন্ট্রির মাসে নিয়ে যাওয়া হলো।');
    }
}

$basePath = '../../';
$pageTitle = 'Petty Cash Book | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
$pdfUrl = 'petty-cash.php?month=' . rawurlencode($monthKey) . '&format=pdf';
$printUrl = 'petty-cash.php?month=' . rawurlencode($monthKey) . '&format=print';
$defaultDate = $isCurrentMonth ? $today->format('Y-m-d') : $month['from'];
$monthMin = $month['from'];
$monthMax = $month['to'];
$editConfirmMsg = 'এই এন্ট্রি সম্পাদনা করতে চান?';
$deleteConfirmMsg = 'এই এন্ট্রি মুছে ফেলতে চান? এটি ফিরিয়ে আনা যাবে না।';
$createConfirmMsg = $isCurrentMonth
    ? ''
    : 'পুরনো মাস (' . $monthLabel . ') এ নতুন আয়/ব্যয় এন্ট্রি যোগ করতে যাচ্ছেন। আপনি কি নিশ্চিত?';
$updateConfirmMsg = 'এন্ট্রি আপডেট নিশ্চিত করবেন?';

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
              <div>
                <h3 class="mb-1">Petty Cash Book</h3>
                <p class="text-secondary mb-0">দৈনিক ক্ষুদ্র আয়-ব্যয়ের আলাদা খাতা — মূল হিসাবের খাতার সাথে মেশে না।</p>
              </div>
              <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">← ড্যাশবোর্ড</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= h($printUrl) ?>" target="_blank" rel="noopener">প্রিন্ট</a>
                <a class="btn btn-outline-secondary btn-sm" href="<?= h($pdfUrl) ?>">PDF</a>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <form method="get" class="card card-body mb-3">
              <div class="row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label" for="petty-month">মাস</label>
                  <input class="form-control" type="month" id="petty-month" name="month" value="<?= h($monthKey) ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="petty-search">আয়/ব্যয় খুঁজুন</label>
                  <input class="form-control" type="search" id="petty-search" name="q" value="<?= h($searchQuery) ?>" placeholder="বিবরণ, তারিখ বা টাকার অংক" autocomplete="off">
                </div>
                <div class="col-md-5 d-flex flex-wrap gap-1">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button>
                  <a class="btn btn-outline-secondary" href="petty-cash.php">চলতি মাস</a>
                  <?php if ($searchQuery !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?= h('petty-cash.php?month=' . rawurlencode($monthKey)) ?>">সার্চ মুছুন</a>
                  <?php endif; ?>
                </div>
              </div>
            </form>

            <?php if ($searchQuery !== ''): ?>
              <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <h3 class="card-title mb-0">সার্চ ফলাফল · “<?= h($searchQuery) ?>”</h3>
                  <span class="small text-secondary"><?= h(bnDigits((string) count($searchHits))) ?>টি মিল</span>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                      <thead>
                        <tr>
                          <th>তারিখ</th>
                          <th>ধরন</th>
                          <th>বিবরণ</th>
                          <th class="text-end">টাকা</th>
                          <th class="text-center">মাস</th>
                          <?php if ($canWrite): ?><th class="text-center">কর্ম</th><?php endif; ?>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if ($searchHits === []): ?>
                          <tr><td colspan="<?= $canWrite ? 6 : 5 ?>" class="text-secondary p-3">কোনো মিল পাওয়া যায়নি।</td></tr>
                        <?php endif; ?>
                        <?php foreach ($searchHits as $hit): ?>
                          <?php
                          $hitMonth = pettyCashParseMonth(substr((string) $hit['entry_date'], 0, 7), $today);
                          $hitMonthUrl = 'petty-cash.php?month=' . rawurlencode($hitMonth['key']);
                          $isIncome = (string) $hit['entry_type'] === PETTY_CASH_INCOME;
                          ?>
                          <tr>
                            <td class="text-nowrap"><?= h(accFormatDate((string) $hit['entry_date'])) ?></td>
                            <td><?= $isIncome ? 'আয়' : 'ব্যয়' ?></td>
                            <td><?= h((string) $hit['description']) ?></td>
                            <td class="text-end text-nowrap"><?= h(pettyCashFormatAmount(Money::fromDb($hit['amount']))) ?></td>
                            <td class="text-center"><a href="<?= h($hitMonthUrl) ?>"><?= h($hitMonth['label']) ?></a></td>
                            <?php if ($canWrite): ?>
                              <td class="text-center text-nowrap">
                                <a class="btn btn-link btn-sm p-0 text-primary"
                                   href="<?= h($hitMonthUrl . '&edit=' . (int) $hit['id'] . '&q=' . rawurlencode($searchQuery)) ?>"
                                   title="এডিট" aria-label="এডিট"
                                   onclick='return confirm(<?= json_encode($editConfirmMsg, JSON_UNESCAPED_UNICODE) ?>);'>
                                  <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                                <form method="post" class="d-inline" data-acc-confirm="<?= h($deleteConfirmMsg) ?>" data-acc-confirm-variant="danger" data-acc-confirm-ok="হ্যাঁ, মুছুন">
                                  <?= csrfField() ?>
                                  <input type="hidden" name="action" value="delete">
                                  <input type="hidden" name="id" value="<?= (int) $hit['id'] ?>">
                                  <input type="hidden" name="month" value="<?= h($hitMonth['key']) ?>">
                                  <button class="btn btn-link btn-sm p-0 text-danger" type="submit" title="ডিলিট" aria-label="ডিলিট">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                  </button>
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
            <?php endif; ?>

            <?php if ($editRow !== null && $canWrite): ?>
              <div class="card mb-3 border-primary">
                <div class="card-header"><h3 class="card-title mb-0">এন্ট্রি সম্পাদনা — <?= h($editRow['entry_type'] === PETTY_CASH_INCOME ? 'আয়' : 'ব্যয়') ?></h3></div>
                <div class="card-body">
                  <form method="post" class="row g-2 align-items-end js-petty-entry-form"
                        data-selected-month="<?= h($monthKey) ?>"
                        data-selected-label="<?= h($monthLabel) ?>"
                        data-confirm-msg="<?= h($updateConfirmMsg) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                    <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                    <div class="col-md-2">
                      <label class="form-label" for="edit-date">তারিখ</label>
                      <input class="form-control" type="date" id="edit-date" name="entry_date" required min="<?= h($monthMin) ?>" max="<?= h($monthMax) ?>" value="<?= h((string) $editRow['entry_date']) ?>">
                    </div>
                    <div class="col-md-5">
                      <label class="form-label" for="edit-description"><?= $editRow['entry_type'] === PETTY_CASH_INCOME ? 'আয়ের খাত / বিবরণ' : 'ব্যয়ের খাত / বিবরণ' ?></label>
                      <input class="form-control" type="text" id="edit-description" name="description" maxlength="255" required value="<?= h((string) $editRow['description']) ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label" for="edit-amount">টাকার অংক</label>
                      <input class="form-control" type="text" id="edit-amount" name="amount" inputmode="numeric" required value="<?= h((string) intdiv(Money::fromDb($editRow['amount']), 100)) ?>">
                    </div>
                    <div class="col-md-3 d-flex flex-wrap gap-1">
                      <button class="btn btn-primary" type="submit">আপডেট</button>
                      <a class="btn btn-outline-secondary" href="<?= h($redirectBase) ?>">বাতিল</a>
                    </div>
                  </form>
                </div>
              </div>
            <?php elseif ($canWrite): ?>
              <div class="row g-3 mb-3">
                <div class="col-lg-6">
                  <div class="card h-100">
                    <div class="card-header"><h3 class="card-title mb-0">আয় এন্ট্রি</h3></div>
                    <div class="card-body">
                      <form method="post" class="row g-2 js-petty-entry-form"
                            data-selected-month="<?= h($monthKey) ?>"
                            data-selected-label="<?= h($monthLabel) ?>"
                            data-confirm-msg="<?= h($createConfirmMsg) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="entry_type" value="<?= h(PETTY_CASH_INCOME) ?>">
                        <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                        <div class="col-md-4">
                          <label class="form-label" for="income-date">তারিখ</label>
                          <input class="form-control" type="date" id="income-date" name="entry_date" required min="<?= h($monthMin) ?>" max="<?= h($monthMax) ?>" value="<?= h($defaultDate) ?>">
                        </div>
                        <div class="col-md-8">
                          <label class="form-label" for="income-description">আয়ের খাত / বিবরণ</label>
                          <input class="form-control" type="text" id="income-description" name="description" maxlength="255" required placeholder="যেমন: চা-নাস্তা তহবিল">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="income-amount">টাকার অংক</label>
                          <input class="form-control" type="text" id="income-amount" name="amount" inputmode="numeric" required placeholder="১২৫০">
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
                      <form method="post" class="row g-2 js-petty-entry-form"
                            data-selected-month="<?= h($monthKey) ?>"
                            data-selected-label="<?= h($monthLabel) ?>"
                            data-confirm-msg="<?= h($createConfirmMsg) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="entry_type" value="<?= h(PETTY_CASH_EXPENSE) ?>">
                        <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                        <div class="col-md-4">
                          <label class="form-label" for="expense-date">তারিখ</label>
                          <input class="form-control" type="date" id="expense-date" name="entry_date" required min="<?= h($monthMin) ?>" max="<?= h($monthMax) ?>" value="<?= h($defaultDate) ?>">
                        </div>
                        <div class="col-md-8">
                          <label class="form-label" for="expense-description">ব্যয়ের খাত / বিবরণ</label>
                          <input class="form-control" type="text" id="expense-description" name="description" maxlength="255" required placeholder="যেমন: স্টেশনারি">
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="expense-amount">টাকার অংক</label>
                          <input class="form-control" type="text" id="expense-amount" name="amount" inputmode="numeric" required placeholder="১২৫০">
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
                <h3 class="card-title mb-0">মাসিক খাতা · <?= h($monthLabel) ?></h3>
                <span class="small text-secondary">
                  <?= h(accFormatDate($month['from'])) ?> — <?= h(accFormatDate($month['to'])) ?>
                  <?php if ($canWrite): ?> · সারিতে ধরে টেনে সাজাতে পারবেন<?php endif; ?>
                </span>
              </div>
              <div class="card-body p-0">
                <div class="row g-0 acc-petty-split">
                  <div class="col-md-6 border-end">
                      <table class="table table-sm table-bordered align-middle mb-0 acc-petty-table">
                        <thead>
                          <tr><th colspan="<?= $canWrite ? 5 : 3 ?>" class="text-center acc-petty-income-head">আয়ের খাত সমূহ</th></tr>
                          <tr>
                            <?php if ($canWrite): ?><th class="text-center" style="width:2rem"></th><?php endif; ?>
                            <th class="text-center">তারিখ</th>
                            <th>বিবরণ</th>
                            <th class="text-end">টাকা</th>
                            <?php if ($canWrite): ?><th class="text-center">কর্ম</th><?php endif; ?>
                          </tr>
                        </thead>
                        <tbody class="<?= $canWrite ? 'js-petty-sortable' : '' ?>"
                               data-type="<?= h(PETTY_CASH_INCOME) ?>"
                               data-month="<?= h($monthKey) ?>">
                          <?php if ($income === []): ?>
                            <tr class="js-petty-empty"><td colspan="<?= $canWrite ? 5 : 3 ?>" class="text-secondary p-3">এই মাসে কোনো আয় নেই।</td></tr>
                          <?php endif; ?>
                          <?php foreach ($income as $left): ?>
                            <tr draggable="<?= $canWrite ? 'true' : 'false' ?>" data-id="<?= (int) $left['id'] ?>" class="<?= $canWrite ? 'acc-petty-drag-row' : '' ?>">
                              <?php if ($canWrite): ?>
                                <td class="text-center acc-petty-drag-handle" title="টেনে সাজান"><i class="bi bi-grip-vertical" aria-hidden="true"></i></td>
                              <?php endif; ?>
                              <td class="text-nowrap text-center"><?= h(accFormatDate((string) $left['entry_date'])) ?></td>
                              <td><?= h((string) $left['description']) ?></td>
                              <td class="text-end text-nowrap"><?= h(pettyCashFormatAmount(Money::fromDb($left['amount']))) ?></td>
                              <?php if ($canWrite): ?>
                                <td class="text-center text-nowrap">
                                  <a class="btn btn-link btn-sm p-0 text-primary"
                                     href="<?= h($redirectBase . '&edit=' . (int) $left['id']) ?>"
                                     title="এডিট" aria-label="এডিট"
                                     onclick='return confirm(<?= json_encode($editConfirmMsg, JSON_UNESCAPED_UNICODE) ?>);'>
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                  </a>
                                  <form method="post" class="d-inline" data-acc-confirm="<?= h($deleteConfirmMsg) ?>" data-acc-confirm-variant="danger" data-acc-confirm-ok="হ্যাঁ, মুছুন">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $left['id'] ?>">
                                    <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                                    <button class="btn btn-link btn-sm p-0 text-danger" type="submit" title="ডিলিট" aria-label="ডিলিট">
                                      <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                  </form>
                                </td>
                              <?php endif; ?>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                          <tr class="fw-semibold">
                            <td colspan="<?= $canWrite ? 3 : 2 ?>">মোট আয়</td>
                            <td class="text-end text-nowrap"><?= h(pettyCashFormatAmount($incomeTotal)) ?></td>
                            <?php if ($canWrite): ?><td></td><?php endif; ?>
                          </tr>
                        </tfoot>
                      </table>
                  </div>
                  <div class="col-md-6">
                      <table class="table table-sm table-bordered align-middle mb-0 acc-petty-table">
                        <thead>
                          <tr><th colspan="<?= $canWrite ? 5 : 3 ?>" class="text-center acc-petty-expense-head">ব্যয়ের খাত সমূহ</th></tr>
                          <tr>
                            <?php if ($canWrite): ?><th class="text-center" style="width:2rem"></th><?php endif; ?>
                            <th class="text-center">তারিখ</th>
                            <th>বিবরণ</th>
                            <th class="text-end">টাকা</th>
                            <?php if ($canWrite): ?><th class="text-center">কর্ম</th><?php endif; ?>
                          </tr>
                        </thead>
                        <tbody class="<?= $canWrite ? 'js-petty-sortable' : '' ?>"
                               data-type="<?= h(PETTY_CASH_EXPENSE) ?>"
                               data-month="<?= h($monthKey) ?>">
                          <?php if ($expense === []): ?>
                            <tr class="js-petty-empty"><td colspan="<?= $canWrite ? 5 : 3 ?>" class="text-secondary p-3">এই মাসে কোনো ব্যয় নেই।</td></tr>
                          <?php endif; ?>
                          <?php foreach ($expense as $right): ?>
                            <tr draggable="<?= $canWrite ? 'true' : 'false' ?>" data-id="<?= (int) $right['id'] ?>" class="<?= $canWrite ? 'acc-petty-drag-row' : '' ?>">
                              <?php if ($canWrite): ?>
                                <td class="text-center acc-petty-drag-handle" title="টেনে সাজান"><i class="bi bi-grip-vertical" aria-hidden="true"></i></td>
                              <?php endif; ?>
                              <td class="text-nowrap text-center"><?= h(accFormatDate((string) $right['entry_date'])) ?></td>
                              <td><?= h((string) $right['description']) ?></td>
                              <td class="text-end text-nowrap"><?= h(pettyCashFormatAmount(Money::fromDb($right['amount']))) ?></td>
                              <?php if ($canWrite): ?>
                                <td class="text-center text-nowrap">
                                  <a class="btn btn-link btn-sm p-0 text-primary"
                                     href="<?= h($redirectBase . '&edit=' . (int) $right['id']) ?>"
                                     title="এডিট" aria-label="এডিট"
                                     onclick='return confirm(<?= json_encode($editConfirmMsg, JSON_UNESCAPED_UNICODE) ?>);'>
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                  </a>
                                  <form method="post" class="d-inline" data-acc-confirm="<?= h($deleteConfirmMsg) ?>" data-acc-confirm-variant="danger" data-acc-confirm-ok="হ্যাঁ, মুছুন">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $right['id'] ?>">
                                    <input type="hidden" name="month" value="<?= h($monthKey) ?>">
                                    <button class="btn btn-link btn-sm p-0 text-danger" type="submit" title="ডিলিট" aria-label="ডিলিট">
                                      <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                  </form>
                                </td>
                              <?php endif; ?>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                          <tr class="fw-semibold">
                            <td colspan="<?= $canWrite ? 3 : 2 ?>">মোট ব্যয়</td>
                            <td class="text-end text-nowrap"><?= h(pettyCashFormatAmount($expenseTotal)) ?></td>
                            <?php if ($canWrite): ?><td></td><?php endif; ?>
                          </tr>
                        </tfoot>
                      </table>
                  </div>
                </div>
              </div>
              <div class="card-footer">
                <?php if ($diff === 0): ?>
                  <div class="acc-petty-balanced">আয়-ব্যয়ের দুই পাশ মিলেছে।</div>
                <?php else: ?>
                  <div class="acc-petty-diff">
                    পার্থক্য: <?= h(pettyCashFormatAmount(abs($diff))) ?>
                    <?= $diff > 0 ? '(আয় বেশি)' : '(ব্যয় বেশি)' ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
<script>
(function () {
  var pettyCsrf = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('.js-petty-entry-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var dateInput = form.querySelector('input[name="entry_date"]');
      if (!dateInput || !dateInput.value) {
        return;
      }
      var selectedMonth = form.getAttribute('data-selected-month') || '';
      var selectedLabel = form.getAttribute('data-selected-label') || 'নির্বাচিত মাস';
      if (dateInput.value.slice(0, 7) !== selectedMonth) {
        event.preventDefault();
        window.alert('শুধুমাত্র নির্বাচিত মাস (' + selectedLabel + ') এর তারিখে আয়-ব্যয় এন্ট্রি করা যাবে।');
        dateInput.focus();
        return;
      }
      var confirmMsg = form.getAttribute('data-confirm-msg') || '';
      if (confirmMsg !== '' && !window.confirm(confirmMsg)) {
        event.preventDefault();
      }
    });
  });

  var dragRow = null;
  document.querySelectorAll('.js-petty-sortable').forEach(function (tbody) {
    tbody.addEventListener('dragstart', function (event) {
      var row = event.target.closest('tr[data-id]');
      if (!row || !tbody.contains(row)) {
        return;
      }
      dragRow = row;
      row.classList.add('acc-petty-dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.getAttribute('data-id') || '');
      }
    });
    tbody.addEventListener('dragend', function () {
      if (dragRow) {
        dragRow.classList.remove('acc-petty-dragging');
      }
      dragRow = null;
      tbody.querySelectorAll('.acc-petty-drag-over').forEach(function (el) {
        el.classList.remove('acc-petty-drag-over');
      });
    });
    tbody.addEventListener('dragover', function (event) {
      event.preventDefault();
      if (!dragRow || dragRow.parentElement !== tbody) {
        return;
      }
      var target = event.target.closest('tr[data-id]');
      if (!target || target === dragRow) {
        return;
      }
      tbody.querySelectorAll('.acc-petty-drag-over').forEach(function (el) {
        el.classList.remove('acc-petty-drag-over');
      });
      target.classList.add('acc-petty-drag-over');
      var rect = target.getBoundingClientRect();
      var before = (event.clientY - rect.top) < rect.height / 2;
      tbody.insertBefore(dragRow, before ? target : target.nextSibling);
    });
    tbody.addEventListener('drop', function (event) {
      event.preventDefault();
      if (!dragRow || dragRow.parentElement !== tbody) {
        return;
      }
      tbody.querySelectorAll('.acc-petty-drag-over').forEach(function (el) {
        el.classList.remove('acc-petty-drag-over');
      });
      var empty = tbody.querySelector('.js-petty-empty');
      if (empty) {
        empty.remove();
      }
      var ids = Array.prototype.map.call(tbody.querySelectorAll('tr[data-id]'), function (row) {
        return row.getAttribute('data-id');
      });
      var body = new URLSearchParams();
      body.set('action', 'reorder');
      body.set('entry_type', tbody.getAttribute('data-type') || '');
      body.set('month', tbody.getAttribute('data-month') || '');
      body.set('ordered_ids', ids.join(','));
      body.set('csrf_token', pettyCsrf);
      fetch('petty-cash.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': pettyCsrf
        },
        body: body.toString(),
        credentials: 'same-origin'
      }).then(function (response) {
        return response.text().then(function (text) {
          var data = null;
          try { data = JSON.parse(text); } catch (e) { data = null; }
          if (!response.ok || !data || !data.ok) {
            var message = (data && data.message) || text || 'সাজানো সংরক্ষণ হয়নি।';
            throw new Error(message);
          }
        });
      }).catch(function (error) {
        window.alert(error.message || 'সাজানো সংরক্ষণ হয়নি।');
        window.location.reload();
      });
    });
  });
})();
</script>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
