<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Access\Role;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$db = getDatabaseConnection();
$basePath = '../../';
$pageTitle = 'হিসাব ড্যাশবোর্ড | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$officerCount = $access->hasSchoolWideScope() ? count(accOfficers()->activeOfficers()) : null;
$pendingApprovals = $access->can(Capability::CONCESSION_APPROVE) ? accPendingApprovalCount() : null;

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
    ['ধাপ ৩ — নগদ আদায়, রসিদ ও হস্তান্তর', false],
    ['ধাপ ৪ — চার্ট অব অ্যাকাউন্টস ও ভাউচার', false],
    ['ধাপ ৫ — bKash / Nagad (sandbox)', false],
    ['ধাপ ৬ — রিপোর্ট ও ড্যাশবোর্ড চার্ট', false],
    ['ধাপ ৭ — নিরাপত্তা যাচাই ও টেস্ট', false],
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
