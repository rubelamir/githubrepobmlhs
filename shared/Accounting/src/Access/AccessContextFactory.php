<?php

declare(strict_types=1);

namespace School\Accounting\Access;

use PDO;
use School\Accounting\Audit\AuditTrail;

/**
 * Builds the AccessContext from the logged-in panel teacher using the
 * existing panel role (head) and class-teacher assignments.
 */
final class AccessContextFactory
{
    public function __construct(private PDO $db, private OfficerRepository $officers)
    {
    }

    /** @param array<string, mixed>|null $teacher */
    public function forTeacher(?array $teacher, ?string $sessionYear = null): AccessContext
    {
        $sessionYear ??= AuditTrail::now()->format('Y');
        $teacherId = (int) ($teacher['id'] ?? 0);
        if ($teacher === null || $teacherId < 1) {
            return AccessContext::guest();
        }

        $roles = [];
        if (\isHeadAdmin($teacher)) {
            $roles[] = Role::HEAD;
        }
        if ($this->officers->isActiveOfficer($teacherId)) {
            $roles[] = Role::OFFICER;
        }
        $scope = \classTeacherScope($this->db, $teacher, $sessionYear);
        if ($scope['entire'] !== [] || $scope['sections'] !== []) {
            $roles[] = Role::CLASS_TEACHER;
        }

        return new AccessContext(
            $teacherId,
            trim((string) ($teacher['teacher_name'] ?? '')),
            $roles,
            $scope,
            $sessionYear
        );
    }
}
