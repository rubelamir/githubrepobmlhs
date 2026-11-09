<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

/** Workflow states shared by category assignments, concessions and waivers. */
final class ApprovalStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const ENDED = 'ended';

    private const LABELS = [
        self::PENDING => 'অনুমোদনের অপেক্ষায়',
        self::APPROVED => 'অনুমোদিত',
        self::REJECTED => 'প্রত্যাখ্যাত',
        self::ENDED => 'সমাপ্ত',
    ];

    private const BADGES = [
        self::PENDING => 'text-bg-warning',
        self::APPROVED => 'text-bg-success',
        self::REJECTED => 'text-bg-danger',
        self::ENDED => 'text-bg-secondary',
    ];

    private function __construct()
    {
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'text-bg-secondary';
    }
}
