<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;

/**
 * Read-only view of students for billing. Only the fields billing needs are
 * selected (no guardian, address or NID data).
 */
final class StudentDirectory
{
    public const ADMITTED = 1;

    private const COLUMNS = 's.id, s.name_ban, s.name_eng, s.cls_roll, s.cls, s.sections, s.session_year, s.status,
                             c.class_name_ban, sec.section_name_ban';

    public function __construct(private PDO $db)
    {
    }

    /**
     * Admitted students of one class/section. $sectionId 0 = a class without sections.
     *
     * @return list<array<string, mixed>>
     */
    public function forClass(string $sessionYear, int $classId, int $sectionId): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
                FROM students_info s
                LEFT JOIN cls c ON c.id = s.cls
                LEFT JOIN sections sec ON sec.id = s.sections
                WHERE s.session_year = :session AND s.cls = :class AND s.status = :status';
        $params = ['session' => $sessionYear, 'class' => $classId, 'status' => self::ADMITTED];
        if ($sectionId > 0) {
            $sql .= ' AND s.sections = :section';
            $params['section'] = (string) $sectionId;
        }
        $sql .= ' ORDER BY CAST(s.cls_roll AS UNSIGNED), s.id';

        return \fetchRows($this->db, $sql, $params);
    }

    /**
     * Admitted students in the actor's classes whose name contains $query,
     * or whose roll is exactly $query when it is a number.
     *
     * @return list<array<string, mixed>>
     */
    public function search(AccessContext $actor, string $capability, string $sessionYear, string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query, 'UTF-8') > 40) {
            return [];
        }
        $rows = \fetchRows(
            $this->db,
            'SELECT ' . self::COLUMNS . '
             FROM students_info s
             LEFT JOIN cls c ON c.id = s.cls
             LEFT JOIN sections sec ON sec.id = s.sections
             WHERE s.session_year = :session AND s.status = :status
             ORDER BY s.cls, CAST(s.cls_roll AS UNSIGNED), s.id',
            ['session' => $sessionYear, 'status' => self::ADMITTED]
        );
        $matched = [];
        foreach ($rows as $row) {
            if (!self::matchesQuery($row, $query)) {
                continue;
            }
            if (!$actor->canForClass($capability, (int) $row['cls'], self::sectionIdOf($row))) {
                continue;
            }
            $matched[] = $row;
            if (count($matched) >= $limit) {
                break;
            }
        }

        return $matched;
    }

    /** Name contains the text, or a numeric query matches the roll exactly. */
    public static function matchesQuery(array $student, string $query): bool
    {
        $query = trim($query);
        if ($query === '') {
            return false;
        }
        $digits = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
        $ascii = strtr($query, $digits);
        $roll = strtr(trim((string) ($student['cls_roll'] ?? '')), $digits);
        if (preg_match('/^\d{1,6}$/', $ascii) === 1) {
            return $roll === $ascii;
        }
        if (mb_strlen($query, 'UTF-8') < 2) {
            return false;
        }
        $haystack = self::displayName($student) . ' ' . trim((string) ($student['name_eng'] ?? ''));

        return mb_stripos($haystack, $query) !== false;
    }

    /** @return array<string, mixed>|null */
    public function find(int $studentId): ?array
    {
        if ($studentId < 1) {
            return null;
        }
        $rows = \fetchRows(
            $this->db,
            'SELECT ' . self::COLUMNS . '
             FROM students_info s
             LEFT JOIN cls c ON c.id = s.cls
             LEFT JOIN sections sec ON sec.id = s.sections
             WHERE s.id = :id',
            ['id' => $studentId]
        );

        return $rows[0] ?? null;
    }

    /** students_info.sections is a varchar; non-numeric values mean "no section". */
    public static function sectionIdOf(array $student): int
    {
        $raw = trim((string) ($student['sections'] ?? ''));

        return ctype_digit($raw) ? (int) $raw : 0;
    }

    public static function displayName(array $student): string
    {
        $name = trim((string) ($student['name_ban'] ?? ''));

        return $name !== '' ? $name : trim((string) ($student['name_eng'] ?? ''));
    }

    /** @return array<string, mixed> */
    public function requireInScope(AccessContext $actor, string $capability, int $studentId): array
    {
        $student = $this->find($studentId);
        if ($student === null) {
            throw new RuntimeException('শিক্ষার্থী পাওয়া যায়নি।');
        }
        if (!$actor->canForClass($capability, (int) $student['cls'], self::sectionIdOf($student))) {
            throw AccessDeniedException::forCapability($capability);
        }

        return $student;
    }
}
