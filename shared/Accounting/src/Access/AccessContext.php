<?php

declare(strict_types=1);

namespace School\Accounting\Access;

/**
 * Immutable snapshot of who is acting and what they may do in the accounting module.
 */
final class AccessContext
{
    /** @var list<string> */
    private array $roles;

    /** @var array<string, true> */
    private array $capabilities;

    /**
     * @param list<string> $roles
     * @param array{entire: array<int, true>, sections: array<int, array<int, true>>} $classScope
     */
    public function __construct(
        private int $teacherId,
        private string $teacherName,
        array $roles,
        private array $classScope,
        private string $sessionYear
    ) {
        $known = [];
        foreach (Role::PRECEDENCE as $role) {
            if (in_array($role, $roles, true)) {
                $known[] = $role;
            }
        }
        $this->roles = $teacherId > 0 ? $known : [];
        $this->capabilities = array_fill_keys(Capability::forRoles($this->roles), true);
    }

    public static function guest(): self
    {
        return new self(0, '', [], ['entire' => [], 'sections' => []], '');
    }

    /** Actor for automatic work (gateway callbacks, cron); it has no capabilities. */
    public static function system(string $name): self
    {
        return new self(0, $name, [], ['entire' => [], 'sections' => []], '');
    }

    public function teacherId(): int
    {
        return $this->teacherId;
    }

    public function teacherName(): string
    {
        return $this->teacherName;
    }

    public function sessionYear(): string
    {
        return $this->sessionYear;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function primaryRole(): ?string
    {
        return $this->roles[0] ?? null;
    }

    public function hasAnyAccess(): bool
    {
        return $this->roles !== [];
    }

    public function can(string $capability): bool
    {
        return isset($this->capabilities[$capability]);
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return array_keys($this->capabilities);
    }

    /** Head and accounts officer work school-wide; class teachers are limited to their assignments. */
    public function hasSchoolWideScope(): bool
    {
        return $this->hasRole(Role::HEAD) || $this->hasRole(Role::OFFICER);
    }

    /**
     * $sectionId = 0 means a class that has no sections (or the whole class).
     */
    public function canForClass(string $capability, int $classId, int $sectionId): bool
    {
        if (!$this->can($capability) || $classId < 1) {
            return false;
        }
        if (!Capability::isClassScoped($capability) || $this->hasSchoolWideScope()) {
            return true;
        }

        return isset($this->classScope['entire'][$classId])
            || ($sectionId > 0 && isset($this->classScope['sections'][$classId][$sectionId]));
    }

    /** @return array{entire: array<int, true>, sections: array<int, array<int, true>>} */
    public function classScope(): array
    {
        return $this->classScope;
    }
}
