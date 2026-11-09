<?php

declare(strict_types=1);

namespace School\Accounting\Collection;

use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Ledger\PostingService;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Cash a class teacher collected stays in their custody until an accounts
 * officer (or the head) counts and receives it. A rejected handover releases
 * the receipts back to the teacher so they can be handed over again.
 */
final class HandoverService
{
    public const SUBMITTED = 'submitted';
    public const RECEIVED = 'received';
    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [self::SUBMITTED => 'গ্রহণের অপেক্ষায়', self::RECEIVED => 'গৃহীত', self::REJECTED => 'ফেরত'];
    public const STATUS_BADGES = [self::SUBMITTED => 'text-bg-warning', self::RECEIVED => 'text-bg-success', self::REJECTED => 'text-bg-danger'];

    public function __construct(private PDO $db, private ?PostingService $posting = null)
    {
    }

    /** @return list<array<string, mixed>> receipts still in this teacher's hands */
    public function pendingPayments(int $teacherId): array
    {
        return \fetchRows(
            $this->db,
            "SELECT p.*, c.class_name_ban, sec.section_name_ban FROM acc_payments p
             LEFT JOIN cls c ON c.id = p.class_id
             LEFT JOIN sections sec ON sec.id = p.section_id
             WHERE p.collected_by = :teacher AND p.status = 'confirmed' AND p.custody = 'teacher' AND p.handover_id IS NULL
             ORDER BY p.collected_at, p.id",
            ['teacher' => $teacherId]
        );
    }

    /**
     * @param list<int> $paymentIds
     * @return array{handover_id: int, duplicate: bool}
     */
    public function submit(AccessContext $actor, AuditTrail $audit, string $idempotencyKey, array $paymentIds, string $note): array
    {
        if (!$actor->can(Capability::HANDOVER_SUBMIT)) {
            throw AccessDeniedException::forCapability(Capability::HANDOVER_SUBMIT);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        $existing = $this->byIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->duplicateOf($existing, $actor);
        }
        $paymentIds = array_values(array_unique(array_filter(array_map('intval', $paymentIds), static fn (int $id): bool => $id > 0)));
        if ($paymentIds === []) {
            throw new RuntimeException('অন্তত একটি রসিদ নির্বাচন করুন।');
        }
        $note = FeeCatalog::text($note, 255);

        $this->db->beginTransaction();
        try {
            $rows = \fetchRows(
                $this->db,
                'SELECT id, amount, collected_by, status, custody, handover_id FROM acc_payments
                 WHERE id IN (' . implode(',', $paymentIds) . ') FOR UPDATE'
            );
            if (count($rows) !== count($paymentIds)) {
                throw new RuntimeException('কিছু রসিদ পাওয়া যায়নি।');
            }
            $total = 0;
            foreach ($rows as $row) {
                if ((int) $row['collected_by'] !== $actor->teacherId()
                    || $row['status'] !== PaymentService::CONFIRMED
                    || $row['custody'] !== PaymentService::CUSTODY_TEACHER
                    || $row['handover_id'] !== null) {
                    throw new RuntimeException('নির্বাচিত রসিদগুলোর মধ্যে কোনোটি আর হস্তান্তরযোগ্য নয়; পাতাটি আবার খুলুন।');
                }
                $total += Money::fromDb($row['amount']);
            }
            $now = AuditTrail::now();
            $number = Sequence::format('HND', $now->format('Y'), Sequence::next($this->db, 'HND', $now->format('Y')));
            $this->db->prepare(
                'INSERT INTO acc_handovers (handover_no, idempotency_key, teacher_id, total, payment_count, status, note, submitted_at)
                 VALUES (:no, :key, :teacher, :total, :count, :status, :note, :now)'
            )->execute([
                'no' => $number,
                'key' => $idempotencyKey,
                'teacher' => $actor->teacherId(),
                'total' => Money::toDb($total),
                'count' => count($rows),
                'status' => self::SUBMITTED,
                'note' => $note,
                'now' => $now->format('Y-m-d H:i:s'),
            ]);
            $handoverId = (int) $this->db->lastInsertId();
            $item = $this->db->prepare('INSERT INTO acc_handover_items (handover_id, payment_id, amount) VALUES (:handover, :payment, :amount)');
            foreach ($rows as $row) {
                $item->execute(['handover' => $handoverId, 'payment' => (int) $row['id'], 'amount' => $row['amount']]);
            }
            $this->db->prepare('UPDATE acc_payments SET handover_id = :handover WHERE id IN (' . implode(',', $paymentIds) . ')')
                ->execute(['handover' => $handoverId]);
            $audit->record(
                'handover.submit',
                'handover',
                $handoverId,
                null,
                ['handover_no' => $number, 'total' => Money::toDb($total), 'payments' => $paymentIds],
                'হস্তান্তর জমা ' . $number . ': ' . Money::format($total)
            );
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            $raced = $exception->getCode() === '23000' ? $this->byIdempotencyKey($idempotencyKey) : null;
            if ($raced !== null) {
                return $this->duplicateOf($raced, $actor);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['handover_id' => $handoverId, 'duplicate' => false];
    }

    /**
     * Receive after counting the cash (the counted amount must match), or send it back.
     */
    public function decide(AccessContext $actor, AuditTrail $audit, int $handoverId, bool $receive, string $countedInput, string $remark): void
    {
        if (!$actor->can(Capability::HANDOVER_RECEIVE)) {
            throw AccessDeniedException::forCapability(Capability::HANDOVER_RECEIVE);
        }
        $remark = FeeCatalog::text($remark, 255);
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT * FROM acc_handovers WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $handoverId]);
            $handover = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$handover || $handover['status'] !== self::SUBMITTED) {
                throw new RuntimeException('হস্তান্তরটি পাওয়া যায়নি বা আগেই নিষ্পত্তি হয়েছে।');
            }
            if ((int) $handover['teacher_id'] === $actor->teacherId()) {
                throw new RuntimeException('নিজের জমা দেওয়া হস্তান্তর নিজে গ্রহণ করা যায় না।');
            }
            $total = Money::fromDb($handover['total']);
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            if ($receive) {
                $counted = Money::parse($countedInput, 'গণনাকৃত টাকা');
                if ($counted !== $total) {
                    throw new RuntimeException('গণনাকৃত টাকা (' . Money::format($counted) . ') হস্তান্তরের মোটের (' . Money::format($total) . ') সমান নয়। গরমিল থাকলে কারণ লিখে ফেরত দিন।');
                }
                $this->db->prepare("UPDATE acc_payments SET custody = 'office' WHERE handover_id = :id AND status = 'confirmed'")
                    ->execute(['id' => $handoverId]);
            } else {
                if (mb_strlen($remark, 'UTF-8') < 3) {
                    throw new RuntimeException('ফেরতের কারণ লিখুন।');
                }
                $this->db->prepare('UPDATE acc_payments SET handover_id = NULL WHERE handover_id = :id')->execute(['id' => $handoverId]);
            }
            $status = $receive ? self::RECEIVED : self::REJECTED;
            $this->db->prepare('UPDATE acc_handovers SET status = :status, decided_by = :actor, decided_at = :now, remark = :remark WHERE id = :id')
                ->execute(['status' => $status, 'actor' => $actor->teacherId(), 'now' => $now, 'remark' => $remark, 'id' => $handoverId]);
            if ($receive) {
                $this->posting?->postHandover($handoverId);
            }
            $audit->record(
                $receive ? 'handover.receive' : 'handover.reject',
                'handover',
                $handoverId,
                ['status' => self::SUBMITTED, 'total' => $handover['total']],
                ['status' => $status, 'remark' => $remark],
                ($receive ? 'হস্তান্তর গ্রহণ ' : 'হস্তান্তর ফেরত ') . $handover['handover_no']
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function handover(int $handoverId): ?array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT h.*, t.teacher_name, d.teacher_name AS decided_by_name FROM acc_handovers h
             LEFT JOIN teacher_info t ON t.id = h.teacher_id
             LEFT JOIN teacher_info d ON d.id = h.decided_by
             WHERE h.id = :id',
            ['id' => $handoverId]
        );

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function items(int $handoverId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT p.*, hi.amount AS handed_amount FROM acc_handover_items hi
             JOIN acc_payments p ON p.id = hi.payment_id
             WHERE hi.handover_id = :id ORDER BY p.collected_at, p.id',
            ['id' => $handoverId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function search(string $status, int $teacherId = 0, int $limit = 300): array
    {
        $sql = 'SELECT h.*, t.teacher_name, d.teacher_name AS decided_by_name FROM acc_handovers h
                LEFT JOIN teacher_info t ON t.id = h.teacher_id
                LEFT JOIN teacher_info d ON d.id = h.decided_by WHERE 1 = 1';
        $params = [];
        if (isset(self::STATUS_LABELS[$status])) {
            $sql .= ' AND h.status = :status';
            $params['status'] = $status;
        }
        if ($teacherId > 0) {
            $sql .= ' AND h.teacher_id = :teacher';
            $params['teacher'] = $teacherId;
        }
        $sql .= ' ORDER BY h.submitted_at DESC, h.id DESC LIMIT ' . max(1, min(1000, $limit));

        return \fetchRows($this->db, $sql, $params);
    }

    public function submittedCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM acc_handovers WHERE status = 'submitted'")->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    private function byIdempotencyKey(string $key): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT id, teacher_id FROM acc_handovers WHERE idempotency_key = :key', ['key' => $key]);

        return $rows[0] ?? null;
    }

    /** @return array{handover_id: int, duplicate: bool} */
    private function duplicateOf(array $existing, AccessContext $actor): array
    {
        if ((int) $existing['teacher_id'] !== $actor->teacherId()) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }

        return ['handover_id' => (int) $existing['id'], 'duplicate' => true];
    }
}
