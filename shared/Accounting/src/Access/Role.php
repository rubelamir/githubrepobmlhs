<?php

declare(strict_types=1);

namespace School\Accounting\Access;

/**
 * Accounting roles. They are derived from the panel role and appointments;
 * a teacher may hold more than one (e.g. accounts officer and class teacher).
 */
final class Role
{
    public const HEAD = 'head';
    public const OFFICER = 'accounts_officer';
    public const CLASS_TEACHER = 'class_teacher';

    /** Highest privilege first; used to pick the primary role for display. */
    public const PRECEDENCE = [self::HEAD, self::OFFICER, self::CLASS_TEACHER];

    private const LABELS = [
        self::HEAD => 'প্রধান শিক্ষক (সুপার অ্যাডমিন)',
        self::OFFICER => 'হিসাব রক্ষক',
        self::CLASS_TEACHER => 'শ্রেণী শিক্ষক',
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $role): bool
    {
        return isset(self::LABELS[$role]);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? $role;
    }
}
