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
 * Waiver (মওকুফ) of part or all of an invoice line's outstanding amount.
 * Takes effect only after the head approves; a head's own request is approved at once.
 */
final class WaiverService
{
    public function __construct(private PDO $db, private InvoiceService $invoices, private StudentDirectory $students)
    {
    }

    public function request(AccessContext $actor, AuditTrail $audit, int $itemId, string $amountInput, string $reason): int
    {
        if (!$actor->can(Capability::WAIVER_REQUEST)) {
            throw AccessDeniedException::forCapability(Capability::WAIVER_REQUEST);
        }
        $item = $this->item($itemId);
        $student = $this->students->requireInScope($actor, Capability::WAIVER_REQUEST, (int) $item['student_id']);
        $invoice = $this->invoices->invoice((int) $item['invoice_id']);
        if ($invoice === null || $invoice['status'] === InvoiceService::CANCELLED) {
            throw new RuntimeException('বাতিল ইনভয়েসে মওকুফ দেওয়া যায় না।');
        }
        $amount = Money::parse($amountInput, 'মওকুফের পরিমাণ');
        $outstanding = Money::fromDb($item['net']) - Money::fromDb($item['paid']);
        if ($amount < 1 || $amount > $outstanding) {
            throw new RuntimeException('মওকুফের পরিমাণ ০-এর বেশি এবং বকেয়া (' . Money::format(max(0, $outstanding)) . ') -এর সমান বা কম হতে হবে।');
        }
        $reason = FeeCatalog::text($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 3) {
            throw new RuntimeException('মওকুফের কারণ লিখুন।');
        }
        if (\fetchRows($this->db, 'SELECT id FROM acc_waivers WHERE invoice_item_id = :item AND status = :status LIMIT 1', ['item' => $itemId, 'status' => ApprovalStatus::PENDING]) !== []) {
            throw new RuntimeException('এই লাইনে একটি মওকুফের আবেদন ইতিমধ্যে অনুমোদনের অপেক্ষায়।');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO acc_waivers (invoice_item_id, invoice_id, student_id, amount, reason, status, requested_by, requested_at)
                 VALUES (:item, :invoice, :student, :amount, :reason, :status, :actor, :now)'
            )->execute([
                'item' => $itemId,
                'invoice' => (int) $item['invoice_id'],
                'student' => (int) $item['student_id'],
                'amount' => Money::toDb($amount),
                'reason' => $reason,
                'status' => ApprovalStatus::PENDING,
                'actor' => $actor->teacherId(),
                'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->lastInsertId();
            $audit->record(
                'waiver.request',
                'waiver',
                $id,
                null,
                ['invoice_no' => $invoice['invoice_no'], 'item_id' => $itemId, 'amount' => Money::toDb($amount), 'reason' => $reason],
                'মওকুফের আবেদন: ' . StudentDirectory::displayName($student) . ' — ' . $invoice['invoice_no']
            );
            if ($actor->can(Capability::WAIVER_APPROVE)) {
                $this->approveLocked($actor, $audit, $id, 'প্রধান শিক্ষক কর্তৃক সরাসরি মওকুফ');
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
        if (!$actor->can(Capability::WAIVER_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::WAIVER_APPROVE);
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
                $this->db->prepare('UPDATE acc_waivers SET status = :status, decided_by = :actor, decided_at = :now, decision_note = :note WHERE id = :id')
                    ->execute([
                        'status' => ApprovalStatus::REJECTED,
                        'actor' => $actor->teacherId(),
                        'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
                        'note' => $note,
                        'id' => $id,
                    ]);
                $audit->record('waiver.reject', 'waiver', $id, ['status' => ApprovalStatus::PENDING], ['status' => ApprovalStatus::REJECTED, 'note' => $note], 'মওকুফ প্রত্যাখ্যাত');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function pending(int $limit = 300): array
    {
        return \fetchRows(
            $this->db,
            'SELECT w.*, i.description, inv.invoice_no, inv.billing_label, inv.name_snapshot, inv.roll_snapshot,
                    cl.class_name_ban, sec.section_name_ban, t.teacher_name AS requested_by_name
             FROM acc_waivers w
             JOIN acc_invoice_items i ON i.id = w.invoice_item_id
             JOIN acc_invoices inv ON inv.id = w.invoice_id
             LEFT JOIN cls cl ON cl.id = inv.class_id
             LEFT JOIN sections sec ON sec.id = inv.section_id
             LEFT JOIN teacher_info t ON t.id = w.requested_by
             WHERE w.status = :status ORDER BY w.requested_at, w.id
             LIMIT ' . max(1, min(1000, $limit)),
            ['status' => ApprovalStatus::PENDING]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(int $studentId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT w.*, i.description, inv.invoice_no, r.teacher_name AS requested_by_name, d.teacher_name AS decided_by_name
             FROM acc_waivers w
             JOIN acc_invoice_items i ON i.id = w.invoice_item_id
             JOIN acc_invoices inv ON inv.id = w.invoice_id
             LEFT JOIN teacher_info r ON r.id = w.requested_by
             LEFT JOIN teacher_info d ON d.id = w.decided_by
             WHERE w.student_id = :student ORDER BY w.id DESC',
            ['student' => $studentId]
        );
    }

    private function approveLocked(AccessContext $actor, AuditTrail $audit, int $id, string $note): void
    {
        $waiver = $this->lockPending($id);
        $statement = $this->db->prepare('SELECT * FROM acc_invoice_items WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => (int) $waiver['invoice_item_id']]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        $invoice = $this->invoices->invoice((int) $waiver['invoice_id']);
        if (!$item || $invoice === null || $invoice['status'] === InvoiceService::CANCELLED) {
            throw new RuntimeException('ইনভয়েস লাইনটি আর সক্রিয় নেই।');
        }
        $amount = Money::fromDb($waiver['amount']);
        $net = Money::fromDb($item['net']);
        $outstanding = $net - Money::fromDb($item['paid']);
        if ($amount > $outstanding) {
            throw new RuntimeException('মওকুফের পরিমাণ এখনকার বকেয়ার (' . Money::format(max(0, $outstanding)) . ') চেয়ে বেশি; আবেদনটি প্রত্যাখ্যান করে নতুন আবেদন নিন।');
        }
        $waived = Money::fromDb($item['waived']) + $amount;
        $this->db->prepare('UPDATE acc_invoice_items SET waived = :waived, net = :net WHERE id = :id')
            ->execute(['waived' => Money::toDb($waived), 'net' => Money::toDb($net - $amount), 'id' => (int) $item['id']]);
        $this->db->prepare('UPDATE acc_waivers SET status = :status, decided_by = :actor, decided_at = :now, decision_note = :note WHERE id = :id')
            ->execute([
                'status' => ApprovalStatus::APPROVED,
                'actor' => $actor->teacherId(),
                'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
                'note' => $note === '' ? null : $note,
                'id' => $id,
            ]);
        $totals = $this->invoices->recompute((int) $item['invoice_id']);
        $audit->record(
            'waiver.approve',
            'waiver',
            $id,
            ['item_waived' => $item['waived'], 'item_net' => $item['net'], 'invoice_net' => $invoice['net']],
            ['item_waived' => Money::toDb($waived), 'item_net' => Money::toDb($net - $amount), 'invoice_net' => $totals['net']],
            'মওকুফ অনুমোদিত: ' . $invoice['invoice_no']
        );
    }

    /** @return array<string, mixed> */
    private function item(int $itemId): array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_invoice_items WHERE id = :id', ['id' => $itemId]);
        if ($rows === []) {
            throw new RuntimeException('ইনভয়েস লাইন পাওয়া যায়নি।');
        }

        return $rows[0];
    }

    /** @return array<string, mixed> */
    private function lockPending(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM acc_waivers WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== ApprovalStatus::PENDING) {
            throw new RuntimeException('আবেদনটি পাওয়া যায়নি বা আগেই নিষ্পত্তি হয়েছে।');
        }

        return $row;
    }
}
