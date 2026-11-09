<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\StudentDirectory;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::FEES_COLLECT);
$payments = accPayments();
$db = getDatabaseConnection();
$basePath = '../../';
$pageTitle = 'ফি আদায় | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$studentId = idFromSignedRecordRef((string) ($_POST['ref'] ?? $_GET['ref'] ?? ''), 'acc_student');
$student = null;
if ($studentId > 0) {
    try {
        $student = accStudents()->requireInScope($access, Capability::FEES_COLLECT, $studentId);
    } catch (Throwable $exception) {
        redirectWithMessage('collect.php', 'danger', userFacingError($exception));
    }
}
$self = $student ? signedRecordUrl('collect.php', $studentId, 'acc_student') : 'collect.php';
$officeCustody = $access->can(Capability::HANDOVER_RECEIVE);
$studentMobile = '';
if ($student) {
    $mobileRows = fetchRows($db, 'SELECT mobile FROM students_info WHERE id = :id', ['id' => $studentId]);
    $studentMobile = normalizeBdMobile((string) ($mobileRows[0]['mobile'] ?? ''));
}
$smsReady = $studentMobile !== '' && smsGatewayReady($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $student) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'collect') {
            $result = $payments->collect(
                $access,
                accAudit(),
                $studentId,
                (string) ($_POST['amount'] ?? ''),
                (string) ($_POST['idempotency_key'] ?? ''),
                array_map('intval', is_array($_POST['invoices'] ?? null) ? $_POST['invoices'] : []),
                (string) ($_POST['allow_advance'] ?? '') === '1',
                (string) ($_POST['payer_name'] ?? ''),
                (string) ($_POST['note'] ?? '')
            );
            $receiptUrl = signedRecordUrl('receipt.php', $result['payment_id'], 'acc_payment');
            if ($result['duplicate']) {
                redirectWithMessage($receiptUrl, 'info', 'এই আদায়টি আগেই সংরক্ষিত হয়েছে; একই টাকা দ্বিতীয়বার রেকর্ড করা হয়নি।');
            }
            $payment = $payments->payment($result['payment_id']);
            $message = 'আদায় সংরক্ষিত হয়েছে — রসিদ নং ' . $payment['receipt_no'] . '।';
            if ($smsReady && (string) ($_POST['send_sms'] ?? '') === '1') {
                $due = accInvoices()->duesByStudent([$studentId], (string) $student['session_year'], accToday())[$studentId]['due'] ?? 0;
                $settings = schoolSettings($db);
                $text = trim((string) ($settings['short_name_en'] ?? 'School')) . ': ' . StudentDirectory::displayName($student)
                    . '-এর ফি ' . Money::format(Money::fromDb($payment['amount'])) . ' গ্রহণ করা হয়েছে। রসিদ ' . $payment['receipt_no']
                    . '। বর্তমান বকেয়া ' . Money::format($due) . '।';
                $sms = sendSchoolSms($db, $studentMobile, $text, 'acc_receipt');
                $payments->setSmsStatus($result['payment_id'], $sms['ok'] ? 'sent' : 'failed');
                $message .= $sms['ok'] ? ' অভিভাবককে SMS পাঠানো হয়েছে।' : ' SMS পাঠানো যায়নি।';
            }
            redirectWithMessage($receiptUrl, 'success', $message);
        }
        if ($action === 'apply_advance') {
            $applied = $payments->applyAdvance($access, accAudit(), $studentId);
            redirectWithMessage($self, $applied > 0 ? 'success' : 'warning', $applied > 0 ? 'অগ্রিম থেকে ' . Money::format($applied) . ' সমন্বয় হয়েছে।' : 'সমন্বয়ের মতো অগ্রিম বা বকেয়া নেই।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage($self, 'danger', userFacingError($exception));
    }
}

$sessionYear = (string) ($_GET['session_year'] ?? AuditTrail::now()->format('Y'));
if (!in_array($sessionYear, accSessionYears(), true)) {
    $sessionYear = AuditTrail::now()->format('Y');
}
$selection = accClassSelection($access, (int) ($_GET['cls'] ?? ($student['cls'] ?? 0)), (int) ($_GET['sections'] ?? ($student ? StudentDirectory::sectionIdOf($student) : 0)));
$classId = $selection['class_id'];
$sectionId = $selection['section_id'];
$listReady = !$student && $classId > 0 && ($selection['sections'] === [] || $sectionId > 0) && $selection['notice'] === ''
    && $access->canForClass(Capability::FEES_COLLECT, $classId, $sectionId);

$classStudents = [];
$dues = [];
$advances = [];
if ($listReady) {
    $classStudents = accStudents()->forClass($sessionYear, $classId, $sectionId);
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $classStudents);
    $dues = accInvoices()->duesByStudent($ids, $sessionYear, accToday());
    $advances = $payments->advanceMap($ids);
}

$lines = [];
$invoiceGroups = [];
$advance = 0;
$totalDue = 0;
if ($student) {
    $lines = $payments->outstandingLines($studentId);
    foreach ($lines as $line) {
        $outstanding = Money::fromDb($line['net']) - Money::fromDb($line['paid']);
        $invoiceId = (int) $line['invoice_id'];
        $invoiceGroups[$invoiceId] ??= ['invoice_no' => $line['invoice_no'], 'label' => $line['billing_label'], 'due_date' => $line['invoice_due'], 'lines' => [], 'total' => 0];
        $invoiceGroups[$invoiceId]['lines'][] = $line + ['outstanding' => $outstanding];
        $invoiceGroups[$invoiceId]['total'] += $outstanding;
        $totalDue += $outstanding;
    }
    $advance = $payments->advanceBalance($studentId);
}
$today = accToday()->format('Y-m-d');

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">ফি আদায়</h3>
            <p class="text-secondary mb-0">নগদ টাকা গ্রহণ করে রসিদ দিন। টাকা প্রথমে সবচেয়ে পুরনো বকেয়ায় সমন্বয় হয়; আংশিক পরিশোধ করা যায়, অতিরিক্ত টাকা অগ্রিম হিসেবে থাকে।<?= $officeCustody ? '' : ' আদায়কৃত টাকা পরে "টাকা হস্তান্তর" পাতা থেকে অফিসে জমা দিন।' ?></p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <?php if (!$student): ?>
              <div class="card mb-3">
                <form method="get" class="card-body" data-student-filter>
                  <div class="row g-2 align-items-end">
                    <?= accClassFilterFields($selection, $sessionYear, 'col-sm-6 col-lg-3') ?>
                    <div class="col-sm-6 col-lg-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button></div>
                  </div>
                  <?php if ($selection['notice'] !== ''): ?><div class="alert alert-info py-2 mt-2 mb-0 small"><?= h($selection['notice']) ?></div><?php endif; ?>
                </form>
              </div>
              <?php if ($listReady): ?>
                <div class="card">
                  <div class="card-body p-0">
                    <?php if ($classStudents === []): ?>
                      <p class="text-secondary p-3 mb-0">কোনো ভর্তি শিক্ষার্থী নেই।</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 acc-table-stack" data-sf-table>
                          <thead><tr><th>রোল</th><th>নাম</th><th class="text-end">বকেয়া</th><th class="text-end">অগ্রিম</th><th class="text-end">আদায়</th></tr></thead>
                          <tbody>
                            <?php foreach ($classStudents as $row): ?>
                              <?php $rowId = (int) $row['id']; $rowDue = $dues[$rowId]['due'] ?? 0; ?>
                              <tr>
                                <td data-label="রোল"><?= h(bnDigits((string) $row['cls_roll'])) ?></td>
                                <td data-label="নাম"><?= h(StudentDirectory::displayName($row)) ?></td>
                                <td class="text-end" data-label="বকেয়া"><strong class="<?= $rowDue > 0 ? 'text-danger' : 'text-secondary' ?>"><?= h(Money::format($rowDue)) ?></strong></td>
                                <td class="text-end" data-label="অগ্রিম"><?= ($advances[$rowId] ?? 0) > 0 ? h(Money::format($advances[$rowId])) : '—' ?></td>
                                <td class="text-end" data-label="আদায়"><a class="btn btn-sm btn-primary" href="<?= h(signedRecordUrl('collect.php', $rowId, 'acc_student')) ?>"><i class="bi bi-cash-stack me-1"></i>আদায়</a></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="card mb-3">
                <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                  <div class="me-auto">
                    <h4 class="mb-0"><?= h(StudentDirectory::displayName($student)) ?></h4>
                    <div class="text-secondary small"><?= h((string) $student['class_name_ban']) ?><?= trim((string) $student['section_name_ban']) !== '' ? ' — ' . h((string) $student['section_name_ban']) : '' ?> · রোল <?= h(bnDigits((string) $student['cls_roll'])) ?> · সেশন <?= h(bnDigits((string) $student['session_year'])) ?></div>
                  </div>
                  <div class="text-end"><div class="small text-secondary">মোট বকেয়া</div><div class="fs-4 fw-semibold text-danger"><?= h(Money::format($totalDue)) ?></div></div>
                  <?php if ($advance > 0): ?><div class="text-end"><div class="small text-secondary">অগ্রিম জমা</div><div class="fs-4 fw-semibold text-success"><?= h(Money::format($advance)) ?></div></div><?php endif; ?>
                  <div class="d-flex gap-1">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h(signedRecordUrl('student-fees.php', $studentId, 'acc_student')) ?>">ফি বিবরণী</a>
                    <a class="btn btn-sm btn-outline-secondary" href="collect.php?<?= h(http_build_query(['session_year' => (string) $student['session_year'], 'cls' => (int) $student['cls'], 'sections' => StudentDirectory::sectionIdOf($student)])) ?>">তালিকায় ফিরুন</a>
                  </div>
                </div>
                <?php if ($advance > 0 && $totalDue > 0): ?>
                  <form method="post" class="card-footer d-flex flex-wrap gap-2 align-items-center" data-acc-confirm="অগ্রিম জমা থেকে পুরনো বকেয়া পরিশোধ করা হবে। নিশ্চিত?">
                    <?= csrfField() ?><?= signedRecordField($studentId, 'acc_student') ?><input type="hidden" name="action" value="apply_advance">
                    <span class="small">অগ্রিম জমা আছে, বকেয়াও আছে।</span>
                    <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-arrow-repeat me-1"></i>অগ্রিম থেকে সমন্বয় করুন</button>
                  </form>
                <?php endif; ?>
              </div>

              <form method="post" class="row g-3" data-acc-confirm="টাকা গুনে নিয়েছেন? আদায় সংরক্ষণ করে রসিদ তৈরি হবে।" data-acc-confirm-ok="হ্যাঁ, সংরক্ষণ করুন" data-acc-confirm-variant="success">
                <?= csrfField() ?>
                <?= signedRecordField($studentId, 'acc_student') ?>
                <input type="hidden" name="action" value="collect">
                <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                <div class="col-lg-7">
                  <div class="card h-100">
                    <div class="card-header"><h3 class="card-title mb-0">বকেয়া <span class="small text-secondary fw-normal">(যে ইনভয়েস পরিশোধ হবে তা টিক দিন)</span></h3></div>
                    <div class="card-body p-0">
                      <?php if ($invoiceGroups === []): ?>
                        <p class="text-secondary p-3 mb-0">কোনো বকেয়া নেই। টাকা নিলে তা অগ্রিম হিসেবে জমা থাকবে।</p>
                      <?php else: ?>
                        <div class="table-responsive">
                          <table class="table table-sm align-middle mb-0 acc-table-stack">
                            <thead><tr><th style="width:2.5rem"><input class="form-check-input" type="checkbox" checked data-acc-check-all="invoices[]" aria-label="সব ইনভয়েস"></th><th>ইনভয়েস</th><th>খাত</th><th>শেষ তারিখ</th><th class="text-end">বকেয়া</th></tr></thead>
                            <tbody>
                              <?php foreach ($invoiceGroups as $invoiceId => $group): ?>
                                <tr class="<?= (string) $group['due_date'] < $today ? 'table-warning' : '' ?>">
                                  <td data-label="নির্বাচন"><input class="form-check-input" type="checkbox" name="invoices[]" value="<?= (int) $invoiceId ?>" checked data-acc-due="<?= (int) $group['total'] ?>" aria-label="<?= h((string) $group['invoice_no']) ?>"></td>
                                  <td data-label="ইনভয়েস"><code><?= h((string) $group['invoice_no']) ?></code><div class="small"><?= h((string) $group['label']) ?></div></td>
                                  <td data-label="খাত">
                                    <?php foreach ($group['lines'] as $line): ?>
                                      <div class="small"><?= h((string) $line['description']) ?>: <?= h(Money::format($line['outstanding'])) ?></div>
                                    <?php endforeach; ?>
                                  </td>
                                  <td data-label="শেষ তারিখ"><?= h(bnDigits((string) $group['due_date'])) ?></td>
                                  <td class="text-end text-nowrap" data-label="বকেয়া"><strong><?= h(Money::format($group['total'])) ?></strong></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-lg-5">
                  <div class="card h-100">
                    <div class="card-header"><h3 class="card-title mb-0">টাকা গ্রহণ (নগদ)</h3></div>
                    <div class="card-body">
                      <label class="form-label" for="pay-amount">গৃহীত টাকা (৳)</label>
                      <input class="form-control form-control-lg text-end" id="pay-amount" name="amount" inputmode="decimal" required maxlength="16" value="<?= $totalDue > 0 ? h(Money::toInput($totalDue)) : '' ?>" data-acc-amount autocomplete="off">
                      <div class="form-text">নির্বাচিত বকেয়া: <strong data-acc-selected-due><?= h(Money::format($totalDue)) ?></strong>। কম দিলে আংশিক পরিশোধ হবে।</div>
                      <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="allow_advance" value="1" id="pay-advance"<?= $invoiceGroups === [] ? ' checked' : '' ?>>
                        <label class="form-check-label" for="pay-advance">বকেয়ার অতিরিক্ত টাকা অগ্রিম হিসেবে জমা রাখুন</label>
                      </div>
                      <div class="row g-2 mt-1">
                        <div class="col-sm-6">
                          <label class="form-label small mb-1" for="pay-payer">প্রদানকারী <span class="text-secondary">(ঐচ্ছিক)</span></label>
                          <input class="form-control form-control-sm" id="pay-payer" name="payer_name" maxlength="120" placeholder="যেমন: পিতা">
                        </div>
                        <div class="col-sm-6">
                          <label class="form-label small mb-1" for="pay-note">মন্তব্য <span class="text-secondary">(ঐচ্ছিক)</span></label>
                          <input class="form-control form-control-sm" id="pay-note" name="note" maxlength="255">
                        </div>
                      </div>
                      <?php if ($smsReady): ?>
                        <div class="form-check mt-2">
                          <input class="form-check-input" type="checkbox" name="send_sms" value="1" id="pay-sms" checked>
                          <label class="form-check-label" for="pay-sms">অভিভাবকের মোবাইলে রসিদের SMS পাঠান</label>
                        </div>
                      <?php endif; ?>
                      <div class="small text-secondary mt-2">টাকা থাকবে: <strong><?= $officeCustody ? 'অফিসের ক্যাশে' : 'আপনার কাছে (হস্তান্তর না করা পর্যন্ত)' ?></strong></div>
                    </div>
                    <div class="card-footer"><button class="btn btn-success w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>আদায় সংরক্ষণ ও রসিদ</button></div>
                  </div>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
