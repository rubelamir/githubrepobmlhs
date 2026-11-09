<?php
declare(strict_types=1);

require_once __DIR__ . '/site.php';
require_once dirname(__DIR__, 2) . '/shared/Accounting/bootstrap.php';

use School\Accounting\Gateway\GuardianPortal;

startAppSession();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/** @return array{online: \School\Accounting\Gateway\OnlinePaymentService, refunds: \School\Accounting\Gateway\RefundService, payments: \School\Accounting\Collection\PaymentService, invoices: \School\Accounting\Billing\InvoiceService, audit: \School\Accounting\Audit\AuditTrail} */
function feePortalServices(): array
{
    static $services = null;

    return $services ??= accStandaloneServices(publicDatabase(), 'অভিভাবক পোর্টাল');
}

function feePortal(): GuardianPortal
{
    $db = publicDatabase();

    return new GuardianPortal($db, static fn (string $mobile, string $text): bool => sendSchoolSms($db, $mobile, $text, 'acc_portal_otp')['ok']);
}

function feePortalRedirect(string $target, string $type = '', string $message = ''): never
{
    if ($message !== '') {
        $_SESSION['acc_portal_flash'] = ['type' => $type, 'message' => $message];
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $target, true, 303);
    exit;
}

/** @return array{type: string, message: string}|null */
function feePortalFlash(): ?array
{
    $flash = $_SESSION['acc_portal_flash'] ?? null;
    unset($_SESSION['acc_portal_flash']);

    return is_array($flash) ? ['type' => (string) ($flash['type'] ?? 'info'), 'message' => (string) ($flash['message'] ?? '')] : null;
}

/**
 * Absolute URL for gateway callbacks. Set payment_callback_base in the private
 * config (e.g. "https://bmlhs.site/bmlhs") so it never depends on the Host header.
 */
function feePortalAbsoluteUrl(string $page): string
{
    $base = deploymentPrivateConfig()['payment_callback_base'] ?? '';
    if (is_string($base) && preg_match('#^https://#i', $base) === 1) {
        return rtrim($base, '/') . '/' . ltrim($page, '/');
    }

    return publicAbsoluteUrl($page);
}

function feePortalAlertClass(string $type): string
{
    return match ($type) {
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
        default => 'alert-danger',
    };
}
