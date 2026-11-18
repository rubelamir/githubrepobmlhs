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
 * Which fee category (general / stipend / scholarship …) a student belongs to.
 * Officers and class teachers request; the head approves. A request by the head
 * is approved immediately. Students without an approved assignment are "general".
 */
final class StudentCategoryService
{
    public function __construct(private PDO $db, private FeeCatalog $catalog, private StudentDirectory $students)
    {
    }

    /**
     * @param list<int> $studentIds
     * @return array{created: int, skipped: list<string>, applied: int, advance: int, raised: int, skipped_waiver: int}
     */
    public function request(
        AccessContext $actor,
        AuditTrail $audit,
        array $studentIds,
        int $categoryId,
        string $effectiveFrom,
        string $referenceNo,
        string $note
    ): array {
        if (!$actor->can(Capability::CONCESSION_REQUEST)) {
            throw AccessDeniedException::forCapability(Capability::CONCESSION_REQUEST);
        }
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0)));
        if ($studentIds === []) {
            throw new RuntimeException('অন্তত একজন শিক্ষার্থী নির্বাচন করুন।');
        }
        if (count($studentIds) > 300) {
            throw new RuntimeException('একবারে সর্বোচ্চ ৩০০ জন শিক্ষার্থী নির্বাচন করা যায়।');
        }
        $category = $this->catalog->category($categoryId);
        if ($category === null || (int) $category['is_active'] !== 1) {
            throw new RuntimeException('সক্রিয় ফি-শ্রেণী নির্বাচন করুন।');
        }
        $effective = FeeStructureRepository::parseDate($effectiveFrom, 'কার্যকর তারিখ');
        $referenceNo = FeeCatalog::text($referenceNo, 100);
        $note = FeeCatalog::text($note, 255);
        if ((int) $category['requires_reference'] === 1 && $referenceNo === '') {
            throw new RuntimeException($category['name_bn'] . ' শ্রেণীর জন্য আদেশ/রেফারেন্স নম্বর লিখুন।');
        }
        $autoApprove = $actor->can(Capability::CONCESSION_APPROVE);
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $created = 0;
        $skipped = [];
        $applied = ['applied' => 0, 'advance' => 0, 'raised' => 0, 'skipped_waiver' => 0];

        $this->db->beginTransaction();
        try {
            foreach ($studentIds as $studentId) {
                $student = $this->students->requireInScope($actor, Capability::CONCESSION_REQUEST, $studentId);
                $name = StudentDirectory::displayName($student);
                $session = (string) $student['session_year'];
                if ($effective->format('Y') !== $session) {
                    $skipped[] = $name . ' (কার্যকর তারিখ শিক্ষার্থীর সেশন ' . \bnDigits($session) . '-এর বাইরে)';
                    continue;
                }
                if ($this->hasPending($studentId, $session)) {
                    $skipped[] = $name . ' (আগের আবেদন অনুমোদনের অপেক্ষায়)';
                    continue;
                }
                $current = $this->currentMap([$studentId], $session, $effectiveFrom)[$studentId] ?? null;
                if ($current !== null && (int) $current['fee_category_id'] === $categoryId) {
                    $skipped[] = $name . ' (ইতিমধ্যে এই ফি-শ্রেণীতে)';
                    continue;
                }
                if ($current === null && (int) $category['is_default'] === 1) {
                    $skipped[] = $name . ' (ইতিমধ্যে সাধারণ)';
                    continue;
                }
                $this->db->prepare(
                    'INSERT INTO acc_student_fee_categories
                        (student_id, session_year, fee_category_id, reference_no, effective_from, status, note, requested_by, requested_at)
                     VALUES (:student, :session, :category, :reference, :effective, :status, :note, :actor, :now)'
                )->execute([
                    'student' => $studentId,
                    'session' => $session,
                    'category' => $categoryId,
                    'reference' => $referenceNo,
                    'effective' => $effectiveFrom,
                    'status' => ApprovalStatus::PENDING,
                    'note' => $note,
                    'actor' => $actor->teacherId(),
                    'now' => $now,
                ]);
                $id = (int) $this->db->lastInsertId();
                $audit->record(
                    'student_category.request',
                    'student_fee_category',
                    $id,
                    $current === null ? ['student_id' => $studentId, 'category' => 'general'] : ['student_id' => $studentId, 'category_id' => (int) $current['fee_category_id']],
                    ['student_id' => $studentId, 'category_id' => $categoryId, 'reference_no' => $referenceNo, 'effective_from' => $effectiveFrom],
                    'ফি-শ্রেণী আবেদন: ' . $name . ' → ' . $category['name_bn']
                );
                if ($autoApprove) {
                    foreach ($this->approveLocked($actor, $audit, $id, 'প্রধান শিক্ষক কর্তৃক সরাসরি নির্ধারিত') as $key => $count) {
                        $applied[$key] += $count;
                    }
                }
                $created++;
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['created' => $created, 'skipped' => $skipped] + $applied;
    }

    public static function appliedToBillsNote(array $result): string
    {
        $count = (int) ($result['applied'] ?? 0);
        $advance = (int) ($result['advance'] ?? 0);
        $raised = (int) ($result['raised'] ?? 0);
        $skipped = (int) ($result['skipped_waiver'] ?? 0);
        $note = '';
        if ($count > 0) {
            $note .= ' কার্যকর তারিখের মাস থেকে ' . \bnDigits((string) $count) . 'টি মাসিক বিলে নতুন আদায়ের হার বসানো হয়েছে।';
            if ($advance > 0) {
                $note .= ' অতিরিক্ত আদায় ' . Money::format($advance) . '। এই টাকা আগে বকেয়া থেকে কাটা হয়; বকেয়া না থাকলে অগ্রিম থাকে।';
            }
            if ($raised > 0) {
                $note .= ' ' . \bnDigits((string) $raised) . 'টিতে নতুন হার বেশি বলে বকেয়া বেড়েছে।';
            }
        }
        if ($skipped > 0) {
            $note .= ' ' . \bnDigits((string) $skipped) . 'টিতে মওকুফের আবেদন অপেক্ষমাণ থাকায় হার বসানো হয়নি।';
        }

        return $note;
    }

    /** @return array{applied: int, advance: int, raised: int, skipped_waiver: int} */
    public function decide(AccessContext $actor, AuditTrail $audit, int $id, bool $approve, string $note): array
    {
        if (!$actor->can(Capability::CONCESSION_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::CONCESSION_APPROVE);
        }
        $note = FeeCatalog::text($note, 255);
        if (!$approve && mb_strlen($note, 'UTF-8') < 3) {
            throw new RuntimeException('প্রত্যাখ্যানের কারণ লিখুন।');
        }
        $this->db->beginTransaction();
        $result = ['applied' => 0, 'advance' => 0, 'raised' => 0, 'skipped_waiver' => 0];
        try {
            if ($approve) {
                $result = $this->approveLocked($actor, $audit, $id, $note);
            } else {
                $row = $this->lockPending($id);
                $this->db->prepare(
                    'UPDATE acc_student_fee_categories
                     SET status = :status, decided_by = :actor, decided_at = :now, decision_note = :note WHERE id = :id'
                )->execute([
                    'status' => ApprovalStatus::REJECTED,
                    'actor' => $actor->teacherId(),
                    'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
                    'note' => $note,
                    'id' => $id,
                ]);
                $audit->record('student_category.reject', 'student_fee_category', $id, ['status' => $row['status']], ['status' => ApprovalStatus::REJECTED, 'note' => $note], 'ফি-শ্রেণী আবেদন প্রত্যাখ্যাত');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $result;
    }

    /**
     * Approved category per student on a date.
     *
     * @param list<int> $studentIds
     * @return array<int, array<string, mixed>> student_id => assignment row (+ category name)
     */
    public function currentMap(array $studentIds, string $sessionYear, string $onDate): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0));
        if ($studentIds === []) {
            return [];
        }
        $rows = \fetchRows(
            $this->db,
            'SELECT a.*, c.name_bn AS category_name, c.code AS category_code
             FROM acc_student_fee_categories a
             JOIN acc_fee_categories c ON c.id = a.fee_category_id
             WHERE a.student_id IN (' . implode(',', $studentIds) . ')
               AND a.session_year = :session AND a.status = :status
               AND a.effective_from <= :on_date AND (a.effective_to IS NULL OR a.effective_to >= :on_date2)
             ORDER BY a.effective_from, a.id',
            ['session' => $sessionYear, 'status' => ApprovalStatus::APPROVED, 'on_date' => $onDate, 'on_date2' => $onDate]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['student_id']] = $row;
        }

        return $map;
    }

    /**
     * @param list<int> $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function pendingMap(array $studentIds, string $sessionYear): array
    {
        $studentIds = array_values(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0));
        if ($studentIds === []) {
            return [];
        }
        $map = [];
        foreach (\fetchRows(
            $this->db,
            'SELECT a.*, c.name_bn AS category_name FROM acc_student_fee_categories a
             JOIN acc_fee_categories c ON c.id = a.fee_category_id
             WHERE a.student_id IN (' . implode(',', $studentIds) . ') AND a.session_year = :session AND a.status = :status',
            ['session' => $sessionYear, 'status' => ApprovalStatus::PENDING]
        ) as $row) {
            $map[(int) $row['student_id']] = $row;
        }

        return $map;
    }

    /** @return list<array<string, mixed>> */
    public function pending(int $limit = 300): array
    {
        return \fetchRows(
            $this->db,
            'SELECT a.*, c.name_bn AS category_name, s.name_ban, s.name_eng, s.cls_roll, s.cls, s.sections,
                    cl.class_name_ban, sec.section_name_ban, t.teacher_name AS requested_by_name
             FROM acc_student_fee_categories a
             JOIN acc_fee_categories c ON c.id = a.fee_category_id
             LEFT JOIN students_info s ON s.id = a.student_id
             LEFT JOIN cls cl ON cl.id = s.cls
             LEFT JOIN sections sec ON sec.id = s.sections
             LEFT JOIN teacher_info t ON t.id = a.requested_by
             WHERE a.status = :status
             ORDER BY a.requested_at, a.id
             LIMIT ' . max(1, min(1000, $limit)),
            ['status' => ApprovalStatus::PENDING]
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(int $studentId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT a.*, c.name_bn AS category_name, r.teacher_name AS requested_by_name, d.teacher_name AS decided_by_name
             FROM acc_student_fee_categories a
             JOIN acc_fee_categories c ON c.id = a.fee_category_id
             LEFT JOIN teacher_info r ON r.id = a.requested_by
             LEFT JOIN teacher_info d ON d.id = a.decided_by
             WHERE a.student_id = :student ORDER BY a.id DESC',
            ['student' => $studentId]
        );
    }

    private function hasPending(int $studentId, string $sessionYear): bool
    {
        return \fetchRows(
            $this->db,
            'SELECT id FROM acc_student_fee_categories WHERE student_id = :student AND session_year = :session AND status = :status LIMIT 1',
            ['student' => $studentId, 'session' => $sessionYear, 'status' => ApprovalStatus::PENDING]
        ) !== [];
    }

    /**
     * Caller holds the transaction. Ends any assignment the new one overlaps,
     * then applies its monthly rate from the effective date.
     *
     * @return array{applied: int, advance: int, raised: int, skipped_waiver: int}
     */
    private function approveLocked(AccessContext $actor, AuditTrail $audit, int $id, string $note): array
    {
        $row = $this->lockPending($id);
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        ApprovalWorkflow::supersede(
            $this->db,
            'acc_student_fee_categories',
            ['student_id' => (int) $row['student_id'], 'session_year' => (string) $row['session_year']],
            (string) $row['effective_from'],
            $id
        );
        $this->db->prepare(
            'UPDATE acc_student_fee_categories
             SET status = :status, decided_by = :actor, decided_at = :now, decision_note = :note WHERE id = :id'
        )->execute([
            'status' => ApprovalStatus::APPROVED,
            'actor' => $actor->teacherId(),
            'now' => $now,
            'note' => $note === '' ? null : $note,
            'id' => $id,
        ]);
        $applied = \accInvoices()->applyApprovedCategory($row, $actor->teacherId());
        $audit->record(
            'student_category.approve',
            'student_fee_category',
            $id,
            ['status' => ApprovalStatus::PENDING],
            ['status' => ApprovalStatus::APPROVED, 'student_id' => (int) $row['student_id'], 'category_id' => (int) $row['fee_category_id'], 'effective_from' => $row['effective_from']] + $applied,
            'ফি-শ্রেণী অনুমোদিত'
        );

        return $applied;
    }

    /**
     * Writes one already-approved category onto monthly bills from its effective month.
     * Approval used to leave existing bills at the old rate.
     *
     * @return array{applied: int, advance: int, raised: int, skipped_waiver: int}
     */
    public function syncApprovedRate(int $assignmentId, int $actorId): array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT * FROM acc_student_fee_categories WHERE id = :id AND status = :status',
            ['id' => $assignmentId, 'status' => ApprovalStatus::APPROVED]
        );
        $empty = ['applied' => 0, 'advance' => 0, 'raised' => 0, 'skipped_waiver' => 0];
        if ($rows === []) {
            return $empty;
        }
        $row = $rows[0];
        $actor = (int) ($row['decided_by'] ?? 0);
        if ($actor < 1) {
            $actor = $actorId;
        }
        $this->db->beginTransaction();
        try {
            $applied = \accInvoices()->applyApprovedCategory($row, $actor);
            if ($applied['applied'] > 0) {
                \accAudit()->record(
                    'student_category.sync_rates',
                    'student_fee_category',
                    (int) $row['id'],
                    null,
                    [
                        'student_id' => (int) $row['student_id'],
                        'category_id' => (int) $row['fee_category_id'],
                        'effective_from' => (string) $row['effective_from'],
                    ] + $applied,
                    'আগে অনুমোদিত ফি-শ্রেণীর মাসিক হার বিলে বসানো হয়েছে'
                );
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $applied;
    }

    /** @return array<string, mixed> */
    private function lockPending(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM acc_student_fee_categories WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== ApprovalStatus::PENDING) {
            throw new RuntimeException('আবেদনটি পাওয়া যায়নি বা আগেই নিষ্পত্তি হয়েছে।');
        }

        return $row;
    }
}
