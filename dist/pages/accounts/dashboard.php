<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Access\Role;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\SimpleBooks;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$db = getDatabaseConnection();
$basePath = '../../';
$pageTitle = 'আজকের কাজ | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
$chartData = null;
if ($access->can(Capability::REPORTS_VIEW_ALL) || $access->can(Capability::REPORTS_VIEW_CLASS)) {
    $chartData = accReports()->charts($access);
    $pageScriptFiles[] = $basePath . 'assets/vendor/chartjs/chart.umd.min.js';
    $pageScriptFiles[] = accAssetUrl($basePath, 'js/accounts-charts.js');
}

$officerCount = $access->hasSchoolWideScope() ? count(accOfficers()->activeOfficers()) : null;
$pendingApprovals = $access->can(Capability::CONCESSION_APPROVE) ? accPendingApprovalCount() : null;
$todayCollection = null;
if ($access->can(Capability::FEES_COLLECT)) {
    $todayCollection = 0;
    $todayDate = accToday()->format('Y-m-d');
    foreach (accPayments()->search(['from' => $todayDate, 'to' => $todayDate, 'collector' => $access->hasSchoolWideScope() ? 0 : $access->teacherId(), 'status' => 'confirmed']) as $row) {
        $todayCollection += Money::fromDb($row['amount']);
    }
}
$myCustody = $access->can(Capability::HANDOVER_SUBMIT)
    ? array_sum(array_map(static fn (array $row): int => Money::fromDb($row['amount']), accHandovers()->pendingPayments($access->teacherId())))
    : null;
$handoversWaiting = $access->can(Capability::HANDOVER_RECEIVE) ? accHandovers()->submittedCount() : null;
$cashBalance = null;
$bankBalance = null;
$flow = null;
if ($access->can(Capability::REPORTS_VIEW_ALL)) {
    $cashBalance = accChart()->balance(accChart()->idOf(ChartOfAccounts::CASH));
    $bankBalance = 0;
    foreach (accChart()->bankAccounts() as $bankAccount) {
        $bankBalance += accChart()->balance((int) $bankAccount['id']);
    }
    $todayDate = accToday()->format('Y-m-d');
    $flow = [
        'আজ' => accReports()->income($todayDate, $todayDate),
        'এই মাস' => accReports()->income(accToday()->format('Y-m-01'), accToday()->format('Y-m-t')),
        'এই বছর' => accReports()->income(accToday()->format('Y-01-01'), accToday()->format('Y-12-31')),
    ];
}
$dueTotal = null;
if ($access->can(Capability::FEES_VIEW_CLASS) || $access->can(Capability::FEES_VIEW_ALL)) {
    $dueTotal = 0;
    foreach (accReports()->dues($access, $access->sessionYear()) as $dueRow) {
        $dueTotal += $dueRow['due'];
    }
}
$recentPayments = [];
if ($access->can(Capability::FEES_COLLECT) || $access->can(Capability::FEES_VIEW_ALL)) {
    $recentPayments = accPayments()->search([
        'from' => accToday()->modify('-60 days')->format('Y-m-d'),
        'to' => accToday()->format('Y-m-d'),
        'collector' => $access->hasSchoolWideScope() ? 0 : $access->teacherId(),
    ], 8);
}
$staffAdvance = null;
if ($access->can(Capability::REPORTS_VIEW_ALL)) {
    $staffAdvance = accChart()->balance(accChart()->idOf(SimpleBooks::STAFF_ADVANCE));
}
$vouchersWaiting = $access->can(Capability::VOUCHER_APPROVE) ? accVouchers()->submittedCount() + accBank()->submittedCount() : null;
$bkashBalance = null;
$nagadBalance = null;
if ($access->can(Capability::REPORTS_VIEW_ALL)) {
    $bkashBalance = accChart()->balance(accChart()->idOf(ChartOfAccounts::BKASH));
    $nagadBalance = accChart()->balance(accChart()->idOf(ChartOfAccounts::NAGAD));
}
$onlineReview = ($access->can(Capability::GATEWAY_SETTINGS) || $access->can(Capability::REFUND_REQUEST)) ? accOnline()->attentionCount() : null;
$onlinePending = $onlineReview !== null
    ? (int) $db->query("SELECT COUNT(*) FROM acc_gateway_transactions WHERE status IN ('pending', 'initiated')")->fetchColumn()
    : null;
$refundsWaiting = $access->can(Capability::REFUND_APPROVE) ? accRefunds()->waitingCount() : null;
$settlementsWaiting = $access->can(Capability::VOUCHER_APPROVE) ? accSettlements()->submittedCount() : null;

$classNames = [];
foreach (schoolClasses($db) as $class) {
    $classNames[(int) $class['id']] = trim((string) ($class['class_name_ban'] ?? '')) ?: (string) ($class['class_name'] ?? '');
}
$sectionNames = [];
foreach (schoolSections($db) as $section) {
    $sectionNames[(int) $section['id']] = trim((string) ($section['section_name_ban'] ?? '')) ?: (string) ($section['section_name'] ?? '');
}
$assignedGroups = [];
if ($access->hasRole(Role::CLASS_TEACHER)) {
    $scope = $access->classScope();
    foreach (array_keys($scope['entire']) as $classId) {
        $assignedGroups[] = ($classNames[$classId] ?? ('#' . $classId)) . ' (পুরো শ্রেণী)';
    }
    foreach ($scope['sections'] as $classId => $sections) {
        foreach (array_keys($sections) as $sectionId) {
            $assignedGroups[] = ($classNames[$classId] ?? ('#' . $classId)) . ' — ' . ($sectionNames[$sectionId] ?? ('#' . $sectionId));
        }
    }
}

$steps = [];
if ($access->can(Capability::OFFICERS_MANAGE)) {
    $steps[] = ['হিসাব রক্ষক', 'হিসাবের কাজ তাঁর মাধ্যমে চলবে। আপনি নিয়োগ দেন এবং প্রত্যাহার করেন।', 'officers.php', 'নিয়োগ দিন'];
}
if ($access->can(Capability::VOUCHER_APPROVE)) {
    $steps[] = ['যাচাই', 'ছাড়, ভাউচার, ব্যাংক জমা ও টাকা ফেরত মিলিয়ে অনুমোদন করুন। এন্ট্রি হিসাব রক্ষক করেন।', 'vouchers.php?status=submitted', 'যাচাই করুন'];
}
if ($access->can(Capability::FEE_STRUCTURE_MANAGE)) {
    $steps[] = ['শ্রেণীর হার', 'বছরের শুরুতে একবার। সাধারণ শিক্ষার্থী ও বৃত্তি/উপবৃত্তির টাকা আলাদা দিন।', 'fee-structure.php', 'হার দেখুন'];
}
if ($access->can(Capability::INVOICE_MANAGE)) {
    $steps[] = ['এই মাসের বিল', 'প্রতি মাসে শ্রেণী বেছে বিল তৈরি করুন। বিল না থাকলে বকেয়া তালিকা খালি থাকে। একই মাস দুবার বিল হয় না।', 'invoices.php', 'বিল তৈরি'];
}
if ($access->can(Capability::FEES_VIEW_CLASS)) {
    $steps[] = ['বকেয়া', 'শ্রেণী ও শাখা বাছলে কার কত বাকি তা দেখায়।', 'dues.php', 'বকেয়া দেখুন'];
}
if ($access->can(Capability::FEES_COLLECT)) {
    $steps[] = ['টাকা নিন', 'শিক্ষার্থী খুলে বকেয়া বেছে নগদ নিন। একই ফর্ম দুবার দিলে নতুন রসিদ হয় না।', 'collect.php', 'ফি আদায়'];
}
if ($access->can(Capability::HANDOVER_SUBMIT) && !$access->hasSchoolWideScope()) {
    $steps[] = ['টাকা জমা', 'নেওয়া নগদ অফিসে জমা দিন। গ্রহণ না হওয়া পর্যন্ত টাকা আপনার কাছে দেখায়।', 'handovers.php', 'জমা দিন'];
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
              <div>
                <h3 class="mb-1">আজকের কাজ</h3>
                <p class="text-secondary mb-0">স্বাগতম, <?= h($access->teacherName()) ?>। <?= $access->can(Capability::OFFICERS_MANAGE) ? 'আপনি হিসাব রক্ষক নিয়োগ দেন এবং সব হিসাব দেখে যাচাই করেন। এন্ট্রি, বিল ও আদায় হিসাব রক্ষক করেন।' : 'প্রতিদিনের কাজ বাঁ দিকের প্রথম অংশে। হিসাবের খাতা আলাদা অংশে, দরকার হলে খুলবেন।' ?></p>
              </div>
              <div class="acc-role-badges d-flex flex-wrap gap-1">
                <?php foreach ($access->roles() as $role): ?>
                  <span class="badge rounded-pill <?= h(accRoleBadgeClass($role)) ?>"><?= h(Role::label($role)) ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <?php if ($steps !== []): ?>
            <div class="acc-steps mb-3">
              <?php foreach ($steps as $index => [$title, $text, $href, $action]): ?>
                <div class="acc-step">
                  <div class="acc-step-no"><?= h(bnDigits((string) ($index + 1))) ?>. <?= h($title) ?></div>
                  <p class="text-secondary small mb-2"><?= h($text) ?></p>
                  <a class="btn btn-sm btn-primary align-self-start" href="<?= h($href) ?>"><?= h($action) ?></a>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
              <?php if ($access->can(Capability::FEES_COLLECT)): ?><a class="btn btn-primary" href="collect.php">ফি আদায়</a><?php endif; ?>
              <?php if ($access->can(Capability::VOUCHER_CREATE)): ?><a class="btn btn-primary" href="expense.php">খরচ যোগ</a><?php endif; ?>
              <?php if ($access->can(Capability::REPORTS_VIEW_ALL) || $access->can(Capability::REPORTS_VIEW_CLASS)): ?><a class="btn btn-outline-primary" href="reports.php">রিপোর্ট</a><?php endif; ?>
              <?php if ($access->can(Capability::REPORTS_VIEW_ALL) || $access->can(Capability::VOUCHER_CREATE)): ?><a class="btn btn-outline-primary" href="books.php">ক্যাশ ও ব্যাংক</a><?php endif; ?>
            </div>

            <?php if ($flow !== null): ?>
            <div class="row g-3 mb-3">
              <?php foreach ($flow as $label => $row): ?>
                <div class="col-md-4">
                  <div class="card h-100">
                    <div class="card-header"><h3 class="card-title mb-0"><?= h($label) ?></h3></div>
                    <div class="card-body">
                      <div class="d-flex justify-content-between"><span>আয়</span><strong class="text-success"><?= h(Money::format($row['income_total'])) ?></strong></div>
                      <div class="d-flex justify-content-between"><span>খরচ</span><strong><?= h(Money::format($row['expense_total'])) ?></strong></div>
                      <div class="d-flex justify-content-between border-top mt-2 pt-2"><span>নিট</span><strong><?= h(Money::format($row['surplus'])) ?></strong></div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3">
                <div class="acc-stat">
                  <div class="acc-stat-label">চলতি সেশন</div>
                  <div class="acc-stat-value"><?= h(bnDigits($access->sessionYear())) ?></div>
                </div>
              </div>
              <?php if ($officerCount !== null): ?>
              <div class="col-6 col-lg-3">
                <div class="acc-stat">
                  <div class="acc-stat-label">সক্রিয় হিসাব রক্ষক</div>
                  <div class="acc-stat-value"><?= h(bnDigits((string) $officerCount)) ?> জন</div>
                </div>
              </div>
              <?php endif; ?>
              <?php if ($dueTotal !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="dues.php">
                  <div class="acc-stat-label">মোট বকেয়া ফি</div>
                  <div class="acc-stat-value"><?= h(Money::format($dueTotal)) ?></div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($todayCollection !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="payments.php">
                  <div class="acc-stat-label"><?= $access->hasSchoolWideScope() ? 'আজকের মোট আদায়' : 'আজ আমার আদায়' ?></div>
                  <div class="acc-stat-value text-success"><?= h(Money::format($todayCollection)) ?></div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($myCustody !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="handovers.php">
                  <div class="acc-stat-label">আমার কাছে (হস্তান্তর বাকি)</div>
                  <div class="acc-stat-value<?= $myCustody > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(Money::format($myCustody)) ?></div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($handoversWaiting !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="handovers.php">
                  <div class="acc-stat-label">গ্রহণের অপেক্ষায় হস্তান্তর</div>
                  <div class="acc-stat-value<?= $handoversWaiting > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(bnDigits((string) $handoversWaiting)) ?>টি</div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($cashBalance !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="books.php">
                  <div class="acc-stat-label">নগদ</div>
                  <div class="acc-stat-value"><?= h(Money::format($cashBalance)) ?></div>
                </a>
              </div>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="books.php">
                  <div class="acc-stat-label">ব্যাংক</div>
                  <div class="acc-stat-value"><?= h(Money::format($bankBalance)) ?></div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($bkashBalance !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="books.php">
                  <div class="acc-stat-label">বিকাশ</div>
                  <div class="acc-stat-value"><?= h(Money::format($bkashBalance)) ?></div>
                </a>
              </div>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="books.php">
                  <div class="acc-stat-label">নগদ (Nagad)</div>
                  <div class="acc-stat-value"><?= h(Money::format($nagadBalance)) ?></div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($onlinePending !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="online-payments.php?status=pending">
                  <div class="acc-stat-label">পেন্ডিং অনলাইন পেমেন্ট</div>
                  <div class="acc-stat-value<?= $onlinePending > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(bnDigits((string) $onlinePending)) ?>টি</div>
                </a>
              </div>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="online-payments.php?status=review">
                  <div class="acc-stat-label">যাচাই প্রয়োজন</div>
                  <div class="acc-stat-value<?= $onlineReview > 0 ? ' text-danger' : '' ?>"><?= h(bnDigits((string) $onlineReview)) ?>টি</div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($refundsWaiting !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="refunds.php">
                  <div class="acc-stat-label">রিফান্ডের সিদ্ধান্ত বাকি</div>
                  <div class="acc-stat-value<?= $refundsWaiting > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(bnDigits((string) $refundsWaiting)) ?>টি</div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($settlementsWaiting !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="settlements.php">
                  <div class="acc-stat-label">সেটেলমেন্ট যাচাই বাকি</div>
                  <div class="acc-stat-value<?= $settlementsWaiting > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(bnDigits((string) $settlementsWaiting)) ?>টি</div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($vouchersWaiting !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="vouchers.php?status=submitted">
                  <div class="acc-stat-label">অনুমোদনের অপেক্ষায় ভাউচার ও ব্যাংক জমা</div>
                  <div class="acc-stat-value<?= $vouchersWaiting > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(bnDigits((string) $vouchersWaiting)) ?>টি</div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($pendingApprovals !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="approvals.php">
                  <div class="acc-stat-label">অনুমোদনের অপেক্ষায়</div>
                  <div class="acc-stat-value<?= $pendingApprovals > 0 ? ' text-warning-emphasis' : '' ?>"><?= h(bnDigits((string) $pendingApprovals)) ?>টি আবেদন</div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($access->hasRole(Role::CLASS_TEACHER)): ?>
              <div class="col-6 col-lg-3">
                <div class="acc-stat">
                  <div class="acc-stat-label">দায়িত্বপ্রাপ্ত শ্রেণী/শাখা</div>
                  <div class="acc-stat-value"><?= h(bnDigits((string) count($assignedGroups))) ?>টি</div>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <?php if ($staffAdvance !== null && $staffAdvance > 0): ?>
            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="<?= $access->can(Capability::VOUCHER_CREATE) ? 'expense.php' : 'vouchers.php' ?>">
                  <div class="acc-stat-label">কর্মচারী অগ্রিম বকেয়া</div>
                  <div class="acc-stat-value"><?= h(Money::format($staffAdvance)) ?></div>
                </a>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($recentPayments !== []): ?>
            <div class="card mb-3">
              <div class="card-header"><h3 class="card-title mb-0">সাম্প্রতিক আদায়</h3></div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>রসিদ</th><th>শিক্ষার্থী</th><th>মাধ্যম</th><th class="text-end">টাকা</th></tr></thead>
                  <tbody>
                    <?php foreach ($recentPayments as $payment): ?>
                      <tr>
                        <td><a href="<?= h(signedRecordUrl('receipt.php', (int) $payment['id'], 'acc_payment')) ?>"><?= h((string) $payment['receipt_no']) ?></a></td>
                        <td><?= h((string) $payment['name_snapshot']) ?></td>
                        <td><?= h(\School\Accounting\Collection\PaymentService::METHOD_LABELS[$payment['method']] ?? (string) $payment['method']) ?></td>
                        <td class="text-end"><?= h(Money::format(Money::fromDb($payment['amount']))) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($chartData !== null): ?>
            <div class="row g-3 mb-3">
              <div class="col-lg-6">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">গত ছয় মাসের আদায়</h3></div>
                  <div class="card-body"><canvas id="acc-chart-collection" height="140"></canvas></div>
                </div>
              </div>
              <?php if ($chartData['income'] !== []): ?>
              <div class="col-lg-6">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">গত ছয় মাসের আয় ও ব্যয়</h3></div>
                  <div class="card-body"><canvas id="acc-chart-flow" height="140"></canvas></div>
                </div>
              </div>
              <?php endif; ?>
            </div>
            <script type="application/json" id="acc-chart-data"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
            <?php endif; ?>

            <?php if ($assignedGroups !== [] || $access->can(Capability::OFFICERS_MANAGE)): ?>
            <div class="row g-3 mb-3">
              <?php if ($assignedGroups !== []): ?>
              <div class="col-lg-6">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">আপনার শ্রেণী</h3></div>
                  <ul class="list-group list-group-flush">
                    <?php foreach ($assignedGroups as $group): ?>
                      <li class="list-group-item"><i class="bi bi-people me-2 text-success"></i><?= h($group) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
              <?php endif; ?>
              <?php if ($access->can(Capability::OFFICERS_MANAGE)): ?>
              <div class="col-lg-6">
                <div class="card h-100">
                  <div class="card-body">
                    <h3 class="h6">হিসাব রক্ষক</h3>
                    <p class="text-secondary small mb-2">যিনি প্রতিদিন হিসাব লিখবেন, তাঁকে এখান থেকে নিয়োগ দিন।</p>
                    <a href="officers.php" class="btn btn-sm btn-primary"><i class="bi bi-person-gear me-1"></i>হিসাব রক্ষক নিয়োগ</a>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <details>
              <summary class="text-secondary">আমার অনুমতি</summary>
              <ul class="acc-cap-list mt-2">
                <?php foreach (Capability::labels() as $capability => $label): ?>
                  <?php $granted = $access->can($capability); ?>
                  <li class="<?= $granted ? '' : 'text-secondary' ?>">
                    <i class="bi <?= $granted ? 'bi-check-circle-fill' : 'bi-dash-circle' ?>" aria-hidden="true"></i>
                    <span>
                      <?= h($label) ?>
                      <?php if ($granted && Capability::isClassScoped($capability) && !$access->hasSchoolWideScope()): ?>
                        <small class="text-secondary">(শুধু নিজ শ্রেণী)</small>
                      <?php endif; ?>
                      <span class="visually-hidden"><?= $granted ? 'অনুমতি আছে' : 'অনুমতি নেই' ?></span>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </details>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
