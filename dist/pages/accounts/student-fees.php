<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Billing\ApprovalStatus;
use School\Accounting\Billing\ConcessionService;
use School\Accounting\Billing\InvoiceService;
use School\Accounting\Billing\StudentDirectory;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::FEES_VIEW_CLASS);
$basePath = '../../';
$pageTitle = 'শিক্ষার্থীর ফি বিবরণী | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$studentId = idFromSignedRecordRef((string) ($_POST['ref'] ?? $_GET['ref'] ?? ''), 'acc_student');
try {
    $student = accStudents()->requireInScope($access, Capability::FEES_VIEW_CLASS, $studentId);
} catch (Throwable $exception) {
    redirectWithMessage('dues.php', 'danger', userFacingError($exception));
}
$self = signedRecordUrl('student-fees.php', $studentId, 'acc_student');
$sessionYear = (string) $student['session_year'];
$canRequestConcession = $access->canForClass(Capability::CONCESSION_REQUEST, (int) $student['cls'], StudentDirectory::sectionIdOf($student));
$canRequestWaiver = $access->canForClass(Capability::WAIVER_REQUEST, (int) $student['cls'], StudentDirectory::sectionIdOf($student));
$canApprove = $access->can(Capability::CONCESSION_APPROVE);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'concession_request') {
            accConcessions()->request(
                $access,
                accAudit(),
                $studentId,
                (int) ($_POST['fee_head_id'] ?? 0),
                (string) ($_POST['kind'] ?? ''),
                (string) ($_POST['value'] ?? ''),
                (string) ($_POST['reason'] ?? ''),
                (string) ($_POST['effective_from'] ?? '')
            );
            redirectWithMessage($self . '#concessions', 'success', $canApprove ? 'ব্যক্তিগত ছাড় নির্ধারিত হয়েছে।' : 'ছাড়ের আবেদন অনুমোদনের জন্য পাঠানো হয়েছে।');
        }
        if ($action === 'concession_end') {
            accConcessions()->end($access, accAudit(), postedRecordId('acc_concession', 'concession_ref'), (string) ($_POST['last_day'] ?? ''), (string) ($_POST['note'] ?? ''));
            redirectWithMessage($self . '#concessions', 'success', 'ছাড়ের মেয়াদ শেষ করা হয়েছে।');
        }
        if ($action === 'waiver_request') {
            accWaivers()->request($access, accAudit(), postedRecordId('acc_invoice_item', 'item_ref'), (string) ($_POST['amount'] ?? ''), (string) ($_POST['reason'] ?? ''));
            redirectWithMessage($self . '#invoices', 'success', $access->can(Capability::WAIVER_APPROVE) ? 'মওকুফ প্রয়োগ হয়েছে।' : 'মওকুফের আবেদন অনুমোদনের জন্য পাঠানো হয়েছে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage($self, 'danger', userFacingError($exception));
    }
}

$today = accToday();
$currentCategory = accStudentCategories()->currentMap([$studentId], $sessionYear, $today->format('Y-m-d'))[$studentId] ?? null;
$categoryHistory = accStudentCategories()->history($studentId);
$concessions = accConcessions()->forStudent($studentId);
$invoiceRows = accInvoices()->forStudent($studentId);
$waivers = accWaivers()->forStudent($studentId);
$receipts = accPayments()->forStudent($studentId);
$advance = accPayments()->advanceBalance($studentId);
$canCollect = $access->canForClass(Capability::FEES_COLLECT, (int) $student['cls'], StudentDirectory::sectionIdOf($student));
$dues = accInvoices()->duesByStudent([$studentId], $sessionYear)[$studentId] ?? ['invoices' => 0, 'net' => 0, 'paid' => 0, 'due' => 0];
$heads = accCatalog()->heads(true);
$pendingWaiverItems = [];
foreach ($waivers as $waiver) {
    if ($waiver['status'] === ApprovalStatus::PENDING) {
        $pendingWaiverItems[(int) $waiver['invoice_item_id']] = true;
    }
}
$defaultEffective = $today->format('Y') === $sessionYear ? $today->format('Y-m-d') : $sessionYear . '-01-01';

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid d-flex flex-wrap justify-content-between gap-2 align-items-start">
            <div>
              <h3 class="mb-1"><?= h(StudentDirectory::displayName($student)) ?></h3>
              <p class="text-secondary mb-0">
                <?= h((string) $student['class_name_ban']) ?><?= trim((string) $student['section_name_ban']) !== '' ? ' — ' . h((string) $student['section_name_ban']) : '' ?>
                · রোল <?= h(bnDigits((string) $student['cls_roll'])) ?> · সেশন <?= h(bnDigits($sessionYear)) ?>
                · ফি-শ্রেণী: <span class="badge <?= $currentCategory ? 'text-bg-info' : 'text-bg-light border' ?>"><?= h((string) ($currentCategory['category_name'] ?? 'সাধারণ')) ?></span>
              </p>
            </div>
            <div class="d-flex gap-1">
            <?php if ($canCollect): ?><a class="btn btn-primary btn-sm" href="<?= h(signedRecordUrl('collect.php', $studentId, 'acc_student')) ?>"><i class="bi bi-cash-stack"></i> আদায়</a><?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="dues.php?<?= h(http_build_query(['session_year' => $sessionYear, 'cls' => (int) $student['cls'], 'sections' => StudentDirectory::sectionIdOf($student)])) ?>"><i class="bi bi-arrow-left"></i> বকেয়া তালিকা</a>
            </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">মোট প্রাপ্য</div><div class="acc-stat-value"><?= h(Money::format($dues['net'])) ?></div></div></div>
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">আদায়</div><div class="acc-stat-value text-success"><?= h(Money::format($dues['paid'])) ?></div></div></div>
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">বকেয়া</div><div class="acc-stat-value text-danger"><?= h(Money::format($dues['due'])) ?></div></div></div>
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">অগ্রিম জমা</div><div class="acc-stat-value"><?= h(Money::format($advance)) ?></div></div></div>
            </div>

            <div class="card mb-3" id="receipts">
              <div class="card-header"><h3 class="card-title mb-0">রসিদ</h3></div>
              <div class="card-body p-0">
                <?php if ($receipts === []): ?>
                  <p class="text-secondary p-3 mb-0">এখনো কোনো আদায় হয়নি।</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 acc-table-stack">
                      <thead><tr><th>রসিদ নং</th><th>সময়</th><th class="text-end">টাকা</th><th>আদায়কারী</th><th>অবস্থা</th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($receipts as $row): ?>
                          <?php $confirmed = $row['status'] === PaymentService::CONFIRMED; ?>
                          <tr class="<?= $confirmed ? '' : 'text-secondary' ?>">
                            <td data-label="রসিদ নং"><code><?= h((string) $row['receipt_no']) ?></code></td>
                            <td data-label="সময়"><?= h(accFormatDateTime((string) $row['collected_at'])) ?></td>
                            <td class="text-end" data-label="টাকা"><?= h(Money::format(Money::fromDb($row['amount']))) ?></td>
                            <td data-label="আদায়কারী"><?= h((string) $row['collected_by_name']) ?></td>
                            <td data-label="অবস্থা"><span class="badge <?= $confirmed ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h(PaymentService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span></td>
                            <td class="text-end" data-label="রসিদ"><a class="btn btn-sm btn-outline-secondary" href="<?= h(signedRecordUrl('receipt.php', (int) $row['id'], 'acc_payment')) ?>"><i class="bi bi-printer"></i></a></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card mb-3" id="invoices">
              <div class="card-header"><h3 class="card-title mb-0">ইনভয়েস</h3></div>
              <div class="card-body">
                <?php if ($invoiceRows === []): ?>
                  <p class="text-secondary mb-0">এখনো কোনো ইনভয়েস তৈরি হয়নি।</p>
                <?php endif; ?>
                <?php foreach ($invoiceRows as $invoice): ?>
                  <?php
                  $invoiceId = (int) $invoice['id'];
                  $cancelled = $invoice['status'] === InvoiceService::CANCELLED;
                  $lines = accInvoices()->items($invoiceId);
                  ?>
                  <div class="border rounded mb-3" id="inv-<?= $invoiceId ?>">
                    <div class="d-flex flex-wrap gap-2 align-items-center p-2 bg-body-tertiary border-bottom">
                      <code><?= h((string) $invoice['invoice_no']) ?></code>
                      <strong><?= h((string) $invoice['billing_label']) ?></strong>
                      <span class="badge <?= h(InvoiceService::STATUS_BADGES[$invoice['status']] ?? 'text-bg-light') ?>"><?= h(InvoiceService::STATUS_LABELS[$invoice['status']] ?? (string) $invoice['status']) ?></span>
                      <span class="small text-secondary ms-auto">তারিখ <?= h(bnDigits((string) $invoice['issue_date'])) ?> · শেষ তারিখ <?= h(bnDigits((string) $invoice['due_date'])) ?></span>
                    </div>
                    <?php if ($cancelled): ?>
                      <div class="small text-danger px-2 pt-2">বাতিল: <?= h((string) $invoice['cancel_reason']) ?> (<?= h(accFormatDateTime((string) $invoice['cancelled_at'])) ?>)</div>
                    <?php endif; ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0 acc-invoice-lines acc-table-stack">
                        <thead><tr><th>খাত</th><th>ফি-শ্রেণী</th><th class="text-end">সাধারণ হার</th><th class="text-end">শ্রেণী ছাড়</th><th class="text-end">ব্যক্তিগত ছাড়</th><th class="text-end">মওকুফ</th><th class="text-end">প্রাপ্য</th><th class="text-end">আদায়</th><th></th></tr></thead>
                        <tbody>
                          <?php foreach ($lines as $line): ?>
                            <?php
                            $itemId = (int) $line['id'];
                            $outstanding = Money::fromDb($line['net']) - Money::fromDb($line['paid']);
                            $isFine = $line['line_kind'] === InvoiceService::LINE_LATE_FINE;
                            if ($isFine && $outstanding <= 0 && Money::fromDb($line['paid']) === 0) {
                                continue;
                            }
                            ?>
                            <tr>
                              <td data-label="খাত"><?= h((string) $line['description']) ?><?= $isFine ? ' <span class="badge text-bg-warning">জরিমানা</span>' : '' ?></td>
                              <td data-label="ফি-শ্রেণী"><?= $isFine ? '—' : h((string) ($line['category_name'] ?? 'সাধারণ')) ?></td>
                              <td class="text-end" data-label="সাধারণ হার"><?= h(Money::format($isFine ? Money::fromDb($line['amount']) : Money::fromDb($line['standard_amount']))) ?></td>
                              <td class="text-end" data-label="শ্রেণী ছাড়"><?= Money::fromDb($line['category_concession']) !== 0 ? h(Money::format(Money::fromDb($line['category_concession']))) : '—' ?></td>
                              <td class="text-end" data-label="ব্যক্তিগত ছাড়"><?= Money::fromDb($line['discount']) !== 0 ? h(Money::format(Money::fromDb($line['discount']))) : '—' ?></td>
                              <td class="text-end" data-label="মওকুফ"><?= Money::fromDb($line['waived']) !== 0 ? h(Money::format(Money::fromDb($line['waived']))) : '—' ?></td>
                              <td class="text-end" data-label="প্রাপ্য"><strong><?= h(Money::format(Money::fromDb($line['net']))) ?></strong></td>
                              <td class="text-end" data-label="আদায়"><?= h(Money::format(Money::fromDb($line['paid']))) ?></td>
                              <td class="text-end" data-label="মওকুফ আবেদন">
                                <?php if ($canRequestWaiver && !$cancelled && $outstanding > 0 && !isset($pendingWaiverItems[$itemId])): ?>
                                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#waive-<?= $itemId ?>">মওকুফ</button>
                                <?php elseif (isset($pendingWaiverItems[$itemId])): ?>
                                  <span class="badge text-bg-warning">মওকুফ অপেক্ষমাণ</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php if ($canRequestWaiver && !$cancelled && $outstanding > 0 && !isset($pendingWaiverItems[$itemId])): ?>
                              <tr class="collapse" id="waive-<?= $itemId ?>">
                                <td colspan="9">
                                  <form method="post" class="d-flex flex-wrap gap-2" data-acc-confirm="<?= h((string) $line['description']) ?>-এ মওকুফ <?= $access->can(Capability::WAIVER_APPROVE) ? 'দেবেন' : 'আবেদন পাঠাবেন' ?>?">
                                    <?= csrfField() ?>
                                    <?= signedRecordField($studentId, 'acc_student') ?>
                                    <?= signedRecordField($itemId, 'acc_invoice_item', 'item_ref') ?>
                                    <input type="hidden" name="action" value="waiver_request">
                                    <input class="form-control form-control-sm" style="max-width:9rem" name="amount" inputmode="decimal" required placeholder="সর্বোচ্চ <?= h(Money::toInput($outstanding)) ?>" aria-label="মওকুফের পরিমাণ">
                                    <input class="form-control form-control-sm flex-grow-1" style="max-width:24rem" name="reason" minlength="3" maxlength="255" required placeholder="কারণ" aria-label="মওকুফের কারণ">
                                    <button class="btn btn-sm btn-primary" type="submit">পাঠান</button>
                                  </form>
                                </td>
                              </tr>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-lg-7" id="concessions">
                <div class="card h-100">
                  <div class="card-header"><h3 class="card-title mb-0">ব্যক্তিগত ছাড়</h3></div>
                  <div class="card-body p-0">
                    <?php if ($concessions === []): ?>
                      <p class="text-secondary p-3 mb-0">কোনো ব্যক্তিগত ছাড় নেই।</p>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 acc-table-stack">
                          <thead><tr><th>ফি হেড</th><th>ছাড়</th><th>মেয়াদ</th><th>কারণ</th><th>অবস্থা</th><th></th></tr></thead>
                          <tbody>
                            <?php foreach ($concessions as $concession): ?>
                              <tr>
                                <td data-label="ফি হেড"><?= h((string) $concession['head_name']) ?></td>
                                <td data-label="ছাড়"><strong><?= h(ConcessionService::describe($concession)) ?></strong></td>
                                <td data-label="মেয়াদ"><?= h(bnDigits((string) $concession['effective_from'])) ?> — <?= $concession['effective_to'] ? h(bnDigits((string) $concession['effective_to'])) : 'চলমান' ?></td>
                                <td data-label="কারণ"><?= h((string) $concession['reason']) ?><?php if ((string) $concession['decision_note'] !== ''): ?><div class="small text-secondary"><?= h((string) $concession['decision_note']) ?></div><?php endif; ?></td>
                                <td data-label="অবস্থা"><span class="badge <?= h(ApprovalStatus::badge((string) $concession['status'])) ?>"><?= h(ApprovalStatus::label((string) $concession['status'])) ?></span></td>
                                <td class="text-end" data-label="কার্যক্রম">
                                  <?php if ($canApprove && $concession['status'] === ApprovalStatus::APPROVED && $concession['effective_to'] === null): ?>
                                    <form method="post" class="d-flex gap-1 justify-content-end" data-acc-confirm="এই ছাড় নির্ধারিত তারিখে শেষ হবে। নিশ্চিত?">
                                      <?= csrfField() ?>
                                      <?= signedRecordField($studentId, 'acc_student') ?>
                                      <?= signedRecordField((int) $concession['id'], 'acc_concession', 'concession_ref') ?>
                                      <input type="hidden" name="action" value="concession_end">
                                      <input class="form-control form-control-sm" type="date" name="last_day" required value="<?= h($today->format('Y-m-d')) ?>" aria-label="শেষ তারিখ">
                                      <button class="btn btn-sm btn-outline-danger text-nowrap" type="submit">শেষ করুন</button>
                                    </form>
                                  <?php endif; ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                  <?php if ($canRequestConcession): ?>
                    <form method="post" class="card-footer" data-acc-confirm="ব্যক্তিগত ছাড় <?= $canApprove ? 'নির্ধারণ' : 'আবেদন' ?> করবেন?">
                      <?= csrfField() ?>
                      <?= signedRecordField($studentId, 'acc_student') ?>
                      <input type="hidden" name="action" value="concession_request">
                      <div class="row g-2 align-items-end">
                        <div class="col-sm-4">
                          <label class="form-label small mb-1" for="con-head">ফি হেড</label>
                          <select class="form-select form-select-sm" name="fee_head_id" id="con-head" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach ($heads as $head): ?><option value="<?= (int) $head['id'] ?>"><?= h((string) $head['name_bn']) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-6 col-sm-3">
                          <label class="form-label small mb-1" for="con-kind">ধরন</label>
                          <select class="form-select form-select-sm" name="kind" id="con-kind" required>
                            <option value="<?= h(ConcessionService::PERCENT) ?>">শতাংশ (%)</option>
                            <option value="<?= h(ConcessionService::FIXED) ?>">নির্দিষ্ট টাকা</option>
                          </select>
                        </div>
                        <div class="col-6 col-sm-2">
                          <label class="form-label small mb-1" for="con-value">পরিমাণ</label>
                          <input class="form-control form-control-sm" name="value" id="con-value" inputmode="decimal" required maxlength="16">
                        </div>
                        <div class="col-sm-3">
                          <label class="form-label small mb-1" for="con-from">কার্যকর</label>
                          <input class="form-control form-control-sm" type="date" name="effective_from" id="con-from" required value="<?= h($defaultEffective) ?>" min="<?= h($sessionYear) ?>-01-01" max="<?= h($sessionYear) ?>-12-31">
                        </div>
                        <div class="col-sm-9">
                          <input class="form-control form-control-sm" name="reason" minlength="3" maxlength="255" required placeholder="ছাড়ের কারণ (যেমন: ভাই-বোন একই স্কুলে, আর্থিক অসচ্ছলতা)" aria-label="ছাড়ের কারণ">
                        </div>
                        <div class="col-sm-3"><button class="btn btn-sm btn-primary w-100" type="submit"><?= $canApprove ? 'নির্ধারণ' : 'আবেদন পাঠান' ?></button></div>
                      </div>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
              <div class="col-lg-5">
                <div class="card mb-3">
                  <div class="card-header"><h3 class="card-title mb-0">ফি-শ্রেণীর ইতিহাস</h3></div>
                  <ul class="list-group list-group-flush">
                    <?php if ($categoryHistory === []): ?><li class="list-group-item text-secondary">সবসময় সাধারণ।</li><?php endif; ?>
                    <?php foreach ($categoryHistory as $row): ?>
                      <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                          <strong><?= h((string) $row['category_name']) ?></strong>
                          <span class="badge <?= h(ApprovalStatus::badge((string) $row['status'])) ?>"><?= h(ApprovalStatus::label((string) $row['status'])) ?></span>
                        </div>
                        <div class="small text-secondary">
                          <?= h(bnDigits((string) $row['effective_from'])) ?> — <?= $row['effective_to'] ? h(bnDigits((string) $row['effective_to'])) : 'চলমান' ?>
                          <?= (string) $row['reference_no'] !== '' ? ' · ' . h((string) $row['reference_no']) : '' ?>
                          · আবেদন: <?= h((string) $row['requested_by_name']) ?>
                          <?= $row['decided_by_name'] ? ' · সিদ্ধান্ত: ' . h((string) $row['decided_by_name']) : '' ?>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <div class="card">
                  <div class="card-header"><h3 class="card-title mb-0">মওকুফের ইতিহাস</h3></div>
                  <ul class="list-group list-group-flush">
                    <?php if ($waivers === []): ?><li class="list-group-item text-secondary">কোনো মওকুফ নেই।</li><?php endif; ?>
                    <?php foreach ($waivers as $row): ?>
                      <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                          <span><code><?= h((string) $row['invoice_no']) ?></code> <?= h((string) $row['description']) ?> — <strong><?= h(Money::format(Money::fromDb($row['amount']))) ?></strong></span>
                          <span class="badge <?= h(ApprovalStatus::badge((string) $row['status'])) ?>"><?= h(ApprovalStatus::label((string) $row['status'])) ?></span>
                        </div>
                        <div class="small text-secondary"><?= h((string) $row['reason']) ?> · <?= h((string) $row['requested_by_name']) ?></div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
