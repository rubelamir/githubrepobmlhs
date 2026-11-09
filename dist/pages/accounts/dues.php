<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\StudentDirectory;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::FEES_VIEW_CLASS);
$basePath = '../../';
$pageTitle = 'বকেয়া তালিকা | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$sessionYear = (string) ($_GET['session_year'] ?? AuditTrail::now()->format('Y'));
if (!in_array($sessionYear, accSessionYears(), true)) {
    $sessionYear = AuditTrail::now()->format('Y');
}
$selection = accClassSelection($access, (int) ($_GET['cls'] ?? 0), (int) ($_GET['sections'] ?? 0));
$classId = $selection['class_id'];
$sectionId = $selection['section_id'];
$ready = $classId > 0
    && ($selection['sections'] === [] || $sectionId > 0)
    && $selection['notice'] === ''
    && $access->canForClass(Capability::FEES_VIEW_CLASS, $classId, $sectionId);
$onlyDue = (string) ($_GET['only_due'] ?? '1') === '1';

$students = [];
$dues = [];
$categories = [];
$totals = ['net' => 0, 'paid' => 0, 'due' => 0, 'overdue' => 0, 'students_due' => 0];
if ($ready) {
    $today = accToday();
    $students = accStudents()->forClass($sessionYear, $classId, $sectionId);
    $ids = array_map(static fn (array $student): int => (int) $student['id'], $students);
    $dues = accInvoices()->duesByStudent($ids, $sessionYear, $today);
    $categories = accStudentCategories()->currentMap($ids, $sessionYear, $today->format('Y-m-d'));
    foreach ($dues as $due) {
        $totals['net'] += $due['net'];
        $totals['paid'] += $due['paid'];
        $totals['due'] += $due['due'];
        $totals['overdue'] += $due['overdue'];
        $totals['students_due'] += $due['due'] > 0 ? 1 : 0;
    }
    if ($onlyDue) {
        $students = array_values(array_filter($students, static fn (array $student): bool => ($dues[(int) $student['id']]['due'] ?? 0) > 0));
    }
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">বকেয়া তালিকা</h3>
            <p class="text-secondary mb-0">নির্বাচিত সেশনের সব ইনভয়েস (বাতিল ছাড়া) ধরে প্রত্যেক শিক্ষার্থীর প্রাপ্য, আদায় ও বকেয়া।<?= $access->hasSchoolWideScope() ? '' : ' আপনি শুধু নিজের শ্রেণী/শাখা দেখতে পাবেন।' ?></p>
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
                    <label class="form-label" for="only-due">দেখাবে</label>
                    <select class="form-select" name="only_due" id="only-due">
                      <option value="1"<?= $onlyDue ? ' selected' : '' ?>>শুধু বকেয়াদার</option>
                      <option value="0"<?= $onlyDue ? '' : ' selected' ?>>সব শিক্ষার্থী</option>
                    </select>
                  </div>
                </div>
                <div class="mt-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button></div>
                <?php if ($selection['notice'] !== ''): ?><div class="alert alert-info py-2 mt-2 mb-0 small"><?= h($selection['notice']) ?></div><?php endif; ?>
              </form>
            </div>

            <?php if ($ready): ?>
              <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">মোট প্রাপ্য</div><div class="acc-stat-value"><?= h(Money::format($totals['net'])) ?></div></div></div>
                <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">আদায়</div><div class="acc-stat-value text-success"><?= h(Money::format($totals['paid'])) ?></div></div></div>
                <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">বকেয়া (<?= h(bnDigits((string) $totals['students_due'])) ?> জন)</div><div class="acc-stat-value text-danger"><?= h(Money::format($totals['due'])) ?></div></div></div>
                <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">মেয়াদোত্তীর্ণ</div><div class="acc-stat-value text-warning-emphasis"><?= h(Money::format($totals['overdue'])) ?></div></div></div>
              </div>
              <div class="card">
                <div class="card-body p-0">
                  <?php if ($students === []): ?>
                    <p class="text-secondary p-3 mb-0"><?= $onlyDue ? 'কোনো বকেয়া নেই।' : 'কোনো ভর্তি শিক্ষার্থী নেই।' ?></p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0 acc-table-stack">
                        <thead><tr><th>রোল</th><th>নাম</th><th>ফি-শ্রেণী</th><th class="text-end">ইনভয়েস</th><th class="text-end">প্রাপ্য</th><th class="text-end">আদায়</th><th class="text-end">বকেয়া</th><th class="text-end">মেয়াদোত্তীর্ণ</th><th class="text-end">বিস্তারিত</th></tr></thead>
                        <tbody>
                          <?php foreach ($students as $student): ?>
                            <?php $studentId = (int) $student['id']; $due = $dues[$studentId] ?? ['invoices' => 0, 'net' => 0, 'paid' => 0, 'due' => 0, 'overdue' => 0]; ?>
                            <tr>
                              <td data-label="রোল"><?= h(bnDigits((string) $student['cls_roll'])) ?></td>
                              <td data-label="নাম"><?= h(StudentDirectory::displayName($student)) ?></td>
                              <td data-label="ফি-শ্রেণী"><?= h((string) ($categories[$studentId]['category_name'] ?? 'সাধারণ')) ?></td>
                              <td class="text-end" data-label="ইনভয়েস"><?= h(bnDigits((string) $due['invoices'])) ?></td>
                              <td class="text-end" data-label="প্রাপ্য"><?= h(Money::format($due['net'])) ?></td>
                              <td class="text-end" data-label="আদায়"><?= h(Money::format($due['paid'])) ?></td>
                              <td class="text-end" data-label="বকেয়া"><strong class="<?= $due['due'] > 0 ? 'text-danger' : '' ?>"><?= h(Money::format($due['due'])) ?></strong></td>
                              <td class="text-end" data-label="মেয়াদোত্তীর্ণ"><?= $due['overdue'] > 0 ? '<span class="badge text-bg-warning">' . h(Money::format($due['overdue'])) . '</span>' : '—' ?></td>
                              <td class="text-end" data-label="বিস্তারিত"><a class="btn btn-sm btn-outline-secondary" href="<?= h(signedRecordUrl('student-fees.php', $studentId, 'acc_student')) ?>">ফি বিবরণী</a></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
