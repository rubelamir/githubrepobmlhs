<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\BillingPeriod;
use School\Accounting\Billing\FeeStructureRepository;
use School\Accounting\Support\Money;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage([Capability::INVOICE_MANAGE, Capability::FEES_VIEW_ALL]);
$canGenerate = $access->can(Capability::INVOICE_MANAGE);
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
        if ($action !== 'generate') {
            throw new RuntimeException('অজানা অনুরোধ।');
        }
        if (!$ready) {
            throw new RuntimeException('শ্রেণী ও শাখা নির্বাচন করুন।');
        }
        $issueDate = FeeStructureRepository::parseDate((string) ($_POST['issue_date'] ?? ''), 'বিলের তারিখ');
        $kind = (string) ($_POST['billing_kind'] ?? '');
        $period = match ($kind) {
            BillingPeriod::MONTHLY => BillingPeriod::monthly((string) ($_POST['month'] ?? '')),
            BillingPeriod::OTHER => BillingPeriod::other((string) ($_POST['month'] ?? '')),
            BillingPeriod::SESSION => BillingPeriod::session($sessionYear),
            BillingPeriod::ADMISSION => BillingPeriod::admission($sessionYear),
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
        $postedHeads = array_values(array_filter(
            array_map('intval', is_array($_POST['heads'] ?? null) ? $_POST['heads'] : []),
            static fn (int $id): bool => $id > 0
        ));
        $allowedHeads = [];
        foreach (accCatalog()->heads(true) as $head) {
            if (BillingPeriod::headMatchesInvoiceKind($head, $kind)) {
                $allowedHeads[] = (int) $head['id'];
            }
        }
        $headIds = array_values(array_intersect($postedHeads, $allowedHeads));
        if ($headIds === []) {
            throw new RuntimeException('যে ধরনের বিল তৈরি করছেন, সেই ধরনের অন্তত একটি ফিতে টিক দিন। টিক না দিলে বিল তৈরি হবে না।');
        }
        $result = $invoices->generate($access, accAudit(), $sessionYear, $classId, $sectionId, $period, $headIds, $issueDate);
        $message = $period->label() . ': ' . bnDigits((string) $result['students']) . ' জন শিক্ষার্থী — নতুন বিল '
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
    } catch (Throwable $exception) {
        redirectWithMessage($back, 'danger', userFacingError($exception));
    }
}

$heads = accCatalog()->heads(true);
$defaultMonth = $sessionYear . '-01';
if ($ready) {
    $defaultMonth = BillingPeriod::nextOpenMonth($sessionYear, $invoices->liveMonthlyKeys($sessionYear, $classId, $sectionId));
}

include __DIR__ . '/../../includes/layout-head.php';
?>
        <div class="app-content-header acc-page-header">
          <div class="container-fluid">
            <h3 class="mb-1">মাসের বিল</h3>
            <p class="text-secondary mb-0">শ্রেণী বেছে বিলের ধরন দিন। যে ফিতে টিক দেবেন, শুধু সেই ফির বিল তৈরি হবে। টিক না দিলে বিল তৈরি হবে না। একই ফি দুবার হয় না।</p>
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
                  <form method="post" class="card h-100" data-acc-confirm="নির্বাচিত শ্রেণী/শাখার সব ভর্তি শিক্ষার্থীর বিল তৈরি হবে। চালিয়ে যাবেন?">
                    <?= csrfField() ?>
                    <?php foreach ($query as $key => $value): ?><input type="hidden" name="<?= h($key) ?>" value="<?= h((string) $value) ?>"><?php endforeach; ?>
                    <input type="hidden" name="action" value="generate">
                    <div class="card-header"><h3 class="card-title mb-0">এই মাসের বিল তৈরি</h3></div>
                    <div class="card-body">
                      <div class="row g-2">
                        <div class="col-md-6 col-lg-3">
                          <label class="form-label" for="inv-kind">বিলের ধরন</label>
                          <select class="form-select" name="billing_kind" id="inv-kind" required data-acc-kind-select>
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach (BillingPeriod::INVOICE_KIND_LABELS as $kind => $label): ?>
                              <option value="<?= h($kind) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-6 col-lg-3" data-acc-kind="monthly other" hidden>
                          <label class="form-label" for="inv-month">মাস</label>
                          <input class="form-control" type="month" name="month" id="inv-month" required value="<?= h($defaultMonth) ?>" min="<?= h($sessionYear) ?>-01" max="<?= h($sessionYear) ?>-12">
                        </div>
                        <div class="col-md-6 col-lg-3" data-acc-kind="exam" hidden>
                          <label class="form-label" for="inv-exam">পরীক্ষা</label>
                          <select class="form-select" name="exam_id" id="inv-exam" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach ($exams as $exam): ?><option value="<?= (int) $exam['id'] ?>"><?= h((string) $exam['exam_name_bangla']) ?></option><?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-6 col-lg-3" data-acc-kind="monthly session admission exam other" hidden>
                          <label class="form-label" for="inv-issue">বিলের তারিখ <span class="text-secondary small">(পরে বদলানো যাবে)</span></label>
                          <input class="form-control" type="date" name="issue_date" id="inv-issue" data-acc-issue-date required value="<?= h($today->format('Y-m-d')) ?>">
                        </div>
                      </div>
                      <div class="mt-3" data-acc-kind="monthly session admission exam other" hidden>
                        <span class="form-label d-block">কোন ফি <span class="text-secondary small">(অন্তত একটিতে টিক দিন)</span></span>
                        <?php foreach (BillingPeriod::INVOICE_KIND_LABELS as $kind => $label): ?>
                          <div data-acc-kind="<?= h($kind) ?>" hidden>
                            <?php $kindHeads = array_filter($heads, static fn (array $head): bool => BillingPeriod::headMatchesInvoiceKind($head, $kind)); ?>
                            <?php if ($kindHeads === []): ?><span class="small text-secondary">এই ধরনের কোনো সক্রিয় ফি নেই।</span><?php endif; ?>
                            <?php foreach ($kindHeads as $head): ?>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="heads[]" value="<?= (int) $head['id'] ?>" id="inv-head-<?= (int) $head['id'] ?>">
                                <label class="form-check-label" for="inv-head-<?= (int) $head['id'] ?>"><?= h((string) $head['name_bn']) ?></label>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endforeach; ?>
                        <p class="small text-danger mb-0 mt-2" data-acc-heads-error hidden>এই ধরনের অন্তত একটি ফিতে টিক দিন। টিক না দিলে বিল তৈরি হবে না।</p>
                      </div>
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-receipt me-1"></i>বিল তৈরি করুন</button></div>
                  </form>
                </div>
              </div>
              <?php endif; ?>
          </div>
        </div>
<?php include __DIR__ . '/../../includes/layout-end.php'; ?>
