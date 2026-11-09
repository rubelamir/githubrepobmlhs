<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/fee-portal.php';

use School\Accounting\Billing\StudentDirectory;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Gateway\GuardianPortal;
use School\Accounting\Gateway\OnlinePaymentService;
use School\Accounting\Support\Money;

$portalDb = publicDatabase();
$portalServices = feePortalServices();
$portalOnline = $portalServices['online'];
$portalPayments = $portalServices['payments'];
$portalStudentId = GuardianPortal::sessionStudentId();
$portalPending = is_array($_SESSION[GuardianPortal::PENDING_KEY] ?? null) ? $_SESSION[GuardianPortal::PENDING_KEY] : null;
if ($portalPending !== null && (int) ($portalPending['expires'] ?? 0) < time()) {
    unset($_SESSION[GuardianPortal::PENDING_KEY]);
    $portalPending = null;
}
$portalError = '';
$portalReview = null;
$portalLogin = ['cls' => 0, 'sections' => 0, 'roll' => '', 'mobile' => ''];

/** @return array<int, array{invoice_id: int, invoice_no: string, label: string, due_date: string, outstanding: int}> */
$portalDues = static function (int $studentId) use ($portalPayments): array {
    $dues = [];
    foreach ($portalPayments->outstandingLines($studentId) as $line) {
        $invoiceId = (int) $line['invoice_id'];
        $dues[$invoiceId] ??= [
            'invoice_id' => $invoiceId,
            'invoice_no' => (string) $line['invoice_no'],
            'label' => (string) $line['billing_label'],
            'due_date' => (string) $line['invoice_due'],
            'outstanding' => 0,
        ];
        $dues[$invoiceId]['outstanding'] += Money::fromDb($line['net']) - Money::fromDb($line['paid']);
    }

    return $dues;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verifyCsrfToken()) {
        feePortalRedirect('fee-payment.php', 'danger', 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। পাতাটি আবার খুলে চেষ্টা করুন।');
    }
    try {
        if ($action === 'logout') {
            GuardianPortal::endSession();
            feePortalRedirect('fee-payment.php', 'info', 'আপনি পোর্টাল থেকে বের হয়েছেন।');
        }
        if ($portalStudentId === 0) {
            if ($action === 'otp_request') {
                $portalLogin = [
                    'cls' => (int) ($_POST['cls'] ?? 0),
                    'sections' => (int) ($_POST['sections'] ?? 0),
                    'roll' => trim((string) ($_POST['roll'] ?? '')),
                    'mobile' => trim((string) ($_POST['mobile'] ?? '')),
                ];
                if (!smsGatewayReady($portalDb)) {
                    throw new RuntimeException('এই মুহূর্তে SMS সেবা চালু নেই, তাই পোর্টালে প্রবেশ করা যাচ্ছে না। বিদ্যালয়ে যোগাযোগ করুন।');
                }
                $sent = feePortal()->requestOtp($portalLogin['cls'], $portalLogin['sections'], $portalLogin['roll'], $portalLogin['mobile'], loginClientIp());
                $mobileDigits = preg_replace('/\D+/', '', asciiDigits($portalLogin['mobile'])) ?? '';
                $_SESSION[GuardianPortal::PENDING_KEY] = [
                    'otp_id' => $sent['otp_id'] ?? 0,
                    'student_id' => $sent['student_id'] ?? 0,
                    'mobile_tail' => substr($mobileDigits, -3),
                    'expires' => time() + 300,
                ];
                feePortalRedirect('fee-payment.php', 'info', 'তথ্য মিলে গেলে নিবন্ধিত মোবাইল নম্বরে ৬ সংখ্যার কোড পাঠানো হয়েছে।');
            }
            if ($action === 'otp_verify' && $portalPending !== null) {
                $studentId = feePortal()->verifyOtp((int) $portalPending['otp_id'], (int) $portalPending['student_id'], (string) ($_POST['code'] ?? ''));
                if ($studentId < 1) {
                    throw new RuntimeException('কোডটি সঠিক নয়। আবার চেষ্টা করুন।');
                }
                GuardianPortal::startSession($studentId);
                feePortalRedirect('fee-payment.php', 'success', 'স্বাগতম! নিচে বকেয়া ও পেমেন্টের তথ্য দেখুন।');
            }
            if ($action === 'otp_cancel') {
                unset($_SESSION[GuardianPortal::PENDING_KEY]);
                feePortalRedirect('fee-payment.php');
            }
            feePortalRedirect('fee-payment.php', 'warning', 'আগে পোর্টালে প্রবেশ করুন।');
        }

        if ($action === 'review' || $action === 'pay') {
            $gateway = (string) ($_POST['gateway'] ?? '');
            if (!$portalOnline->isAvailable($gateway)) {
                throw new RuntimeException('এই মুহূর্তে অনলাইন পেমেন্ট চালু নেই।');
            }
            $dues = $portalDues($portalStudentId);
            $selected = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['invoices'] ?? [])), static fn (int $id): bool => isset($dues[$id]))));
            if ($selected === []) {
                throw new RuntimeException('কোন ইনভয়েস পরিশোধ করবেন তা নির্বাচন করুন।');
            }
            if ($action === 'pay') {
                $started = $portalOnline->start($gateway, $portalStudentId, $selected, (string) ($_POST['idempotency_key'] ?? ''), loginClientIp(), feePortalAbsoluteUrl('fee-payment-callback.php'));
                $_SESSION['acc_portal_last_txn'] = $started['txn_id'];
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Location: ' . $started['redirect'], true, 303);
                exit;
            }
            $fee = 0;
            foreach ($selected as $invoiceId) {
                $fee += $dues[$invoiceId]['outstanding'];
            }
            $portalReview = [
                'gateway' => $gateway,
                'invoices' => array_map(static fn (int $id): array => $dues[$id], $selected),
                'quote' => $portalOnline->quote($gateway, $fee),
                'rate' => $portalOnline->settings($gateway)['charge_rate_bp'],
                'key' => PaymentService::newIdempotencyKey(),
            ];
        }
    } catch (RuntimeException $exception) {
        $portalError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Fee portal: ' . $exception->getMessage());
        $portalError = 'একটি সমস্যা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।';
    }
}

$portalFlash = feePortalFlash();
$portalStudent = $portalStudentId > 0 ? (new StudentDirectory($portalDb))->find($portalStudentId) : null;
$portalGateways = $portalOnline->availableGateways();
$portalClasses = schoolClasses($portalDb, true);
$portalSectionMap = [];
foreach ($portalClasses as $portalClass) {
    $portalSectionMap[(int) $portalClass['id']] = array_map(
        static fn (array $section): array => ['id' => (int) $section['id'], 'name' => trim((string) ($section['section_name_ban'] ?? '')) ?: (string) ($section['section_name'] ?? '')],
        schoolSections($portalDb, (int) $portalClass['id'], true)
    );
}

$pageAssets = [];
$pageStylesheets = ['css/fee-portal.css'];
$pageMeta = ['title' => 'অনলাইনে ফি পরিশোধ', 'description' => 'অভিভাবকদের জন্য ফি বকেয়া দেখা ও bKash-এ অনলাইনে পরিশোধ।', 'noindex' => true];
$keepDocumentOpen = true;
include 'header.php';
include 'navbar&marqueetag.php';
schoolPageHero('অনলাইনে ফি পরিশোধ', 'শিক্ষার্থীর বকেয়া দেখুন, অনলাইনে পরিশোধ করুন ও রসিদ সংগ্রহ করুন', 'অভিভাবক পোর্টাল');
?>
<div class="fee-portal container py-4 py-lg-5">
    <?php if ($portalFlash !== null && $portalFlash['message'] !== ''): ?>
    <div class="alert <?= h(feePortalAlertClass($portalFlash['type'])) ?>" role="status"><?= h($portalFlash['message']) ?></div>
    <?php endif; ?>
    <?php if ($portalError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= h($portalError) ?></div>
    <?php endif; ?>

    <?php if ($portalStudent === null): ?>
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <?php if ($portalPending !== null): ?>
            <section class="fee-portal-card">
                <h2 class="h5">কোড যাচাই</h2>
                <p class="text-muted small">মোবাইল নম্বর (…<?= h(bnDigits((string) $portalPending['mobile_tail'])) ?>)-এ পাঠানো ৬ সংখ্যার কোডটি লিখুন। কোড ৫ মিনিট বৈধ।</p>
                <form method="post" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="otp_verify">
                    <label class="form-label" for="fp-code">কোড</label>
                    <input class="form-control form-control-lg text-center fee-portal-otp" id="fp-code" name="code" inputmode="numeric" pattern="[0-9০-৯]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
                    <button class="btn btn-success w-100 mt-3" type="submit">প্রবেশ করুন</button>
                </form>
                <form method="post" class="mt-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="otp_cancel">
                    <button class="btn btn-link w-100" type="submit">তথ্য বদলে আবার কোড নিন</button>
                </form>
            </section>
            <?php else: ?>
            <section class="fee-portal-card">
                <h2 class="h5">পোর্টালে প্রবেশ</h2>
                <p class="text-muted small">শিক্ষার্থীর শ্রেণী, শাখা, রোল এবং বিদ্যালয়ে নিবন্ধিত মোবাইল নম্বর দিন। ওই নম্বরে একটি কোড যাবে।</p>
                <form method="post" autocomplete="off" data-fee-portal-login>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="otp_request">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="fp-class">শ্রেণী</label>
                            <select class="form-select" id="fp-class" name="cls" required data-fp-class>
                                <option value="">নির্বাচন করুন</option>
                                <?php foreach ($portalClasses as $portalClass): ?>
                                <option value="<?= (int) $portalClass['id'] ?>"<?= (int) $portalClass['id'] === $portalLogin['cls'] ? ' selected' : '' ?>><?= h(trim((string) ($portalClass['class_name_ban'] ?? '')) ?: (string) ($portalClass['class_name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="fp-section">শাখা</label>
                            <select class="form-select" id="fp-section" name="sections" data-fp-section data-selected="<?= (int) $portalLogin['sections'] ?>">
                                <option value="0">শাখা নেই</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="fp-roll">রোল</label>
                            <input class="form-control" id="fp-roll" name="roll" inputmode="numeric" maxlength="6" required value="<?= h($portalLogin['roll']) ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="fp-mobile">নিবন্ধিত মোবাইল</label>
                            <input class="form-control" id="fp-mobile" name="mobile" inputmode="tel" maxlength="14" placeholder="01XXXXXXXXX" required value="<?= h($portalLogin['mobile']) ?>">
                        </div>
                    </div>
                    <button class="btn btn-success w-100 mt-4" type="submit">কোড পাঠান</button>
                </form>
                <script type="application/json" id="fp-sections"><?= json_encode($portalSectionMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
            </section>
            <?php endif; ?>
            <p class="small text-muted mt-3 mb-0">মোবাইল নম্বর বদলে থাকলে বিদ্যালয়ের অফিসে যোগাযোগ করে নম্বর হালনাগাদ করুন। আপনার PIN বা OTP কখনো বিদ্যালয় চাইবে না; bKash-এর তথ্য শুধু bKash-এর পাতায় দিন।</p>
        </div>
    </div>
    <?php else: ?>
    <?php
    $portalStudentDues = $portalDues($portalStudentId);
    $portalAdvance = $portalPayments->advanceBalance($portalStudentId);
    $portalReceipts = array_slice($portalPayments->forStudent($portalStudentId), 0, 20);
    $portalTxns = $portalOnline->forStudent($portalStudentId, 10);
    $portalTotalDue = array_sum(array_column($portalStudentDues, 'outstanding'));
    ?>
    <section class="fee-portal-card fee-portal-student">
        <div>
            <h2 class="h5 mb-1"><?= h(StudentDirectory::displayName($portalStudent)) ?></h2>
            <div class="text-muted small">
                <?= h((string) ($portalStudent['class_name_ban'] ?? '')) ?><?= ($portalStudent['section_name_ban'] ?? '') !== '' && $portalStudent['section_name_ban'] !== null ? ' — ' . h((string) $portalStudent['section_name_ban']) : '' ?>,
                রোল <?= h(bnDigits((string) $portalStudent['cls_roll'])) ?>, সেশন <?= h(bnDigits((string) $portalStudent['session_year'])) ?>
            </div>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-outline-secondary btn-sm" type="submit">বের হন</button>
        </form>
    </section>

    <?php if ($portalReview !== null): ?>
    <section class="fee-portal-card">
        <h2 class="h5">পরিশোধের আগে মিলিয়ে নিন</h2>
        <table class="table table-sm align-middle mb-3">
            <thead><tr><th>ইনভয়েস</th><th>বিবরণ</th><th class="text-end">বকেয়া</th></tr></thead>
            <tbody>
                <?php foreach ($portalReview['invoices'] as $due): ?>
                <tr><td><?= h($due['invoice_no']) ?></td><td><?= h($due['label']) ?></td><td class="text-end text-nowrap"><?= h(Money::format($due['outstanding'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th colspan="2">ফি</th><th class="text-end text-nowrap"><?= h(Money::format($portalReview['quote']['fee'])) ?></th></tr>
                <?php if ($portalReview['quote']['bearer'] === 'payer' && $portalReview['quote']['charge'] > 0): ?>
                <tr><th colspan="2">গেটওয়ে চার্জ (<?= h(bnDigits(Money::toInput($portalReview['rate']))) ?>%, অভিভাবক বহন করবেন)</th><th class="text-end text-nowrap"><?= h(Money::format($portalReview['quote']['charge'])) ?></th></tr>
                <?php endif; ?>
                <tr class="table-success"><th colspan="2">মোট পরিশোধ করবেন</th><th class="text-end text-nowrap fs-5"><?= h(Money::format($portalReview['quote']['payable'])) ?></th></tr>
            </tfoot>
        </table>
        <?php if ($portalReview['quote']['bearer'] === 'school'): ?>
        <p class="small text-muted">গেটওয়ে চার্জ বিদ্যালয় বহন করবে; আপনাকে শুধু ফি পরিশোধ করতে হবে।</p>
        <?php endif; ?>
        <form method="post" data-fee-portal-pay>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="pay">
            <input type="hidden" name="gateway" value="<?= h($portalReview['gateway']) ?>">
            <input type="hidden" name="idempotency_key" value="<?= h($portalReview['key']) ?>">
            <?php foreach ($portalReview['invoices'] as $due): ?>
            <input type="hidden" name="invoices[]" value="<?= (int) $due['invoice_id'] ?>">
            <?php endforeach; ?>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-bkash" type="submit"><?= h($portalOnline->gateway($portalReview['gateway'])->label()) ?>-এ পরিশোধ করুন</button>
                <a class="btn btn-outline-secondary" href="fee-payment.php">ফিরে যান</a>
            </div>
            <p class="small text-muted mt-2 mb-0">পরিশোধের পর এই পাতায় ফিরে না এলেও চিন্তা নেই — বিদ্যালয়ের সার্ভার নিজে থেকেই bKash থেকে যাচাই করে রসিদ তৈরি করবে।</p>
        </form>
    </section>
    <?php else: ?>
    <section class="fee-portal-card">
        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
            <h2 class="h5 mb-0">বকেয়া ইনভয়েস</h2>
            <span class="fw-semibold">মোট বকেয়া: <span class="text-danger text-nowrap"><?= h(Money::format($portalTotalDue)) ?></span></span>
        </div>
        <?php if ($portalAdvance > 0): ?>
        <div class="alert alert-info py-2 small">অগ্রিম জমা আছে <?= h(Money::format($portalAdvance)) ?> — পরবর্তী ইনভয়েসে অফিস থেকে সমন্বয় করা হবে।</div>
        <?php endif; ?>
        <?php if ($portalStudentDues === []): ?>
        <p class="text-success mb-0">কোনো বকেয়া নেই। ধন্যবাদ!</p>
        <?php else: ?>
        <form method="post" data-fee-portal-select>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="review">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th class="text-center" style="width:3rem">বাছাই</th><th>ইনভয়েস</th><th>বিবরণ</th><th>শেষ তারিখ</th><th class="text-end">বকেয়া</th></tr></thead>
                    <tbody>
                        <?php foreach ($portalStudentDues as $due): ?>
                        <tr>
                            <td class="text-center"><input class="form-check-input" type="checkbox" name="invoices[]" value="<?= (int) $due['invoice_id'] ?>" checked aria-label="<?= h($due['invoice_no']) ?>" data-fp-amount="<?= (int) $due['outstanding'] ?>"></td>
                            <td class="text-nowrap"><?= h($due['invoice_no']) ?></td>
                            <td><?= h($due['label']) ?></td>
                            <td class="text-nowrap<?= $due['due_date'] < date('Y-m-d') ? ' text-danger' : '' ?>"><?= h(bnDigits(date('d/m/Y', strtotime($due['due_date'])))) ?></td>
                            <td class="text-end text-nowrap"><?= h(Money::format($due['outstanding'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="4">নির্বাচিত</th><th class="text-end text-nowrap" data-fp-selected><?= h(Money::format($portalTotalDue)) ?></th></tr></tfoot>
                </table>
            </div>
            <?php if ($portalGateways === []): ?>
            <div class="alert alert-warning mb-0">এই মুহূর্তে অনলাইন পেমেন্ট চালু নেই। বিদ্যালয়ের অফিসে বা শ্রেণিশিক্ষকের কাছে ফি জমা দিন।</div>
            <?php else: ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php foreach ($portalGateways as $code): ?>
                <button class="btn btn-bkash" type="submit" name="gateway" value="<?= h($code) ?>"><?= h($portalOnline->gateway($code)->label()) ?>-এ পরিশোধ</button>
                <?php endforeach; ?>
                <span class="small text-muted">পরের ধাপে মোট টাকা দেখে নিশ্চিত করবেন।</span>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <section class="fee-portal-card h-100">
                <h2 class="h5">রসিদ</h2>
                <?php if ($portalReceipts === []): ?>
                <p class="text-muted mb-0">এখনো কোনো রসিদ নেই।</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($portalReceipts as $receipt): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-semibold"><?= h($receipt['receipt_no']) ?> <?php if ($receipt['status'] !== PaymentService::CONFIRMED): ?><span class="badge text-bg-secondary">রিভার্সড</span><?php endif; ?></div>
                            <div class="small text-muted"><?= h(accFormatDateTime((string) $receipt['collected_at'])) ?> · <?= h(PaymentService::METHOD_LABELS[$receipt['method']] ?? (string) $receipt['method']) ?></div>
                        </div>
                        <div class="text-end">
                            <div class="text-nowrap"><?= h(Money::format(Money::fromDb($receipt['amount']))) ?></div>
                            <a class="small" href="<?= h(signedRecordUrl('fee-receipt.php', (int) $receipt['id'], 'acc_portal_receipt')) ?>">রসিদ দেখুন</a>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="fee-portal-card h-100">
                <h2 class="h5">অনলাইন পেমেন্টের ইতিহাস</h2>
                <?php if ($portalTxns === []): ?>
                <p class="text-muted mb-0">এখনো কোনো অনলাইন পেমেন্ট নেই।</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($portalTxns as $txn): ?>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-semibold"><?= h($txn['merchant_invoice_no']) ?> <span class="badge <?= h(OnlinePaymentService::STATUS_BADGES[$txn['status']] ?? 'text-bg-secondary') ?>"><?= h(OnlinePaymentService::STATUS_LABELS[$txn['status']] ?? (string) $txn['status']) ?></span></div>
                            <div class="small text-muted"><?= h(accFormatDateTime((string) $txn['created_at'])) ?><?= $txn['trx_id'] ? ' · TrxID ' . h((string) $txn['trx_id']) : '' ?></div>
                        </div>
                        <div class="text-end">
                            <div class="text-nowrap"><?= h(Money::format(Money::fromDb($txn['payable_amount']))) ?></div>
                            <a class="small" href="<?= h(signedRecordUrl('fee-payment-result.php', (int) $txn['id'], 'acc_portal_txn')) ?>">বিস্তারিত</a>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include 'footer.php'; ?>
<script src="js/fee-portal.js?v=<?= (int) @filemtime(__DIR__ . '/js/fee-portal.js') ?>"></script>
</body>
</html>
