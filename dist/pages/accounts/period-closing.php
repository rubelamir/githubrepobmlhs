<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Ledger\PeriodClosing;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::PERIOD_CLOSE, Capability::REPORTS_VIEW_ALL]);
$canClose = $access->can(Capability::PERIOD_CLOSE);
$periods = accPeriods();
$basePath = '../../';
$pageTitle = 'মাস বন্ধ | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $period = (string) ($_POST['period'] ?? '');
    try {
        if ($action === 'close') {
            $periods->close($access, accAudit(), $period, (string) ($_POST['note'] ?? ''));
            redirectWithMessage('period-closing.php', 'success', PeriodClosing::label($period) . ' মাসের হিসাব বন্ধ হয়েছে।');
        }
        if ($action === 'reopen') {
            $periods->reopen($access, accAudit(), $period, (string) ($_POST['reason'] ?? ''));
            redirectWithMessage('period-closing.php', 'warning', PeriodClosing::label($period) . ' মাস পুনরায় খোলা হয়েছে। সংশোধন শেষে আবার বন্ধ করুন।');
        }
        throw new RuntimeException('অজানা অনুরোধ।');
    } catch (Throwable $exception) {
        redirectWithMessage('period-closing.php', 'danger', userFacingError($exception));
    }
}

$records = [];
foreach ($periods->all() as $row) {
    $records[(string) $row['period']] = $row;
}
$months = [];
$cursor = accToday()->modify('first day of this month');
for ($i = 0; $i < 12; $i++) {
    $months[] = $cursor->format('Y-m');
    $cursor = $cursor->modify('-1 month');
}
foreach (array_keys($records) as $period) {
    if (!in_array($period, $months, true)) {
        $months[] = $period;
    }
}
rsort($months);
$currentMonth = accToday()->format('Y-m');

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">মাস বন্ধ</h3>
            <p class="text-secondary mb-0">মাস শেষে সব ভাউচার অনুমোদন, ব্যাংক জমা যাচাই ও হস্তান্তর গ্রহণ শেষ হলে মাসটি বন্ধ করুন। বন্ধ মাসের তারিখে কোনো এন্ট্রি, অনুমোদন বা রিভার্সাল দেওয়া যায় না; বন্ধের সময়কার রেওয়ামিল সংরক্ষিত থাকে।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
            <div class="card">
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table align-middle mb-0 acc-table-stack">
                    <thead><tr><th>মাস</th><th>অবস্থা</th><th>বিস্তারিত</th><th></th></tr></thead>
                    <tbody>
                      <?php foreach ($months as $period): ?>
                        <?php
                        $record = $records[$period] ?? null;
                        $closed = $record !== null && $record['status'] === PeriodClosing::CLOSED;
                        $ended = $period < $currentMonth;
                        $blockers = !$closed && $ended ? $periods->blockers($period) : [];
                        $balances = $closed ? json_decode((string) $record['balances_json'], true) : null;
                        ?>
                        <tr>
                          <td data-label="মাস" class="fw-semibold"><?= h(PeriodClosing::label($period)) ?></td>
                          <td data-label="অবস্থা">
                            <?php if ($closed): ?><span class="badge text-bg-dark"><i class="bi bi-lock-fill"></i> বন্ধ</span>
                            <?php elseif ($record !== null): ?><span class="badge text-bg-warning">পুনরায় খোলা</span>
                            <?php elseif (!$ended): ?><span class="badge text-bg-info">চলতি মাস</span>
                            <?php else: ?><span class="badge border text-secondary">খোলা</span><?php endif; ?>
                          </td>
                          <td data-label="বিস্তারিত" class="small">
                            <?php if ($closed): ?>
                              <?= h((string) $record['closed_by_name']) ?>, <?= h(accFormatDateTime((string) $record['closed_at'])) ?><?= (string) $record['close_note'] !== '' ? ' — ' . h((string) $record['close_note']) : '' ?>
                              <?php if (is_array($balances)): ?><div class="text-secondary"><?= h(bnDigits((string) count($balances))) ?>টি হিসাবের স্থিতি সংরক্ষিত</div><?php endif; ?>
                            <?php elseif ($record !== null): ?>
                              খুলেছেন <?= h((string) $record['reopened_by_name']) ?>, <?= h(accFormatDateTime((string) $record['reopened_at'])) ?> — <?= h((string) $record['reopen_reason']) ?>
                            <?php endif; ?>
                            <?php foreach ($blockers as $blocker): ?><div class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= h($blocker) ?></div><?php endforeach; ?>
                          </td>
                          <td class="text-end" data-label="">
                            <?php if (!$canClose): ?>
                            <?php elseif ($closed): ?>
                              <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="collapse" data-bs-target="#reopen-<?= h($period) ?>">পুনরায় খুলুন</button>
                            <?php elseif ($ended && $blockers === []): ?>
                              <form method="post" class="d-flex gap-1 justify-content-end" data-acc-confirm="<?= h(PeriodClosing::label($period)) ?> মাস বন্ধ করবেন? এরপর এই মাসের তারিখে কোনো এন্ট্রি দেওয়া যাবে না।">
                                <?= csrfField() ?><input type="hidden" name="action" value="close"><input type="hidden" name="period" value="<?= h($period) ?>">
                                <input class="form-control form-control-sm" style="max-width:14rem" name="note" maxlength="255" placeholder="মন্তব্য (ঐচ্ছিক)" aria-label="মন্তব্য">
                                <button class="btn btn-sm btn-dark" type="submit"><i class="bi bi-lock"></i> বন্ধ করুন</button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>
                        <?php if ($canClose && $closed): ?>
                          <tr class="collapse" id="reopen-<?= h($period) ?>"><td colspan="4" class="bg-body-tertiary">
                            <form method="post" class="d-flex flex-wrap gap-1" data-acc-confirm="মাসটি পুনরায় খুলবেন? কারণটি অডিট লগে থাকবে।" data-acc-confirm-variant="danger">
                              <?= csrfField() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="period" value="<?= h($period) ?>">
                              <input class="form-control form-control-sm flex-grow-1" name="reason" required minlength="5" maxlength="255" placeholder="পুনরায় খোলার কারণ (যেমন: ভুল ভাউচার সংশোধন)" aria-label="কারণ">
                              <button class="btn btn-sm btn-warning" type="submit">খুলুন</button>
                            </form>
                          </td></tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
