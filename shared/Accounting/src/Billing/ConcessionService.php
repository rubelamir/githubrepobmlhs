<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Support\Money;

/**
 * Individual concessions (sibling discount, poor fund …) on one fee head,
 * applied on top of the student's fee-category rate. One active per head.
 */
final class ConcessionService
{
    public const PERCENT = 'percent';
    public const FIXED = 'fixed';

    public function __construct(private PDO $db, private FeeCatalog $catalog, private StudentDirectory $students)
    {
    }

    public function request(
        AccessContext $actor,
        AuditTrail $audit,
        int $studentId,
        int $headId,
        string $kind,
        string $value,
        string $reason,
        string $effectiveFrom
    ): int {
        if (!$actor->can(Capability::CONCESSION_REQUEST)) {
            throw AccessDeniedException::forCapability(Capability::CONCESSION_REQUEST);
        }
        $student = $this->students->requireInScope($actor, Capability::CONCESSION_REQUEST, $studentId);
        $head = $this->catalog->head($headId);
        if ($head === null || (int) $head['is_active'] !== 1) {
            throw new RuntimeException('সক্রিয় ফি হেড নির্বাচন করুন।');
        }
        if ($kind !== self::PERCENT && $kind !== self::FIXED) {
            throw new RuntimeException('ছাড়ের ধরন নির্বাচন করুন।');
        }
        $stored = $kind === self::PERCENT ? Money::toDb(Money::parsePercent($value)) : Money::toDb(Money::parse($value, 'ছাড়ের পরিমাণ'));
        if (Money::fromDb($stored) === 0) {
            throw new RuntimeException('ছাড়ের পরিমাণ শূন্যের বেশি হতে হবে।');
        }
        $reason = FeeCatalog::text($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 3) {
            throw new RuntimeException('ছাড়ের কারণ লিখুন।');
        }
        $session = (string) $student['session_year'];
        $effective = FeeStructureRepository::parseDate($effectiveFrom, 'কার্যকর তারিখ');
        if ($effective->format('Y') !== $session) {
            throw new RuntimeException('কার্যকর তারিখ শিক্ষার্থীর সেশনের (' . \bnDigits($session) . ') মধ্যে হতে হবে।');
        }
        $pending = \fetchRows(
            $this->db,
            'SELECT id FROM acc_concessions WHERE student_id = :student AND session_year = :session AND fee_head_id = :head AND status = :status LIMIT 1',
            ['student' => $studentId, 'session' => $session, 'head' => $headId, 'status' => ApprovalStatus::PENDING]
        );
        if ($pending !== []) {
            throw new RuntimeException('এই ফি হেডে আগের একটি ছাড়ের আবেদন অনুমোদনের অপেক্ষায় আছে।');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO acc_concessions
                    (student_id, session_year, fee_head_id, kind, value, reason, effective_from, status, requested_by, requested_at)
                 VALUES (:student, :session, :head, :kind, :value, :reason, :effective, :status, :actor, :now)'
            )->execute([
                'student' => $studentId,
                'session' => $session,
                'head' => $headId,
                'kind' => $kind,
                'value' => $stored,
                'reason' => $reason,
                'effective' => $effectiveFrom,
                'status' => ApprovalStatus::PENDING,
                'actor' => $actor->teacherId(),
                'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->lastInsertId();
            $audit->record(
                'concession.request',
                'concession',
                $id,
                null,
                ['student_id' => $studentId, 'fee_head' => $head['code'], 'kind' => $kind, 'value' => $stored, 'effective_from' => $effectiveFrom, 'reason' => $reason],
                'ব্যক্তিগত ছাড়ের আবেদন: ' . StudentDirectory::displayName($student) . ' — ' . $head['name_bn']
            );
            if ($actor->can(Capability::CONCESSION_APPROVE)) {
                $this->approveLocked($actor, $audit, $id, 'প্রধান শিক্ষক কর্তৃক সরাসরি নির্ধারিত');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $id;
    }

    public function decide(AccessContext $actor, AuditTrail $audit, int $id, bool $approve, string $note): void
    {
        if (!$actor->can(Capability::CONCESSION_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::CONCESSION_APPROVE);
        }
        $note = FeeCatalog::text($note, 255);
        if (!$approve && mb_strlen($note, 'UTF-8') < 3) {
            throw new RuntimeException('প্রত্যাখ্যানের কারণ লিখুন।');
        }
        $this->db->beginTransaction();
        try {
            if ($approve) {
                $this->approveLocked($actor, $audit, $id, $note);
            } else {
                $this->lockPending($id);
                $this->db->prepare(
                    'UPDATE acc_concessions SET status = :status, decided_by = :actor, decided_at = :now, decision_note = :note WHERE id = :id'
                )->execute([
                    'status' => ApprovalStatus::REJECTED,
                    'actor' => $actor->teacherId(),
                    'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
                    'note' => $note,
                    'id' => $id,
                ]);
                $audit->record('concession.reject', 'concession', $id, ['status' => ApprovalStatus::PENDING], ['status' => ApprovalStatus::REJECTED, 'note' => $note], 'ব্যক্তিগত ছাড় প্রত্যাখ্যাত');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** Ends an approved concession from a date onward (head only). */
    public function end(AccessContext $actor, AuditTrail $audit, int $id, string $lastDay, string $note): void
    {
        if (!$actor->can(Capability::CONCESSION_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::CONCESSION_APPROVE);
        }
        FeeStructureRepository::parseDate($lastDay, 'শেষ তারিখ');
        $note = FeeCatalog::text($note, 255);
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT * FROM acc_concessions WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== ApprovalStatus::APPROVED) {
                throw new RuntimeException('সক্রিয় ছাড়টি পাওয়া যায়নি।');
            }
            if ($lastDay < (string) $row['effective_from']) {
                throw new RuntimeException('শেষ তারিখ কার্যকর তারিখের আগে হতে পারে না।');
            }
            $this->db->prepare('UPDATE acc_concessions SET effective_to = :to, decision_note = :note WHERE id = :id')
                ->execute(['to' => $lastDay, 'note' => $note === '' ? $row['decision_note'] : $note, 'id' => $id]);
            $audit->record('concession.end', 'concession', $id, ['effective_to' => $row['effective_to']], ['effective_to' => $lastDay, 'note' => $note], 'ব্যক্তিগত ছাড় সমাপ্ত');
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /**
     * @param list<int> $studentIds
     * @return array<int, array<int, array<string, mixed>>> [student_id][fee_head_id] => concession row
     */
    public function activeMap(array $studentIds, string $sessionYear, string $onDate): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0));
        if ($studentIds === []) {
            return [];
        }
        $map = [];
        foreach (\fetchRows(
            $this->db,
            'SELECT * FROM acc_concessions
             WHERE student_id IN (' . implode(',', $studentIds) . ') AND session_year = :session AND status = :status
               AND effective_from <= :on_date AND (effective_to IS NULL OR effective_to >= :on_date2)
             ORDER BY effective_from, id',
            ['session' => $sessionYear, 'status' => ApprovalStatus::APPROVED, 'on_date' => $onDate, 'on_date2' => $onDate]
        ) as $row) {
            $map[(int) $row['student_id']][(int) $row['fee_head_id']] = $row;
        }

        return $map;
    }

    /** Discount in paisa a concession gives on a line amount (never more than the amount). */
    public static function discountFor(array $concession, int $amount): int
    {
        $value = Money::fromDb($concession['value']);
        $discount = $concession['kind'] === self::PERCENT ? Money::percentOf($amount, $value) : $value;

        return max(0, min($amount, $discount));
    }

    public static function describe(array $concession): string
    {
        $value = Money::fromDb($concession['value']);

        return $concession['kind'] === self::PERCENT
            ? \bnDigits(rtrim(rtrim(Money::toDb($value), '0'), '.')) . '%'
            : Money::format($value);
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(int $studentId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT c.*, h.name_bn AS head_name, r.teacher_name AS requested_by_name, d.teacher_name AS decided_by_name
             FROM acc_concessions c
             JOIN acc_fee_heads h ON h.id = c.fee_head_id
             LEFT JOIN teacher_info r ON r.id = c.requested_by
             LEFT JOIN teacher_info d ON d.id = c.decided_by
             WHERE c.student_id = :student ORDER BY c.id DESC',
            ['student' => $studentId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function pending(int $limit = 300): array
    {
        return \fetchRows(
            $this->db,
            'SELECT c.*, h.name_bn AS head_name, s.name_ban, s.name_eng, s.cls_roll, cl.class_name_ban, sec.section_name_ban,
                    t.teacher_name AS requested_by_name
             FROM acc_concessions c
             JOIN acc_fee_heads h ON h.id = c.fee_head_id
             LEFT JOIN students_info s ON s.id = c.student_id
             LEFT JOIN cls cl ON cl.id = s.cls
             LEFT JOIN sections sec ON sec.id = s.sections
             LEFT JOIN teacher_info t ON t.id = c.requested_by
             WHERE c.status = :status ORDER BY c.requested_at, c.id
             LIMIT ' . max(1, min(1000, $limit)),
            ['status' => ApprovalStatus::PENDING]
        );
    }

    private function approveLocked(AccessContext $actor, AuditTrail $audit, int $id, string $note): void
    {
        $row = $this->lockPending($id);
        ApprovalWorkflow::supersede(
            $this->db,
            'acc_concessions',
            ['student_id' => (int) $row['student_id'], 'session_year' => (string) $row['session_year'], 'fee_head_id' => (int) $row['fee_head_id']],
            (string) $row['effective_from'],
            $id
        );
        $this->db->prepare(
            'UPDATE acc_concessions SET status = :status, decided_by = :actor, decided_at = :now, decision_note = :note WHERE id = :id'
        )->execute([
            'status' => ApprovalStatus::APPROVED,
            'actor' => $actor->teacherId(),
            'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
            'note' => $note === '' ? null : $note,
            'id' => $id,
        ]);
        $audit->record('concession.approve', 'concession', $id, ['status' => ApprovalStatus::PENDING], ['status' => ApprovalStatus::APPROVED], 'ব্যক্তিগত ছাড় অনুমোদিত');
    }

    /** @return array<string, mixed> */
    private function lockPending(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM acc_concessions WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== ApprovalStatus::PENDING) {
            throw new RuntimeException('আবেদনটি পাওয়া যায়নি বা আগেই নিষ্পত্তি হয়েছে।');
        }

        return $row;
    }
}
