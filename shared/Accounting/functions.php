<?php

declare(strict_types=1);

use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessContextFactory;
use School\Accounting\Access\Capability;
use School\Accounting\Access\OfficerRepository;
use School\Accounting\Access\Role;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\ConcessionService;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Billing\FeeStructureRepository;
use School\Accounting\Billing\InvoiceService;
use School\Accounting\Billing\StudentCategoryService;
use School\Accounting\Billing\StudentDirectory;
use School\Accounting\Billing\WaiverService;
use School\Accounting\Collection\HandoverService;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Gateway\BkashGateway;
use School\Accounting\Gateway\GatewayConfig;
use School\Accounting\Gateway\NagadGateway;
use School\Accounting\Gateway\OnlinePaymentService;
use School\Accounting\Gateway\RefundService;
use School\Accounting\Gateway\SettlementService;
use School\Accounting\Ledger\AttachmentStore;
use School\Accounting\Ledger\BankService;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\PeriodClosing;
use School\Accounting\Ledger\PostingService;
use School\Accounting\Ledger\VoucherService;
use School\Accounting\Report\ReportService;

/**
 * Thin procedural helpers so admin pages and the sidebar can use the
 * accounting services without wiring objects by hand.
 */

function accOfficers(): OfficerRepository
{
    static $repository = null;

    return $repository ??= new OfficerRepository(getDatabaseConnection());
}

/** Accounting permissions of the logged-in teacher (cached per request). */
function accAccess(): AccessContext
{
    static $context = null;
    if ($context === null) {
        $factory = new AccessContextFactory(getDatabaseConnection(), accOfficers());
        $context = $factory->forTeacher(getAuthenticatedTeacher());
    }

    return $context;
}

function accAudit(): AuditTrail
{
    static $audit = null;

    return $audit ??= new AuditTrail(getDatabaseConnection(), accAccess());
}

function accCatalog(): FeeCatalog
{
    static $service = null;

    return $service ??= new FeeCatalog(getDatabaseConnection());
}

function accStructures(): FeeStructureRepository
{
    static $service = null;

    return $service ??= new FeeStructureRepository(getDatabaseConnection(), accCatalog());
}

function accStudents(): StudentDirectory
{
    static $service = null;

    return $service ??= new StudentDirectory(getDatabaseConnection());
}

function accStudentCategories(): StudentCategoryService
{
    static $service = null;

    return $service ??= new StudentCategoryService(getDatabaseConnection(), accCatalog(), accStudents());
}

function accConcessions(): ConcessionService
{
    static $service = null;

    return $service ??= new ConcessionService(getDatabaseConnection(), accCatalog(), accStudents());
}

function accInvoices(): InvoiceService
{
    static $service = null;

    return $service ??= new InvoiceService(
        getDatabaseConnection(),
        accCatalog(),
        accStructures(),
        accStudents(),
        accStudentCategories(),
        accConcessions()
    );
}

function accWaivers(): WaiverService
{
    static $service = null;

    return $service ??= new WaiverService(getDatabaseConnection(), accInvoices(), accStudents());
}

function accPayments(): PaymentService
{
    static $service = null;

    return $service ??= new PaymentService(getDatabaseConnection(), accInvoices(), accStudents(), accPosting());
}

function accHandovers(): HandoverService
{
    static $service = null;

    return $service ??= new HandoverService(getDatabaseConnection(), accPosting());
}

function accChart(): ChartOfAccounts
{
    static $service = null;

    return $service ??= new ChartOfAccounts(getDatabaseConnection());
}

function accPeriods(): PeriodClosing
{
    static $service = null;

    return $service ??= new PeriodClosing(getDatabaseConnection(), accChart());
}

function accVouchers(): VoucherService
{
    static $service = null;

    return $service ??= new VoucherService(getDatabaseConnection(), accChart(), accPeriods());
}

function accPosting(): PostingService
{
    static $service = null;

    return $service ??= new PostingService(getDatabaseConnection(), accChart(), accVouchers());
}

function accReports(): ReportService
{
    static $service = null;

    return $service ??= new ReportService(getDatabaseConnection(), accChart());
}

function accBank(): BankService
{
    static $service = null;

    return $service ??= new BankService(getDatabaseConnection(), accChart(), accVouchers(), accPeriods());
}

function accAttachments(): AttachmentStore
{
    static $service = null;

    return $service ??= new AttachmentStore(getDatabaseConnection());
}

function accOnline(): OnlinePaymentService
{
    static $service = null;
    if ($service === null) {
        $db = getDatabaseConnection();
        $system = AccessContext::system('অনলাইন পেমেন্ট (সিস্টেম)');
        $service = new OnlinePaymentService($db, accPayments(), accGateways($db), $system, new AuditTrail($db, $system));
    }

    return $service;
}

function accRefunds(): RefundService
{
    static $service = null;

    return $service ??= new RefundService(getDatabaseConnection(), accOnline(), accPayments(), accChart());
}

function accSettlements(): SettlementService
{
    static $service = null;

    return $service ??= new SettlementService(getDatabaseConnection(), accChart(), accVouchers(), accPeriods());
}

/** @return list<\School\Accounting\Gateway\PaymentGateway> */
function accGateways(PDO $db): array
{
    return [new BkashGateway($db, GatewayConfig::for('bkash')), new NagadGateway()];
}

/**
 * Online payment services for code that runs without a staff login (guardian
 * portal, gateway callback, cron). $actorName appears in the audit log.
 *
 * @return array{online: OnlinePaymentService, refunds: RefundService, payments: PaymentService, invoices: InvoiceService, audit: AuditTrail}
 */
function accStandaloneServices(PDO $db, string $actorName): array
{
    accEnsureSchema($db);
    $system = AccessContext::system($actorName);
    $audit = new AuditTrail($db, $system);
    $chart = new ChartOfAccounts($db);
    $periods = new PeriodClosing($db, $chart);
    $vouchers = new VoucherService($db, $chart, $periods);
    $posting = new PostingService($db, $chart, $vouchers);
    $catalog = new FeeCatalog($db);
    $students = new StudentDirectory($db);
    $categories = new StudentCategoryService($db, $catalog, $students);
    $invoices = new InvoiceService($db, $catalog, new FeeStructureRepository($db, $catalog), $students, $categories, new ConcessionService($db, $catalog, $students));
    $payments = new PaymentService($db, $invoices, $students, $posting);
    $online = new OnlinePaymentService($db, $payments, accGateways($db), $system, $audit);

    return [
        'online' => $online,
        'refunds' => new RefundService($db, $online, $payments, $chart),
        'payments' => $payments,
        'invoices' => $invoices,
        'audit' => $audit,
    ];
}

/** Secret for the gateway reconciliation cron URL (also runnable from the CLI). */
function accGatewayCronKey(): string
{
    $configured = deploymentPrivateConfig()['gateway_cron_key'] ?? '';
    if (is_string($configured) && strlen(trim($configured)) >= 16) {
        return trim($configured);
    }

    return hash_hmac('sha256', 'acc-gateway-cron', appSigningKey());
}

function accToday(): DateTimeImmutable
{
    return new DateTimeImmutable(AuditTrail::now()->format('Y-m-d'));
}

/** @return list<string> */
function accSessionYears(): array
{
    $years = [];
    for ($year = (int) AuditTrail::now()->format('Y') + 1; $year >= 2015; $year--) {
        $years[] = (string) $year;
    }

    return $years;
}

/**
 * Classes the user may work with: all active classes for head/officer,
 * only assigned classes for a class teacher.
 *
 * @return list<array<string, mixed>>
 */
function accClassesFor(AccessContext $access): array
{
    $classes = schoolClasses(getDatabaseConnection(), true);
    if ($access->hasSchoolWideScope()) {
        return $classes;
    }
    $scope = $access->classScope();

    return array_values(array_filter(
        $classes,
        static fn (array $class): bool => isset($scope['entire'][(int) $class['id']]) || isset($scope['sections'][(int) $class['id']])
    ));
}

/** @return list<array<string, mixed>> */
function accSectionsFor(AccessContext $access, int $classId): array
{
    if ($classId < 1) {
        return [];
    }
    $sections = schoolSections(getDatabaseConnection(), $classId, true);
    $scope = $access->classScope();
    if ($access->hasSchoolWideScope() || isset($scope['entire'][$classId])) {
        return $sections;
    }

    return array_values(array_filter(
        $sections,
        static fn (array $section): bool => isset($scope['sections'][$classId][(int) $section['id']])
    ));
}

/**
 * Validated class/section selection for a filter form (strict: a class with
 * sections needs one; a class without sections uses section 0).
 *
 * @return array{class_id: int, section_id: int, sections: list<array<string, mixed>>, notice: string, classes: list<array<string, mixed>>}
 */
function accClassSelection(AccessContext $access, int $classId, int $sectionId): array
{
    $classes = accClassesFor($access);
    $classIds = array_map(static fn (array $class): int => (int) $class['id'], $classes);
    if (!in_array($classId, $classIds, true)) {
        $classId = 0;
    }
    $sections = accSectionsFor($access, $classId);
    $sectionId = studentFilterSectionId($sections, $classId, $sectionId, true);
    $notice = studentFilterSelectionNotice($sections, $classId, $sectionId);
    $allSections = $classId > 0 ? schoolSections(getDatabaseConnection(), $classId, true) : [];
    if ($notice === '' && $sectionId === 0 && $allSections !== []) {
        $notice = 'এই শ্রেণীতে আপনার জন্য কোনো শাখা নির্ধারিত নেই।';
    }

    return ['class_id' => $classId, 'section_id' => $sectionId, 'sections' => $sections, 'notice' => $notice, 'classes' => $classes];
}

/**
 * Session, class and section selects for a GET filter form marked data-student-filter.
 * The section list is reloaded from accounts/academic-api.php when the class changes.
 */
function accClassFilterFields(array $selection, string $sessionYear, string $columnClass = 'col-sm-4'): string
{
    $html = '<div class="' . h($columnClass) . '"><label class="form-label" for="acc-f-session">সেশন</label>'
        . '<select class="form-select" name="session_year" id="acc-f-session">';
    foreach (accSessionYears() as $year) {
        $html .= '<option value="' . h($year) . '"' . ($year === $sessionYear ? ' selected' : '') . '>' . h(bnDigits($year)) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="' . h($columnClass) . '"><label class="form-label" for="acc-f-class">শ্রেণী</label>'
        . '<select class="form-select" name="cls" id="acc-f-class" required><option value="">শ্রেণী নির্বাচন করুন</option>';
    foreach ($selection['classes'] as $class) {
        $id = (int) $class['id'];
        $html .= '<option value="' . $id . '"' . ($id === $selection['class_id'] ? ' selected' : '') . '>' . h(accClassLabel($class)) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="' . h($columnClass) . '"><label class="form-label" for="acc-f-section">শাখা</label>'
        . '<select class="form-select" name="sections" id="acc-f-section" required data-sf-sections>'
        . studentFilterSectionOptionsHtml($selection['sections'], $selection['class_id'], $selection['section_id'] > 0 ? (string) $selection['section_id'] : '')
        . '</select></div>';

    return $html;
}

function accClassLabel(array $class): string
{
    return trim((string) ($class['class_name_ban'] ?? '')) ?: (string) ($class['class_name'] ?? '');
}

function accSectionLabel(array $section): string
{
    return trim((string) ($section['section_name_ban'] ?? '')) ?: (string) ($section['section_name'] ?? '');
}

/** Pending approvals for the head (sidebar badge, dashboard). */
function accPendingApprovalCount(): int
{
    $db = getDatabaseConnection();
    if (!tableExists($db, 'acc_waivers')) {
        return 0;
    }
    $total = 0;
    foreach (['acc_student_fee_categories', 'acc_concessions', 'acc_waivers'] as $table) {
        $total += (int) $db->query("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'")->fetchColumn();
    }

    return $total;
}

/**
 * Page guard for dist/pages/accounts/*: ensures the acc_* schema and the
 * capability, otherwise sends the user back with a message.
 */
function accBootPage(string|array $capability): AccessContext
{
    try {
        accEnsureSchema(getDatabaseConnection());
    } catch (PDOException $exception) {
        error_log('Accounting schema: ' . $exception->getMessage());
        denyPanelAccess('হিসাব মডিউলের ডেটাবেস প্রস্তুত করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।');
    }

    $access = accAccess();
    $capabilities = array_values((array) $capability);
    $allowed = false;
    foreach ($capabilities as $one) {
        $allowed = $allowed || $access->can($one);
    }
    if (!$allowed) {
        $target = $access->can(Capability::DASHBOARD_VIEW) && !in_array(Capability::DASHBOARD_VIEW, $capabilities, true)
            ? accPageUrl('dashboard.php')
            : adminUrl('index.php');
        $label = implode(' বা ', array_map(static fn (string $one): string => Capability::label($one), $capabilities));
        redirectWithMessage($target, 'danger', 'এই পাতার অনুমতি আপনার নেই: ' . $label . '।');
    }
    accSyncPostings();

    return $access;
}

/** Posts collections and handovers that have no voucher yet (e.g. recorded before the ledger existed). */
function accSyncPostings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDatabaseConnection();
    $pending = $db->query(
        "SELECT EXISTS (SELECT 1 FROM acc_payment_allocations WHERE voucher_id IS NULL)
             OR EXISTS (SELECT 1 FROM acc_handovers WHERE status = 'received' AND voucher_id IS NULL)"
    )->fetchColumn();
    if ((int) $pending === 1) {
        accPosting()->syncAll();
    }
}

/** Cache-busted URL of a dist/ asset for pages that set $basePath. */
function accAssetUrl(string $basePath, string $file): string
{
    $path = dirname(__DIR__, 2) . '/dist/' . ltrim($file, '/');

    return $basePath . ltrim($file, '/') . '?v=' . (int) @filemtime($path);
}

function accPageUrl(string $page): string
{
    return adminUrl('pages/accounts/' . ltrim($page, '/'));
}

/** Basename of the current accounting page, or '' outside dist/pages/accounts/. */
function accCurrentPage(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_contains($script, '/pages/accounts/')) {
        return '';
    }

    return pathinfo($script, PATHINFO_FILENAME);
}

/**
 * Sidebar entries the current user may open.
 *
 * @return list<array{page: string, icon: string, label: string, badge: int}>
 */
function accNavItems(AccessContext $access): array
{
    $items = [
        ['group' => 'প্রতিদিন', 'page' => 'dashboard', 'icon' => 'bi-cash-coin', 'label' => 'আজকের কাজ', 'capability' => Capability::DASHBOARD_VIEW],
        ['group' => 'প্রতিদিন', 'page' => 'collect', 'icon' => 'bi-cash-stack', 'label' => 'ফি আদায়', 'capability' => Capability::FEES_COLLECT],
        ['group' => 'প্রতিদিন', 'page' => 'dues', 'icon' => 'bi-exclamation-diamond', 'label' => 'বকেয়া তালিকা', 'capability' => Capability::FEES_VIEW_CLASS],
        ['group' => 'প্রতিদিন', 'page' => 'payments', 'icon' => 'bi-receipt-cutoff', 'label' => 'রসিদ', 'capability' => [Capability::FEES_COLLECT, Capability::FEES_VIEW_ALL]],
        ['group' => 'প্রতিদিন', 'page' => 'handovers', 'icon' => 'bi-arrow-left-right', 'label' => 'টাকা জমা', 'capability' => [Capability::HANDOVER_SUBMIT, Capability::HANDOVER_RECEIVE, Capability::REPORTS_VIEW_ALL]],
        ['group' => 'বিল ও হার', 'page' => 'invoices', 'icon' => 'bi-receipt', 'label' => 'মাসের বিল', 'capability' => [Capability::INVOICE_MANAGE, Capability::FEES_VIEW_ALL]],
        ['group' => 'বিল ও হার', 'page' => 'fee-structure', 'icon' => 'bi-grid-3x3-gap', 'label' => 'শ্রেণীর হার', 'capability' => Capability::FEES_VIEW_ALL],
        ['group' => 'বিল ও হার', 'page' => 'fee-setup', 'icon' => 'bi-sliders', 'label' => 'ফির নাম', 'capability' => [Capability::FEE_STRUCTURE_MANAGE, Capability::FEES_VIEW_ALL]],
        ['group' => 'বিল ও হার', 'page' => 'student-categories', 'icon' => 'bi-mortarboard', 'label' => 'বৃত্তি/উপবৃত্তি', 'capability' => [Capability::CONCESSION_REQUEST, Capability::FEES_VIEW_ALL]],
        ['group' => 'বিল ও হার', 'page' => 'approvals', 'icon' => 'bi-check2-square', 'label' => 'ছাড়ের অনুমোদন', 'capability' => Capability::CONCESSION_APPROVE],
        ['group' => 'অনলাইন', 'page' => 'online-payments', 'icon' => 'bi-phone', 'label' => 'অনলাইন পেমেন্ট', 'capability' => [Capability::GATEWAY_SETTINGS, Capability::REFUND_REQUEST, Capability::REFUND_APPROVE]],
        ['group' => 'অনলাইন', 'page' => 'refunds', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'টাকা ফেরত', 'capability' => [Capability::REFUND_REQUEST, Capability::REFUND_APPROVE]],
        ['group' => 'অনলাইন', 'page' => 'settlements', 'icon' => 'bi-arrow-down-up', 'label' => 'bKash থেকে ব্যাংক', 'capability' => [Capability::BANK_RECONCILE, Capability::VOUCHER_APPROVE]],
        ['group' => 'রিপোর্ট', 'page' => 'reports', 'icon' => 'bi-bar-chart', 'label' => 'রিপোর্ট', 'capability' => [Capability::REPORTS_VIEW_ALL, Capability::REPORTS_VIEW_CLASS]],
        ['group' => 'হিসাবের খাতা', 'page' => 'vouchers', 'icon' => 'bi-journal-check', 'label' => 'ভাউচার', 'capability' => [Capability::VOUCHER_CREATE, Capability::VOUCHER_APPROVE]],
        ['group' => 'হিসাবের খাতা', 'page' => 'bank', 'icon' => 'bi-bank', 'label' => 'ব্যাংক জমা', 'capability' => [Capability::BANK_RECONCILE, Capability::VOUCHER_APPROVE]],
        ['group' => 'হিসাবের খাতা', 'page' => 'ledger', 'icon' => 'bi-journal-bookmark', 'label' => 'খতিয়ান', 'capability' => Capability::REPORTS_VIEW_ALL],
        ['group' => 'হিসাবের খাতা', 'page' => 'chart-of-accounts', 'icon' => 'bi-diagram-3', 'label' => 'হিসাবের নাম', 'capability' => Capability::REPORTS_VIEW_ALL],
        ['group' => 'হিসাবের খাতা', 'page' => 'period-closing', 'icon' => 'bi-calendar2-check', 'label' => 'মাস বন্ধ', 'capability' => [Capability::PERIOD_CLOSE, Capability::REPORTS_VIEW_ALL]],
        ['group' => 'হিসাবের খাতা', 'page' => 'officers', 'icon' => 'bi-person-gear', 'label' => 'হিসাব রক্ষক', 'capability' => Capability::OFFICERS_MANAGE],
        ['group' => 'হিসাবের খাতা', 'page' => 'audit', 'icon' => 'bi-journal-text', 'label' => 'পরিবর্তনের ইতিহাস', 'capability' => Capability::AUDIT_VIEW],
    ];
    $visible = [];
    foreach ($items as $item) {
        $allowed = false;
        foreach ((array) $item['capability'] as $capability) {
            $allowed = $allowed || $access->can($capability);
        }
        if ($allowed) {
            unset($item['capability']);
            $item['badge'] = match ($item['page']) {
                'approvals' => accPendingApprovalCount(),
                'handovers' => $access->can(Capability::HANDOVER_RECEIVE) && tableExists(getDatabaseConnection(), 'acc_handovers') ? accHandovers()->submittedCount() : 0,
                'vouchers' => $access->can(Capability::VOUCHER_APPROVE) && tableExists(getDatabaseConnection(), 'acc_vouchers') ? accVouchers()->submittedCount() : 0,
                'bank' => $access->can(Capability::VOUCHER_APPROVE) && tableExists(getDatabaseConnection(), 'acc_bank_deposits') ? accBank()->submittedCount() : 0,
                'online-payments' => tableExists(getDatabaseConnection(), 'acc_gateway_transactions') ? accOnline()->attentionCount() : 0,
                'refunds' => $access->can(Capability::REFUND_APPROVE) && tableExists(getDatabaseConnection(), 'acc_refunds') ? accRefunds()->waitingCount() : 0,
                'settlements' => $access->can(Capability::VOUCHER_APPROVE) && tableExists(getDatabaseConnection(), 'acc_settlements') ? accSettlements()->submittedCount() : 0,
                default => 0,
            };
            $visible[] = $item;
        }
    }

    return $visible;
}

function accRoleBadgeClass(string $role): string
{
    return match ($role) {
        Role::HEAD => 'text-bg-danger',
        Role::OFFICER => 'text-bg-primary',
        Role::CLASS_TEACHER => 'text-bg-success',
        default => 'text-bg-secondary',
    };
}

/** Plain text (escape when printing): "2026-11-08 14:05:00" → "০৮/১১/২০২৬, ০২:০৫ অপরাহ্ণ" */
function accFormatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }

    return bnDigits(date('d/m/Y, h:i', $time)) . ' ' . (date('A', $time) === 'AM' ? 'পূর্বাহ্ণ' : 'অপরাহ্ণ');
}
