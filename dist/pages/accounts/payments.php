<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Billing\FeeStructureRepository;
use School\Accounting\Collection\HandoverService;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::FEES_COLLECT, Capability::FEES_VIEW_ALL]);
$payments = accPayments();
$schoolWide = $access->hasSchoolWideScope();
$canReverse = $access->can(Capability::PAYMENT_REVERSE);
$basePath = '../../';
$pageTitle = 'রসিদ | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$today = accToday()->format('Y-m-d');
$dateOf = static function (string $value, string $fallback): string {
    try {
        return FeeStructureRepository::parseDate($value, 'তারিখ')->format('Y-m-d');
    } catch (Throwable) {
        return $fallback;
    }
};
$from = $dateOf((string) ($_GET['from'] ?? ''), $today);
$to = $dateOf((string) ($_GET['to'] ?? ''), $today);
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$collector = $schoolWide ? (int) ($_GET['collector'] ?? 0) : $access->teacherId();
$status = (string) ($_GET['status'] ?? '');
$query = ['from' => $from, 'to' => $to, 'collector' => $schoolWide ? $collector : 0, 'status' => $status];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'payments.php?' . http_build_query(array_intersect_key($_POST, $query));
    try {
        if ((string) ($_POST['action'] ?? '') !== 'reverse') {
            throw new RuntimeException('অজানা অনুরোধ।');
        }
        $payments->reverse($access, accAudit(), postedRecordId('acc_payment'), (string) ($_POST['reason'] ?? ''));
        redirectWithMessage($back, 'success', 'রসিদটি রিভার্স হয়েছে; সংশ্লিষ্ট বকেয়া আবার খোলা হয়েছে।');
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$rows = $payments->search(['from' => $from, 'to' => $to, 'collector' => $collector, 'status' => $status]);
$totals = ['confirmed' => 0, 'count' => 0, 'reversed' => 0, 'teacher' => 0, 'office' => 0];
$collectors = [];
foreach ($rows as $row) {
    $paisa = Money::fromDb($row['amount']);
    if ($row['status'] === PaymentService::CONFIRMED) {
        $totals['confirmed'] += $paisa;
        $totals['count']++;
        $totals[$row['custody'] === PaymentService::CUSTODY_TEACHER ? 'teacher' : 'office'] += $paisa;
    } else {
        $totals['reversed'] += $paisa;
    }
}
$collectorOptions = [];
if ($schoolWide) {
    foreach (fetchRows(getDatabaseConnection(), 'SELECT DISTINCT p.collected_by, t.teacher_name FROM acc_payments p LEFT JOIN teacher_info t ON t.id = p.collected_by ORDER BY t.teacher_name') as $option) {
        $collectorOptions[(int) $option['collected_by']] = (string) $option['teacher_name'];
    }
}
$custody = $access->can(Capability::HANDOVER_RECEIVE) ? $payments->teacherCustody() : [];

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">রসিদ</h3>
            <p class="text-secondary mb-0"><?= $schoolWide ? 'সব আদায়ের রসিদ।' : 'আপনার নেওয়া আদায়ের রসিদ।' ?> কোনো রসিদ মুছে ফেলা যায় না; ভুল হলে প্রধান শিক্ষক কারণসহ রিভার্স করবেন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="card mb-3">
              <form method="get" class="card-body row g-2 align-items-end">
                <div class="col-6 col-lg-2"><label class="form-label" for="p-from">থেকে</label><input class="form-control" type="date" name="from" id="p-from" value="<?= h($from) ?>"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="p-to">পর্যন্ত</label><input class="form-control" type="date" name="to" id="p-to" value="<?= h($to) ?>"></div>
                <?php if ($schoolWide): ?>
                  <div class="col-sm-6 col-lg-3">
                    <label class="form-label" for="p-collector">আদায়কারী</label>
                    <select class="form-select" name="collector" id="p-collector">
                      <option value="0">সবাই</option>
                      <?php foreach ($collectorOptions as $id => $name): ?><option value="<?= $id ?>"<?= $id === $collector ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <div class="col-sm-6 col-lg-2">
                  <label class="form-label" for="p-status">অবস্থা</label>
                  <select class="form-select" name="status" id="p-status">
                    <option value="">সব</option>
                    <?php foreach (PaymentService::STATUS_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-lg-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>দেখুন</button></div>
              </form>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">মোট আদায় (<?= h(bnDigits((string) $totals['count'])) ?>টি রসিদ)</div><div class="acc-stat-value text-success"><?= h(Money::format($totals['confirmed'])) ?></div></div></div>
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">অফিসের ক্যাশে</div><div class="acc-stat-value"><?= h(Money::format($totals['office'])) ?></div></div></div>
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">শিক্ষকের কাছে</div><div class="acc-stat-value text-warning-emphasis"><?= h(Money::format($totals['teacher'])) ?></div></div></div>
              <div class="col-6 col-lg-3"><div class="acc-stat"><div class="acc-stat-label">রিভার্সড</div><div class="acc-stat-value text-secondary"><?= h(Money::format($totals['reversed'])) ?></div></div></div>
            </div>

            <?php if ($custody !== []): ?>
              <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">শিক্ষকদের কাছে থাকা আদায় (এখনো হস্তান্তর হয়নি)</h3></div>
                <ul class="list-group list-group-flush">
                  <?php foreach ($custody as $holder): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                      <span><?= h((string) $holder['teacher_name']) ?> — <?= h(bnDigits((string) $holder['receipts'])) ?>টি রসিদ, প্রথমটি <?= h(accFormatDateTime((string) $holder['oldest'])) ?></span>
                      <strong><?= h(Money::format(Money::fromDb($holder['total']))) ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="card">
              <div class="card-body p-0">
                <?php if ($rows === []): ?>
                  <p class="text-secondary p-3 mb-0">এই সময়ে কোনো আদায় নেই।</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 acc-table-stack">
                      <thead><tr><th>রসিদ নং</th><th>সময়</th><th>শিক্ষার্থী</th><th>শ্রেণী</th><th class="text-end">টাকা</th><th>আদায়কারী</th><th>টাকা কোথায়</th><th>অবস্থা</th><th class="text-end">কার্যক্রম</th></tr></thead>
                      <tbody>
                        <?php foreach ($rows as $row): ?>
                          <?php $rowId = (int) $row['id']; $confirmed = $row['status'] === PaymentService::CONFIRMED; ?>
                          <tr class="<?= $confirmed ? '' : 'text-secondary' ?>">
                            <td data-label="রসিদ নং"><code><?= h((string) $row['receipt_no']) ?></code></td>
                            <td data-label="সময়"><?= h(accFormatDateTime((string) $row['collected_at'])) ?></td>
                            <td data-label="শিক্ষার্থী"><?= h((string) $row['name_snapshot']) ?> <span class="small text-secondary">(রোল <?= h(bnDigits((string) $row['roll_snapshot'])) ?>)</span></td>
                            <td data-label="শ্রেণী"><?= h((string) $row['class_name_ban']) ?><?= trim((string) $row['section_name_ban']) !== '' ? ' — ' . h((string) $row['section_name_ban']) : '' ?></td>
                            <td class="text-end" data-label="টাকা"><strong class="<?= $confirmed ? '' : 'text-decoration-line-through' ?>"><?= h(Money::format(Money::fromDb($row['amount']))) ?></strong></td>
                            <td data-label="আদায়কারী"><?= h((string) $row['collected_by_name']) ?></td>
                            <td data-label="টাকা কোথায়">
                              <?= h(PaymentService::CUSTODY_LABELS[$row['custody']] ?? (string) $row['custody']) ?>
                              <?php if ($row['handover_no']): ?><div class="small text-secondary"><?= h((string) $row['handover_no']) ?> · <?= h(HandoverService::STATUS_LABELS[$row['handover_status']] ?? '') ?></div><?php endif; ?>
                            </td>
                            <td data-label="অবস্থা"><span class="badge <?= $confirmed ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h(PaymentService::STATUS_LABELS[$row['status']] ?? (string) $row['status']) ?></span></td>
                            <td class="text-end text-nowrap" data-label="কার্যক্রম">
                              <a class="btn btn-sm btn-outline-secondary" href="<?= h(signedRecordUrl('receipt.php', $rowId, 'acc_payment')) ?>"><i class="bi bi-printer"></i> রসিদ</a>
                              <?php if ($canReverse && $confirmed): ?>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#rev-<?= $rowId ?>">রিভার্স</button>
                              <?php endif; ?>
                            </td>
                          </tr>
                          <?php if ($canReverse && $confirmed): ?>
                            <tr class="collapse" id="rev-<?= $rowId ?>">
                              <td colspan="9">
                                <form method="post" class="d-flex flex-wrap gap-2" data-acc-confirm="<?= h((string) $row['receipt_no']) ?> রিভার্স করবেন? রসিদটি বাতিল হিসেবে থাকবে এবং বকেয়া আবার খুলবে।" data-acc-confirm-variant="danger">
                                  <?= csrfField() ?>
                                  <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                                  <input type="hidden" name="action" value="reverse">
                                  <?= signedRecordField($rowId, 'acc_payment') ?>
                                  <input class="form-control form-control-sm flex-grow-1" style="max-width:28rem" name="reason" minlength="3" maxlength="255" required placeholder="রিভার্সের কারণ" aria-label="রিভার্সের কারণ">
                                  <button class="btn btn-sm btn-danger" type="submit">রিভার্স নিশ্চিত</button>
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
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
