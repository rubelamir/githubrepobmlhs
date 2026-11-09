<?php
declare(strict_types=1);

use School\Accounting\Access\Capability;

require_once __DIR__ . '/../../includes/auth.php';
requireAuthentication('../../examples/login-v2.php');
require_once dirname(__DIR__, 3) . '/shared/Accounting/bootstrap.php';

$access = accBootPage(Capability::DASHBOARD_VIEW);
$store = accAttachments();
$attachmentId = postedRecordId('acc_attachment');
$attachment = $attachmentId > 0 ? $store->attachment($attachmentId) : null;
$allowed = $attachment !== null && match ((string) $attachment['owner_type']) {
    'voucher' => $access->can(Capability::VOUCHER_CREATE) || $access->can(Capability::REPORTS_VIEW_ALL),
    'deposit' => $access->can(Capability::BANK_RECONCILE) || $access->can(Capability::VOUCHER_APPROVE),
    default => false,
};
$path = $allowed ? $store->path($attachment) : null;
if ($path === null || !is_readable($path)) {
    http_response_code(404);
    exit;
}

$mime = (string) $attachment['mime'];
$extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'bin';
$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string) $attachment['original_name'], PATHINFO_FILENAME)) ?: 'attachment';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . $name . '.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
}
readfile($path);
