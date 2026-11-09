<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Access\Role;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$db = getDatabaseConnection();
$basePath = '../../';
$pageTitle = 'হিসাব ড্যাশবোর্ড | School Admin';
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
if ($access->can(Capability::REPORTS_VIEW_ALL)) {
    $cashBalance = accChart()->balance(accChart()->idOf(ChartOfAccounts::CASH));
    $bankBalance = 0;
    foreach (accChart()->bankAccounts() as $bankAccount) {
        $bankBalance += accChart()->balance((int) $bankAccount['id']);
    }
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

$phases = [
    ['নকশা প্রস্তাব (ডেটাবেস স্কিমা, ER ডায়াগ্রাম, ফোল্ডার কাঠামো)', true],
    ['ধাপ ১ — অথেন্টিকেশন ও ভূমিকা', true],
    ['ধাপ ২ — ফি কাঠামো (সাধারণ ও বৃত্তি/উপবৃত্তি হার), ছাড় ও ইনভয়েস', true],
    ['ধাপ ৩ — নগদ আদায়, রসিদ ও হস্তান্তর', true],
    ['ধাপ ৪ — চার্ট অব অ্যাকাউন্টস, ভাউচার, ব্যাংক ও মাস সমাপনী', true],
    ['ধাপ ৫ — bKash (sandbox), Nagad অ্যাডাপ্টার, অভিভাবক পোর্টাল, রিফান্ড ও সেটেলমেন্ট', true],
    ['ধাপ ৬ — রিপোর্ট ও ড্যাশবোর্ড চার্ট', true],
    ['ধাপ ৭ — নিরাপত্তা যাচাই, টেস্ট প্ল্যান ও ব্যবহার নির্দেশিকা', true],
];

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
              <div>
                <h3 class="mb-1">হিসাব ও ফি ব্যবস্থাপনা</h3>
                <p class="text-secondary mb-0">স্বাগতম, <?= h($access->teacherName()) ?>। আপনার ভূমিকা অনুযায়ী নিচের কাজগুলো করতে পারবেন।</p>
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

            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3">
                <div class="acc-stat">
                  <div class="acc-stat-label">প্রধান ভূমিকা</div>
                  <div class="acc-stat-value fs-6 mt-1"><?= h(Role::label((string) $access->primaryRole())) ?></div>
                </div>
              </div>
              <div class="col-6 col-lg-3">
                <div class="acc-stat">
                  <div class="acc-stat-label">চলতি সেশন</div>
                  <div class="acc-stat-value"><?= h(bnDigits($access->sessionYear())) ?></div>
                </div>
              </div>
              <?php if ($officerCount !== null): ?>
              <div class="col-6 col-lg-3">
                <div class="acc-stat">
                  <div class="acc-stat-label">সক্রিয় অ্যাকাউন্টস অফিসার</div>
                  <div class="acc-stat-value"><?= h(bnDigits((string) $officerCount)) ?> জন</div>
                </div>
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
                <a class="acc-stat d-block text-decoration-none text-body" href="ledger.php">
                  <div class="acc-stat-label">অফিসের নগদ তহবিল</div>
                  <div class="acc-stat-value"><?= h(Money::format($cashBalance)) ?></div>
                </a>
              </div>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="bank.php">
                  <div class="acc-stat-label">ব্যাংক স্থিতি</div>
                  <div class="acc-stat-value"><?= h(Money::format($bankBalance)) ?></div>
                </a>
              </div>
              <?php endif; ?>
              <?php if ($bkashBalance !== null): ?>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="online-payments.php">
                  <div class="acc-stat-label">bKash হিসাব</div>
                  <div class="acc-stat-value"><?= h(Money::format($bkashBalance)) ?></div>
                </a>
              </div>
              <div class="col-6 col-lg-3">
                <a class="acc-stat d-block text-decoration-none text-body" href="settlements.php">
                  <div class="acc-stat-label">Nagad হিসাব</div>
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

            <div class="row g-3">
              <div class="col-lg-8">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">আপনার অনুমতি</h3></div>
                  <div class="card-body">
                    <ul class="acc-cap-list">
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
                  </div>
                </div>
              </div>
              <div class="col-lg-4">
                <?php if ($assignedGroups !== []): ?>
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title mb-0">আপনার শ্রেণী</h3></div>
                  <ul class="list-group list-group-flush">
                    <?php foreach ($assignedGroups as $group): ?>
                      <li class="list-group-item"><i class="bi bi-people me-2 text-success"></i><?= h($group) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php endif; ?>
                <?php if ($access->can(Capability::OFFICERS_MANAGE)): ?>
                <div class="card mb-3">
                  <div class="card-body">
                    <h3 class="h6">অ্যাকাউন্টস অফিসার</h3>
                    <p class="text-secondary small mb-2">হিসাবরক্ষক নিয়োগ বা প্রত্যাহার করুন। প্রতিটি পরিবর্তন অডিট লগে সংরক্ষিত হয়।</p>
                    <a href="officers.php" class="btn btn-sm btn-primary"><i class="bi bi-person-gear me-1"></i>নিয়োগ ব্যবস্থাপনা</a>
                  </div>
                </div>
                <?php endif; ?>
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0">বাস্তবায়নের অগ্রগতি</h3></div>
                  <ul class="list-group list-group-flush acc-phase-list">
                    <?php foreach ($phases as [$label, $done]): ?>
                      <li class="list-group-item">
                        <span><?= h($label) ?></span>
                        <span class="badge <?= $done ? 'text-bg-success' : 'border text-secondary' ?>"><?= $done ? 'সম্পন্ন' : 'পরবর্তী' ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
