<?php

declare(strict_types=1);

namespace School\Accounting\Access;

use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Audit\AuditTrail;
use Throwable;

/**
 * Accounts officer appointments. Rows are never deleted: revoking keeps the
 * history and clears `active_teacher_id`, whose UNIQUE key prevents a teacher
 * from holding two active appointments.
 */
final class OfficerRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function isActiveOfficer(int $teacherId): bool
    {
        if ($teacherId < 1 || !\tableExists($this->db, 'acc_officers')) {
            return false;
        }
        $statement = $this->db->prepare('SELECT 1 FROM acc_officers WHERE active_teacher_id = :id LIMIT 1');
        $statement->execute(['id' => $teacherId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    public function activeOfficers(): array
    {
        return \fetchRows(
            $this->db,
            'SELECT o.*, t.teacher_name, t.teacher_mobile, t.teacher_designation, a.teacher_name AS appointed_by_name
             FROM acc_officers o
             LEFT JOIN teacher_info t ON t.id = o.teacher_id
             LEFT JOIN teacher_info a ON a.id = o.appointed_by
             WHERE o.active_teacher_id IS NOT NULL
             ORDER BY o.appointed_at ASC, o.id ASC'
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return \fetchRows(
            $this->db,
            'SELECT o.*, t.teacher_name, t.teacher_mobile,
                    a.teacher_name AS appointed_by_name, r.teacher_name AS revoked_by_name
             FROM acc_officers o
             LEFT JOIN teacher_info t ON t.id = o.teacher_id
             LEFT JOIN teacher_info a ON a.id = o.appointed_by
             LEFT JOIN teacher_info r ON r.id = o.revoked_by
             ORDER BY o.id DESC
             LIMIT ' . $limit
        );
    }

    /**
     * Active teachers who can be appointed (not head, not already an officer).
     *
     * @return list<array<string, mixed>>
     */
    public function appointableTeachers(): array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT t.*
             FROM teacher_info t
             LEFT JOIN acc_officers o ON o.active_teacher_id = t.id
             WHERE o.id IS NULL AND (t.teacher_status IS NULL OR t.teacher_status = 1)
             ORDER BY ' . \teacherListOrderSql($this->db)
        );

        return array_values(array_filter($rows, static fn (array $row): bool => !\isHeadAdmin($row)));
    }

    public function appoint(AccessContext $actor, AuditTrail $audit, int $teacherId, string $note): int
    {
        $this->assertCanManage($actor);
        $note = self::cleanText($note, 255);
        $teacher = $this->findTeacher($teacherId);
        if ($teacher === null) {
            throw new RuntimeException('শিক্ষক পাওয়া যায়নি।');
        }
        if ((int) ($teacher['teacher_status'] ?? 1) !== 1) {
            throw new RuntimeException('নিষ্ক্রিয় শিক্ষককে অ্যাকাউন্টস অফিসার করা যাবে না।');
        }
        if (\isHeadAdmin($teacher)) {
            throw new RuntimeException('প্রধান শিক্ষকের সব অনুমতি আগে থেকেই আছে; আলাদা নিয়োগের প্রয়োজন নেই।');
        }

        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO acc_officers (teacher_id, active_teacher_id, appointed_by, appointed_at, appointment_note)
                 VALUES (:teacher_id, :active_teacher_id, :appointed_by, :appointed_at, :note)'
            )->execute([
                'teacher_id' => $teacherId,
                'active_teacher_id' => $teacherId,
                'appointed_by' => $actor->teacherId(),
                'appointed_at' => $now,
                'note' => $note,
            ]);
            $id = (int) $this->db->lastInsertId();
            $audit->record(
                'officer.appoint',
                'acc_officer',
                $id,
                ['teacher_id' => $teacherId, 'is_officer' => false],
                ['teacher_id' => $teacherId, 'is_officer' => true, 'appointed_at' => $now, 'note' => $note],
                'অ্যাকাউন্টস অফিসার নিয়োগ: ' . (string) $teacher['teacher_name']
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('এই শিক্ষক ইতিমধ্যে অ্যাকাউন্টস অফিসার হিসেবে নিযুক্ত।');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $id;
    }

    public function revoke(AccessContext $actor, AuditTrail $audit, int $officerId, string $reason): void
    {
        $this->assertCanManage($actor);
        $reason = self::cleanText($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 3) {
            throw new RuntimeException('প্রত্যাহারের কারণ লিখুন (কমপক্ষে ৩ অক্ষর)।');
        }

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT o.*, t.teacher_name
                 FROM acc_officers o
                 LEFT JOIN teacher_info t ON t.id = o.teacher_id
                 WHERE o.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $officerId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['active_teacher_id'] === null) {
                throw new RuntimeException('সক্রিয় নিয়োগটি পাওয়া যায়নি (হয়তো আগেই প্রত্যাহার করা হয়েছে)।');
            }
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            $this->db->prepare(
                'UPDATE acc_officers
                 SET active_teacher_id = NULL, revoked_by = :revoked_by, revoked_at = :revoked_at, revoke_reason = :reason
                 WHERE id = :id AND active_teacher_id IS NOT NULL'
            )->execute([
                'revoked_by' => $actor->teacherId(),
                'revoked_at' => $now,
                'reason' => $reason,
                'id' => $officerId,
            ]);
            $audit->record(
                'officer.revoke',
                'acc_officer',
                $officerId,
                ['teacher_id' => (int) $row['teacher_id'], 'is_officer' => true, 'appointed_at' => $row['appointed_at']],
                ['teacher_id' => (int) $row['teacher_id'], 'is_officer' => false, 'revoked_at' => $now, 'reason' => $reason],
                'অ্যাকাউন্টস অফিসার প্রত্যাহার: ' . (string) ($row['teacher_name'] ?? '')
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function assertCanManage(AccessContext $actor): void
    {
        if (!$actor->can(Capability::OFFICERS_MANAGE)) {
            throw AccessDeniedException::forCapability(Capability::OFFICERS_MANAGE);
        }
    }

    /** @return array<string, mixed>|null */
    private function findTeacher(int $teacherId): ?array
    {
        if ($teacherId < 1) {
            return null;
        }
        $statement = $this->db->prepare('SELECT * FROM teacher_info WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $teacherId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function cleanText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
}
