<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Collection\HandoverService;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$canSubmit = $access->can(Capability::HANDOVER_SUBMIT);
$canReceive = $access->can(Capability::HANDOVER_RECEIVE);
if (!$canSubmit && !$canReceive && !$access->can(Capability::REPORTS_VIEW_ALL)) {
    redirectWithMessage('dashboard.php', 'danger', 'এই পাতার অনুমতি আপনার নেই।');
}
$handovers = accHandovers();
$basePath = '../../';
$pageTitle = 'টাকা জমা | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];
$status = (string) ($_GET['status'] ?? ($canReceive ? HandoverService::SUBMITTED : ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'submit') {
            $result = $handovers->submit(
                $access,
                accAudit(),
                (string) ($_POST['idempotency_key'] ?? ''),
                idsFromSignedRecordRefs(is_array($_POST['payments'] ?? null) ? $_POST['payments'] : [], 'acc_payment'),
                (string) ($_POST['note'] ?? '')
            );
            $handover = $handovers->handover($result['handover_id']);
            redirectWithMessage('handovers.php', $result['duplicate'] ? 'info' : 'success', $result['duplicate']
                ? 'এই হস্তান্তর আগেই জমা হয়েছে (' . $handover['handover_no'] . ')।'
                : 'হস্তান্তর ' . $handover['handover_no'] . ' জমা হয়েছে — ' . Money::format(Money::fromDb($handover['total'])) . '। হিসাব রক্ষক টাকা গুনে গ্রহণ করবেন।');
        }
        if ($action === 'receive' || $action === 'reject') {
            $handovers->decide($access, accAudit(), postedRecordId('acc_handover'), $action === 'receive', (string) ($_POST['counted'] ?? ''), (string) ($_POST['remark'] ?? ''));
            redirectWithMessage('handovers.php', 'success', $action === 'receive' ? 'হস্তান্তর গ্রহণ করা হয়েছে; টাকা এখন অফিসের ক্যাশে।' : 'হস্তান্তর ফেরত দেওয়া হয়েছে; রসিদগুলো আবার শিক্ষকের কাছে দেখাবে।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('handovers.php', 'danger', userFacingError($exception));
    }
}

$pending = $canSubmit ? $handovers->pendingPayments($access->teacherId()) : [];
$pendingTotal = array_sum(array_map(static fn (array $row): int => Money::fromDb($row['amount']), $pending));
$list = $handovers->search($status, $canReceive ? 0 : $access->teacherId());

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">টাকা জমা</h3>
            <p class="text-secondary mb-0">শ্রেণি শিক্ষকের আদায় করা নগদ টাকা হিসাব রক্ষকের কাছে জমা দেওয়ার হিসাব। হিসাব রক্ষক টাকা গুনে মিললে গ্রহণ করবেন, না মিললে কারণসহ ফেরত দেবেন।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <?php if ($canSubmit): ?>
              <form method="post" class="card mb-3" data-acc-confirm="নির্বাচিত রসিদের টাকা অফিসে হস্তান্তরের জন্য জমা দেবেন?">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="idempotency_key" value="<?= h(PaymentService::newIdempotencyKey()) ?>">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                  <h3 class="card-title mb-0 me-auto">আমার কাছে থাকা আদায়</h3>
                  <span class="fs-5 fw-semibold"><?= h(Money::format($pendingTotal)) ?></span>
                </div>
                <div class="card-body p-0">
                  <?php if ($pending === []): ?>
                    <p class="text-secondary p-3 mb-0">হস্তান্তরের মতো কোনো আদায় আপনার কাছে নেই।</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm align-middle mb-0 acc-table-stack">
                        <thead><tr><th style="width:2.5rem"><input class="form-check-input" type="checkbox" checked data-acc-check-all="payments[]" aria-label="সব রসিদ"></th><th>রসিদ নং</th><th>সময়</th><th>শিক্ষার্থী</th><th class="text-end">টাকা</th></tr></thead>
                        <tbody>
                          <?php foreach ($pending as $row): ?>
                            <tr>
                              <td data-label="নির্বাচন"><input class="form-check-input" type="checkbox" name="payments[]" value="<?= h(signedRecordRef((int) $row['id'], 'acc_payment')) ?>" checked aria-label="<?= h((string) $row['receipt_no']) ?>"></td>
                              <td data-label="রসিদ নং"><code><?= h((string) $row['receipt_no']) ?></code></td>
                              <td data-label="সময়"><?= h(accFormatDateTime((string) $row['collected_at'])) ?></td>
                              <td data-label="শিক্ষার্থী"><?= h((string) $row['name_snapshot']) ?> <span class="small text-secondary">(<?= h((string) $row['class_name_ban']) ?>, রোল <?= h(bnDigits((string) $row['roll_snapshot'])) ?>)</span></td>
                              <td class="text-end" data-label="টাকা"><?= h(Money::format(Money::fromDb($row['amount']))) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
                <?php if ($pending !== []): ?>
                  <div class="card-footer d-flex flex-wrap gap-2">
                    <input class="form-control form-control-sm flex-grow-1" style="max-width:28rem" name="note" maxlength="255" placeholder="মন্তব্য (ঐচ্ছিক)" aria-label="মন্তব্য">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-up-right me-1"></i>হস্তান্তর জমা দিন</button>
                  </div>
                <?php endif; ?>
              </form>
            <?php endif; ?>

            <div class="card">
              <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <h3 class="card-title mb-0 me-auto"><?= $canReceive ? 'হস্তান্তর' : 'আমার হস্তান্তর' ?></h3>
                <form method="get" class="d-flex gap-1">
                  <select class="form-select form-select-sm" name="status" aria-label="অবস্থা">
                    <option value="">সব</option>
                    <?php foreach (HandoverService::STATUS_LABELS as $key => $label): ?><option value="<?= h($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">দেখুন</button>
                </form>
              </div>
              <div class="card-body">
                <?php if ($list === []): ?><p class="text-secondary mb-0">কোনো হস্তান্তর নেই।</p><?php endif; ?>
                <?php foreach ($list as $handover): ?>
                  <?php $handoverId = (int) $handover['id']; $items = $handovers->items($handoverId); $open = $handover['status'] === HandoverService::SUBMITTED; ?>
                  <div class="border rounded mb-3">
                    <div class="d-flex flex-wrap gap-2 align-items-center p-2 bg-body-tertiary">
                      <code><?= h((string) $handover['handover_no']) ?></code>
                      <strong><?= h((string) $handover['teacher_name']) ?></strong>
                      <span class="badge <?= h(HandoverService::STATUS_BADGES[$handover['status']] ?? 'text-bg-light') ?>"><?= h(HandoverService::STATUS_LABELS[$handover['status']] ?? (string) $handover['status']) ?></span>
                      <span class="small text-secondary"><?= h(accFormatDateTime((string) $handover['submitted_at'])) ?> · <?= h(bnDigits((string) $handover['payment_count'])) ?>টি রসিদ</span>
                      <span class="ms-auto fs-5 fw-semibold"><?= h(Money::format(Money::fromDb($handover['total']))) ?></span>
                      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#ho-<?= $handoverId ?>">রসিদ</button>
                    </div>
                    <?php if ((string) $handover['note'] !== '' || $handover['decided_by_name']): ?>
                      <div class="small px-2 pt-2">
                        <?= (string) $handover['note'] !== '' ? 'মন্তব্য: ' . h((string) $handover['note']) : '' ?>
                        <?php if ($handover['decided_by_name']): ?> · সিদ্ধান্ত: <?= h((string) $handover['decided_by_name']) ?>, <?= h(accFormatDateTime((string) $handover['decided_at'])) ?><?= (string) $handover['remark'] !== '' ? ' — ' . h((string) $handover['remark']) : '' ?><?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <div class="collapse" id="ho-<?= $handoverId ?>">
                      <div class="table-responsive">
                        <table class="table table-sm mb-0 acc-table-stack">
                          <thead><tr><th>রসিদ নং</th><th>সময়</th><th>শিক্ষার্থী</th><th class="text-end">টাকা</th><th>অবস্থা</th></tr></thead>
                          <tbody>
                            <?php foreach ($items as $item): ?>
                              <tr>
                                <td data-label="রসিদ নং"><a href="<?= h(signedRecordUrl('receipt.php', (int) $item['id'], 'acc_payment')) ?>"><code><?= h((string) $item['receipt_no']) ?></code></a></td>
                                <td data-label="সময়"><?= h(accFormatDateTime((string) $item['collected_at'])) ?></td>
                                <td data-label="শিক্ষার্থী"><?= h((string) $item['name_snapshot']) ?></td>
                                <td class="text-end" data-label="টাকা"><?= h(Money::format(Money::fromDb($item['handed_amount']))) ?></td>
                                <td data-label="অবস্থা"><?= h(PaymentService::STATUS_LABELS[$item['status']] ?? (string) $item['status']) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <?php if ($canReceive && $open && (int) $handover['teacher_id'] !== $access->teacherId()): ?>
                      <div class="d-flex flex-wrap gap-2 p-2 border-top">
                        <form method="post" class="d-flex flex-wrap gap-1" data-acc-confirm="গণনাকৃত টাকা মিলেছে? হস্তান্তর গ্রহণ করবেন?" data-acc-confirm-variant="success">
                          <?= csrfField() ?><input type="hidden" name="action" value="receive"><?= signedRecordField($handoverId, 'acc_handover') ?>
                          <input class="form-control form-control-sm" style="max-width:10rem" name="counted" inputmode="decimal" required placeholder="গণনাকৃত টাকা" aria-label="গণনাকৃত টাকা">
                          <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg"></i> গ্রহণ</button>
                        </form>
                        <form method="post" class="d-flex flex-wrap gap-1 ms-sm-auto" data-acc-confirm="হস্তান্তরটি ফেরত দেবেন?" data-acc-confirm-variant="danger">
                          <?= csrfField() ?><input type="hidden" name="action" value="reject"><?= signedRecordField($handoverId, 'acc_handover') ?>
                          <input class="form-control form-control-sm" style="max-width:18rem" name="remark" minlength="3" maxlength="255" required placeholder="ফেরতের কারণ (যেমন: ৳৫০ কম)" aria-label="ফেরতের কারণ">
                          <button class="btn btn-sm btn-outline-danger" type="submit">ফেরত</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
