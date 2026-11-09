<?php

declare(strict_types=1);

use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessContextFactory;
use School\Accounting\Access\Capability;
use School\Accounting\Access\OfficerRepository;
use School\Accounting\Access\Role;
use School\Accounting\Audit\AuditTrail;

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

/**
 * Page guard for dist/pages/accounts/*: ensures the acc_* schema and the
 * capability, otherwise sends the user back with a message.
 */
function accBootPage(string $capability): AccessContext
{
    try {
        accEnsureSchema(getDatabaseConnection());
    } catch (PDOException $exception) {
        error_log('Accounting schema: ' . $exception->getMessage());
        denyPanelAccess('হিসাব মডিউলের ডেটাবেস প্রস্তুত করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।');
    }

    $access = accAccess();
    if (!$access->can($capability)) {
        $target = $access->can(Capability::DASHBOARD_VIEW) && $capability !== Capability::DASHBOARD_VIEW
            ? accPageUrl('dashboard.php')
            : adminUrl('index.php');
        redirectWithMessage($target, 'danger', 'এই পাতার অনুমতি আপনার নেই: ' . Capability::label($capability) . '।');
    }

    return $access;
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
 * @return list<array{page: string, icon: string, label: string}>
 */
function accNavItems(AccessContext $access): array
{
    $items = [
        ['page' => 'dashboard', 'icon' => 'bi-cash-coin', 'label' => 'হিসাব ড্যাশবোর্ড', 'capability' => Capability::DASHBOARD_VIEW],
        ['page' => 'officers', 'icon' => 'bi-person-gear', 'label' => 'অ্যাকাউন্টস অফিসার', 'capability' => Capability::OFFICERS_MANAGE],
        ['page' => 'audit', 'icon' => 'bi-journal-text', 'label' => 'হিসাব অডিট লগ', 'capability' => Capability::AUDIT_VIEW],
    ];
    $visible = [];
    foreach ($items as $item) {
        if ($access->can($item['capability'])) {
            unset($item['capability']);
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
