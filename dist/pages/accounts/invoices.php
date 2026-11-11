<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\BillingPeriod;
use School\Accounting\Billing\FeeStructureRepository;
use School\Accounting\Billing\InvoiceService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::INVOICE_MANAGE, Capability::FEES_VIEW_ALL]);
$canGenerate = $access->can(Capability::INVOICE_MANAGE);
$canCancel = $access->can(Capability::INVOICE_CANCEL);
$invoices = accInvoices();
$db = getDatabaseConnection();
$basePath = '../../';
$pageTitle = 'মাসের বিল | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$sessionYear = (string) ($input['session_year'] ?? AuditTrail::now()->format('Y'));
if (!in_array($sessionYear, accSessionYears(), true)) {
    $sessionYear = AuditTrail::now()->format('Y');
}
$selection = accClassSelection($access, (int) ($input['cls'] ?? 0), (int) ($input['sections'] ?? 0));
$classId = $selection['class_id'];
$sectionId = $selection['section_id'];
$ready = $classId > 0 && ($selection['sections'] === [] || $sectionId > 0);
$query = ['session_year' => $sessionYear, 'cls' => $classId, 'sections' => $sectionId];
$exams = fetchRows($db, 'SELECT id, exam_name_bangla FROM exam_details WHERE is_active = 1 ORDER BY sort_order, id');
$today = accToday();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'invoices.php?' . http_build_query($query);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'generate') {
            if (!$ready) {
                throw new RuntimeException('শ্রেণী ও শাখা নির্বাচন করুন।');
            }
            $issueDate = FeeStructureRepository::parseDate((string) ($_POST['issue_date'] ?? ''), 'ইনভয়েসের তারিখ');
            $kind = (string) ($_POST['billing_kind'] ?? '');
            $period = match ($kind) {
                BillingPeriod::MONTHLY => BillingPeriod::monthly((string) ($_POST['month'] ?? '')),
                BillingPeriod::OTHER => BillingPeriod::other((string) ($_POST['month'] ?? '')),
                BillingPeriod::SESSION => BillingPeriod::session($sessionYear),
                BillingPeriod::EXAM => (static function () use ($exams, $sessionYear, $issueDate): BillingPeriod {
                    $examId = (int) ($_POST['exam_id'] ?? 0);
                    foreach ($exams as $exam) {
                        if ((int) $exam['id'] === $examId) {
                            return BillingPeriod::exam($examId, (string) $exam['exam_name_bangla'], $sessionYear, $issueDate);
                        }
                    }
                    throw new RuntimeException('পরীক্ষা নির্বাচন করুন।');
                })(),
                default => throw new RuntimeException('বিলের ধরন নির্বাচন করুন।'),
            };
            $headIds = array_map('intval', is_array($_POST['heads'] ?? null) ? $_POST['heads'] : []);
            $result = $invoices->generate($access, accAudit(), $sessionYear, $classId, $sectionId, $period, $headIds, $issueDate);
            $message = $period->label() . ': ' . bnDigits((string) $result['students']) . ' জন শিক্ষার্থী — নতুন ইনভয়েস '
                . bnDigits((string) $result['created']) . 'টি';
            if ($result['already'] > 0) {
                $message .= ', আগেই তৈরি ' . bnDigits((string) $result['already']) . 'টি';
            }
            if ($result['no_rate'] > 0) {
                $message .= ', হার না থাকায় বাদ ' . bnDigits((string) $result['no_rate']) . ' জন';
            }
            if ($result['zero'] > 0) {
                $message .= ', সম্পূর্ণ ছাড়প্রাপ্ত (৳০) ' . bnDigits((string) $result['zero']) . 'টি';
            }
            if ($result['created'] > 0 && $access->can(Capability::FEES_COLLECT)) {
                $studentIds = array_map(static fn (array $row): int => (int) $row['id'], accStudents()->forClass($sessionYear, $classId, $sectionId));
                $adjusted = 0;
                foreach (array_keys(array_filter(accPayments()->advanceMap($studentIds), static fn (int $balance): bool => $balance > 0)) as $studentId) {
                    $adjusted += accPayments()->applyAdvance($access, accAudit(), $studentId);
                }
                if ($adjusted > 0) {
                    $message .= '; অগ্রিম জমা থেকে ' . Money::format($adjusted) . ' সমন্বয় হয়েছে';
                }
            }
            redirectWithMessage($back, $result['created'] > 0 ? 'success' : 'warning', $message . '।');
        }
        if ($action === 'cancel') {
            $invoices->cancel($access, accAudit(), postedRecordId('acc_invoice'), (string) ($_POST['reason'] ?? ''));
            redirectWithMessage($back, 'success', 'ইনভয়েস বাতিল হয়েছে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$heads = accCatalog()->heads(true);
$rows = [];
$billingKeys = [];
$billingKey = (string) ($_GET['billing_key'] ?? '');
$status = (string) ($_GET['status'] ?? '');
if (!isset(InvoiceService::STATUS_LABELS[$status])) {
    $status = '';
}
$totals = ['net' => 0, 'paid' => 0, 'due' => 0];
if ($ready) {
    $billingKeys = $invoices->billingKeys($sessionYear, $classId, $sectionId);
    if (!isset($billingKeys[$billingKey])) {
        $billingKey = $billingKeys !== [] ? (string) array_key_last($billingKeys) : '';
    }
    if ($billingKey !== '') {
        $rows = $invoices->listForClass(['session_year' => $sessionYear, 'class_id' => $classId, 'section_id' => $sectionId, 'billing_key' => $billingKey, 'status' => $status]);
    }
    foreach ($rows as $row) {
        if ($row['status'] === InvoiceService::CANCELLED) {
            continue;
        }
        $totals['net'] += Money::fromDb($row['net']);
        $totals['paid'] += Money::fromDb($row['paid']);
    }
    $totals['due'] = max(0, $totals['net'] - $totals['paid']);
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">মাসের বিল</h3>
            <p class="text-secondary mb-0">শ্রেণী বেছে এই মাসের বেতন বা অন্য ফি বিল করুন। বিল তৈরি না হলে বকেয়া তালিকায় কিছু দেখায় না। একই ফি দুবার বিল হয় না।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="card mb-3">
              <form method="get" class="card-body" data-student-filter>
                <div class="row g-2 align-items-end">
                  <?= accClassFilterFields($selection, $sessionYear, 'col-sm-6 col-lg-3') ?>
                  <div class="col-sm-6 col-lg-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button></div>
                </div>
                <?php if ($selection['notice'] !== ''): ?><div class="alert alert-info py-2 mt-2 mb-0 small"><?= h($selection['notice']) ?></div><?php endif; ?>
              </form>
            </div>

            <?php if ($ready && $canGenerate): ?>
              <div class="row g-3 mb-3">
                <div class="col-lg-12">
                  <form method="post" class="card h-100" data-acc-confirm="নির্বাচিত শ্রেণী/শাখার সব ভর্তি শিক্ষার্থীর ইনভয়েস তৈরি হবে। চালিয়ে যাবেন?">
                    <?= csrfField() ?>
                    <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                    <input type="hidden" name="action" value="generate">
                    <div class="card-header"><h3 class="card-title mb-0">এই মাসের বিল তৈরি</h3></div>
                    <div class="card-body">
                      <div class="row g-2">
                        <div class="col-sm-4">
                          <label class="form-label" for="inv-kind">বিলের ধরন</label>
                          <select class="form-select" name="billing_kind" id="inv-kind" required data-acc-kind-select>
                            <?php foreach (BillingPeriod::KIND_LABELS as $kind => $label): ?>
                              <option value="<?= h($kind) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-sm-4" data-acc-kind="monthly other">
                          <label class="form-label" for="inv-month">মাস</label>
                          <input class="form-control" type="month" name="month" id="inv-month" required value="<?= h($today->format('Y') === $sessionYear ? $today->format('Y-m') : $sessionYear . '-01') ?>" min="<?= h($sessionYear) ?>-01" max="<?= h($sessionYear) ?>-12">
                        </div>
                        <div class="col-sm-4" data-acc-kind="exam" hidden>
                          <label class="form-label" for="inv-exam">পরীক্ষা</label>
                          <select class="form-select" name="exam_id" id="inv-exam" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach ($exams as $exam): ?><option value="<?= (int) $exam['id'] ?>"><?= h((string) $exam['exam_name_bangla']) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-sm-4">
                          <label class="form-label" for="inv-issue">ইনভয়েসের তারিখ</label>
                          <input class="form-control" type="date" name="issue_date" id="inv-issue" required value="<?= h($today->format('Y-m-d')) ?>">
                        </div>
                      </div>
                      <div class="mt-3">
                        <span class="form-label d-block">কোন ফি <span class="text-secondary small">(কিছু না বাছলে এই ধরনের সব ফি)</span></span>
                        <?php foreach (BillingPeriod::KIND_LABELS as $kind => $label): ?>
                          <div data-acc-kind="<?= h($kind) ?>"<?= $kind === BillingPeriod::MONTHLY ? '' : ' hidden' ?>>
                            <?php $kindHeads = array_filter($heads, static fn (array $head): bool => $head['billing_kind'] === $kind); ?>
                            <?php if ($kindHeads === []): ?><span class="small text-secondary">এই ধরনের কোনো সক্রিয় ফি হেড নেই।</span><?php endif; ?>
                            <?php foreach ($kindHeads as $head): ?>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="heads[]" value="<?= (int) $head['id'] ?>" id="inv-head-<?= (int) $head['id'] ?>">
                                <label class="form-check-label" for="inv-head-<?= (int) $head['id'] ?>"><?= h((string) $head['name_bn']) ?></label>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-receipt me-1"></i>বিল তৈরি করুন</button></div>
                  </form>
                </div>
              </div>
              <?php endif; ?>

              <?php if ($ready): ?>
              <div class="card">
                <div class="card-header">
                  <form method="get" class="row g-2 align-items-end">
                    <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                    <div class="col-sm-5">
                      <label class="form-label small mb-1" for="list-key">বিল</label>
                      <select class="form-select form-select-sm" name="billing_key" id="list-key"<?= $billingKeys === [] ? ' disabled' : '' ?>>
                        <?php if ($billingKeys === []): ?><option>এখনো কোনো বিল নেই</option><?php endif; ?>
                        <?php foreach (array_reverse($billingKeys, true) as $key => $label): ?>
                          <option value="<?= h($key) ?>"<?= $key === $billingKey ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-sm-4">
                      <label class="form-label small mb-1" for="list-status">অবস্থা</label>
                      <select class="form-select form-select-sm" name="status" id="list-status">
                        <option value="">সব</option>
                        <?php foreach (InvoiceService::STATUS_LABELS as $key => $label): ?>
                          <option value="<?= h($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-sm-3"><button class="btn btn-sm btn-outline-primary w-100" type="submit">দেখুন</button></div>
                  </form>
                </div>
                <?php if ($rows !== []): ?>
                  <div class="card-body border-bottom py-2 d-flex flex-wrap gap-3 small">
                    <span>মোট প্রাপ্য: <strong><?= h(Money::format($totals['net'])) ?></strong></span>
                    <span>আদায়: <strong class="text-success"><?= h(Money::format($totals['paid'])) ?></strong></span>
                    <span>বকেয়া: <strong class="text-danger"><?= h(Money::format($totals['due'])) ?></strong></span>
                  </div>
                <?php endif; ?>
                <div class="card-body p-0">
                  <?php if ($rows === []): ?>
                    <p class="text-secondary p-3 mb-0">কোনো ইনভয়েস পাওয়া যায়নি।</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0 acc-table-stack">
                        <thead><tr><th>ইনভয়েস নং</th><th>রোল</th><th>নাম</th><th class="text-end">মোট</th><th class="text-end">ছাড়/মওকুফ</th><th class="text-end">প্রাপ্য</th><th class="text-end">আদায়</th><th>শেষ তারিখ</th><th>অবস্থা</th><th class="text-end">কার্যক্রম</th></tr></thead>
                        <tbody>
                          <?php foreach ($rows as $row): ?>
                            <?php
                            $invoiceId = (int) $row['id'];
                            $relief = Money::fromDb($row['category_concession']) + Money::fromDb($row['discount']) + Money::fromDb($row['waived']);
                            $cancelled = $row['status'] === InvoiceService::CANCELLED;
                            ?>
                            <tr class="<?= $cancelled ? 'text-secondary text-decoration-line-through' : '' ?>">
                              <td data-label="ইনভয়েস নং"><code><?= h((string) $row['invoice_no']) ?></code></td>
                              <td data-label="রোল"><?= h(bnDigits((string) $row['roll_snapshot'])) ?></td>
                              <td data-label="নাম"><?= h((string) $row['name_snapshot']) ?></td>
                              <td class="text-end" data-label="মোট"><?= h(Money::format(Money::fromDb($row['standard_total']) + Money::fromDb($row['fine']))) ?></td>
                              <td class="text-end" data-label="ছাড়/মওকুফ"><?= $relief !== 0 ? h(Money::format($relief)) : '—' ?></td>
                              <td class="text-end" data-label="প্রাপ্য"><strong><?= h(Money::format(Money::fromDb($row['net']))) ?></strong></td>
                              <td class="text-end" data-label="আদায়"><?= h(Money::format(Money::fromDb($row['paid']))) ?></td>
                              <td data-label="শেষ তারিখ"><?= h(bnDigits((string) $row['due_date'])) ?></td>
                              <td data-label="অবস্থা"><span class="badge <?= h(InvoiceService::STATUS_BADGES[$row['status']] ?? 'text-bg-light') ?>"><?= h(InvoiceService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span></td>
                              <td class="text-end text-nowrap" data-label="কার্যক্রম">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= h(signedRecordUrl('student-fees.php#inv-' . $invoiceId, (int) $row['student_id'], 'acc_student')) ?>">বিস্তারিত</a>
                                <?php if ($canCancel && !$cancelled && Money::fromDb($row['paid']) === 0): ?>
                                  <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#cancel-<?= $invoiceId ?>">বাতিল</button>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php if ($canCancel && !$cancelled && Money::fromDb($row['paid']) === 0): ?>
                              <tr class="collapse" id="cancel-<?= $invoiceId ?>">
                                <td colspan="10">
                                  <form method="post" class="d-flex flex-wrap gap-2" data-acc-confirm="<?= h((string) $row['invoice_no']) ?> বাতিল করবেন? বাতিল ইনভয়েস মুছে যায় না, তবে প্রয়োজনে নতুন করে বিল করা যাবে।" data-acc-confirm-variant="danger">
                                    <?= csrfField() ?>
                                    <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <?= signedRecordField($invoiceId, 'acc_invoice') ?>
                                    <input class="form-control form-control-sm flex-grow-1" style="max-width:28rem" name="reason" minlength="3" maxlength="255" required placeholder="বাতিলের কারণ" aria-label="বাতিলের কারণ">
                                    <button class="btn btn-sm btn-danger" type="submit">বাতিল নিশ্চিত</button>
                                  </form>
                                </td>
                              </tr>
                            <?php endif; ?>
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
