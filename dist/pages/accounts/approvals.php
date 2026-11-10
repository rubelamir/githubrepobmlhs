<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Billing\ConcessionService;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::CONCESSION_APPROVE);
$basePath = '../../';
$pageTitle = 'ছাড়ের অনুমোদন | School Admin';
$pageCssFiles = [accAssetUrl($basePath, 'css/accounts.css')];
$pageScriptFiles = [accAssetUrl($basePath, 'js/accounts.js')];

$kinds = [
    'category' => ['scope' => 'acc_sfc', 'service' => 'accStudentCategories', 'label' => 'ফি-শ্রেণী'],
    'concession' => ['scope' => 'acc_concession', 'service' => 'accConcessions', 'label' => 'ব্যক্তিগত ছাড়'],
    'waiver' => ['scope' => 'acc_waiver', 'service' => 'accWaivers', 'label' => 'মওকুফ'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $kind = (string) ($_POST['kind'] ?? '');
    try {
        if (!isset($kinds[$kind])) {
            throw new RuntimeException('অজানা অনুরোধ।');
        }
        $decision = (string) ($_POST['decision'] ?? '');
        if ($decision !== 'approve' && $decision !== 'reject') {
            throw new RuntimeException('অনুমোদন বা প্রত্যাখ্যান নির্বাচন করুন।');
        }
        $id = postedRecordId($kinds[$kind]['scope']);
        if ($id < 1) {
            throw new RuntimeException('আবেদনটি পাওয়া যায়নি।');
        }
        $note = (string) ($_POST['note'] ?? '');
        if ($decision === 'reject' && mb_strlen(trim($note), 'UTF-8') < 3) {
            throw new RuntimeException('প্রত্যাখ্যানের কারণ লিখুন।');
        }
        ($kinds[$kind]['service'])()->decide($access, accAudit(), $id, $decision === 'approve', $note);
        redirectWithMessage('approvals.php#' . $kind, 'success', $kinds[$kind]['label'] . ' আবেদন ' . ($decision === 'approve' ? 'অনুমোদিত' : 'প্রত্যাখ্যাত') . ' হয়েছে।');
    } catch (Throwable $exception) {
        redirectWithMessage('approvals.php', 'danger', userFacingError($exception));
    }
}

$pendingCategories = accStudentCategories()->pending();
$pendingConcessions = accConcessions()->pending();
$pendingWaivers = accWaivers()->pending();

$classText = static function (array $row): string {
    $text = trim((string) ($row['class_name_ban'] ?? ''));
    $section = trim((string) ($row['section_name_ban'] ?? ''));

    return $text . ($section !== '' ? ' — ' . $section : '');
};
$studentText = static function (array $row): string {
    $name = trim((string) ($row['name_ban'] ?? '')) ?: trim((string) ($row['name_eng'] ?? '')) ?: trim((string) ($row['name_snapshot'] ?? ''));
    $roll = (string) ($row['cls_roll'] ?? $row['roll_snapshot'] ?? '');

    return $name . ($roll !== '' ? ' (রোল ' . bnDigits($roll) . ')' : '');
};
$decisionForm = static function (string $kind, string $scope, int $id): string {
    $collapse = 'dec-' . $kind . '-' . $id;

    return '<div class="d-flex flex-wrap gap-1 justify-content-end">'
        . '<form method="post" class="d-inline" data-acc-confirm="আবেদনটি অনুমোদন করবেন?" data-acc-confirm-variant="success">'
        . csrfField() . '<input type="hidden" name="kind" value="' . h($kind) . '"><input type="hidden" name="decision" value="approve">'
        . signedRecordField($id, $scope)
        . '<button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg"></i> অনুমোদন</button></form>'
        . '<button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#' . h($collapse) . '" aria-expanded="false">প্রত্যাখ্যান</button>'
        . '</div><div class="collapse mt-2" id="' . h($collapse) . '">'
        . '<form method="post" class="d-flex gap-1" data-acc-confirm="আবেদনটি প্রত্যাখ্যান করবেন?" data-acc-confirm-variant="danger">'
        . csrfField() . '<input type="hidden" name="kind" value="' . h($kind) . '"><input type="hidden" name="decision" value="reject">'
        . signedRecordField($id, $scope)
        . '<input class="form-control form-control-sm" name="note" minlength="3" maxlength="255" required placeholder="কারণ" aria-label="প্রত্যাখ্যানের কারণ">'
        . '<button class="btn btn-sm btn-danger" type="submit">পাঠান</button></form></div>';
};

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">ছাড়ের অনুমোদন</h3>
            <p class="text-secondary mb-0">শ্রেণি শিক্ষক ও অ্যাকাউন্টস অফিসারের পাঠানো ফি-শ্রেণী পরিবর্তন, ব্যক্তিগত ছাড় ও মওকুফের আবেদন। অনুমোদনের আগে কোনো আবেদন হিসাবে কার্যকর হয় না।</p>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>

            <div class="card mb-3" id="category">
              <div class="card-header"><h3 class="card-title mb-0">ফি-শ্রেণী পরিবর্তন <span class="badge text-bg-warning"><?= h(bnDigits((string) count($pendingCategories))) ?></span></h3></div>
              <div class="card-body p-0">
                <?php if ($pendingCategories === []): ?>
                  <p class="text-secondary p-3 mb-0">কোনো অপেক্ষমাণ আবেদন নেই।</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0 acc-table-stack">
                      <thead><tr><th>শিক্ষার্থী</th><th>শ্রেণী</th><th>নতুন ফি-শ্রেণী</th><th>কার্যকর</th><th>আদেশ নম্বর / মন্তব্য</th><th>আবেদনকারী</th><th class="text-end">সিদ্ধান্ত</th></tr></thead>
                      <tbody>
                        <?php foreach ($pendingCategories as $row): ?>
                          <tr>
                            <td data-label="শিক্ষার্থী"><?= h($studentText($row)) ?></td>
                            <td data-label="শ্রেণী"><?= h($classText($row)) ?></td>
                            <td data-label="নতুন ফি-শ্রেণী"><span class="badge text-bg-info"><?= h((string) $row['category_name']) ?></span></td>
                            <td data-label="কার্যকর"><?= h(bnDigits((string) $row['effective_from'])) ?></td>
                            <td data-label="আদেশ নম্বর / মন্তব্য"><?= h((string) $row['reference_no']) ?><?php if ((string) $row['note'] !== ''): ?><div class="small text-secondary"><?= h((string) $row['note']) ?></div><?php endif; ?></td>
                            <td data-label="আবেদনকারী"><?= h((string) $row['requested_by_name']) ?><div class="small text-secondary"><?= h(accFormatDateTime((string) $row['requested_at'])) ?></div></td>
                            <td data-label="সিদ্ধান্ত"><?= $decisionForm('category', 'acc_sfc', (int) $row['id']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card mb-3" id="concession">
              <div class="card-header"><h3 class="card-title mb-0">ব্যক্তিগত ছাড় <span class="badge text-bg-warning"><?= h(bnDigits((string) count($pendingConcessions))) ?></span></h3></div>
              <div class="card-body p-0">
                <?php if ($pendingConcessions === []): ?>
                  <p class="text-secondary p-3 mb-0">কোনো অপেক্ষমাণ আবেদন নেই।</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0 acc-table-stack">
                      <thead><tr><th>শিক্ষার্থী</th><th>শ্রেণী</th><th>ফি হেড</th><th>ছাড়</th><th>কার্যকর</th><th>কারণ</th><th>আবেদনকারী</th><th class="text-end">সিদ্ধান্ত</th></tr></thead>
                      <tbody>
                        <?php foreach ($pendingConcessions as $row): ?>
                          <tr>
                            <td data-label="শিক্ষার্থী"><?= h($studentText($row)) ?></td>
                            <td data-label="শ্রেণী"><?= h($classText($row)) ?></td>
                            <td data-label="ফি হেড"><?= h((string) $row['head_name']) ?></td>
                            <td data-label="ছাড়"><strong><?= h(ConcessionService::describe($row)) ?></strong></td>
                            <td data-label="কার্যকর"><?= h(bnDigits((string) $row['effective_from'])) ?></td>
                            <td data-label="কারণ"><?= h((string) $row['reason']) ?></td>
                            <td data-label="আবেদনকারী"><?= h((string) $row['requested_by_name']) ?><div class="small text-secondary"><?= h(accFormatDateTime((string) $row['requested_at'])) ?></div></td>
                            <td data-label="সিদ্ধান্ত"><?= $decisionForm('concession', 'acc_concession', (int) $row['id']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card mb-3" id="waiver">
              <div class="card-header"><h3 class="card-title mb-0">মওকুফ <span class="badge text-bg-warning"><?= h(bnDigits((string) count($pendingWaivers))) ?></span></h3></div>
              <div class="card-body p-0">
                <?php if ($pendingWaivers === []): ?>
                  <p class="text-secondary p-3 mb-0">কোনো অপেক্ষমাণ আবেদন নেই।</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0 acc-table-stack">
                      <thead><tr><th>শিক্ষার্থী</th><th>শ্রেণী</th><th>ইনভয়েস / খাত</th><th class="text-end">পরিমাণ</th><th>কারণ</th><th>আবেদনকারী</th><th class="text-end">সিদ্ধান্ত</th></tr></thead>
                      <tbody>
                        <?php foreach ($pendingWaivers as $row): ?>
                          <tr>
                            <td data-label="শিক্ষার্থী"><?= h($studentText($row)) ?></td>
                            <td data-label="শ্রেণী"><?= h($classText($row)) ?></td>
                            <td data-label="ইনভয়েস / খাত"><code><?= h((string) $row['invoice_no']) ?></code><div class="small"><?= h((string) $row['description']) ?></div></td>
                            <td class="text-end" data-label="পরিমাণ"><strong><?= h(Money::format(Money::fromDb($row['amount']))) ?></strong></td>
                            <td data-label="কারণ"><?= h((string) $row['reason']) ?></td>
                            <td data-label="আবেদনকারী"><?= h((string) $row['requested_by_name']) ?><div class="small text-secondary"><?= h(accFormatDateTime((string) $row['requested_at'])) ?></div></td>
                            <td data-label="সিদ্ধান্ত"><?= $decisionForm('waiver', 'acc_waiver', (int) $row['id']) ?></td>
                          </tr>
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
