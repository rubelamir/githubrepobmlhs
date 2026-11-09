<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Collection\PaymentService;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\PostingService;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Refunds of online payments: requested by the accounts office, approved by
 * the head, then sent to the gateway. The books change only when the gateway
 * confirms the refund (directly or later through Refund Status).
 *
 *   requested → rejected | processing → completed | failed (head may retry)
 */
final class RefundService
{
    public const REQUESTED = 'requested';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::REQUESTED => 'অনুমোদনের অপেক্ষায়',
        self::PROCESSING => 'প্রক্রিয়াধীন',
        self::COMPLETED => 'সম্পন্ন',
        self::FAILED => 'ব্যর্থ',
        self::REJECTED => 'নামঞ্জুর',
    ];
    public const STATUS_BADGES = [
        self::REQUESTED => 'text-bg-warning',
        self::PROCESSING => 'text-bg-info',
        self::COMPLETED => 'text-bg-success',
        self::FAILED => 'text-bg-danger',
        self::REJECTED => 'text-bg-secondary',
    ];

    private const REFUND_WINDOW_DAYS = 60;
    private const UNCONFIRMED_AFTER_MINUTES = 15;

    public function __construct(
        private PDO $db,
        private OnlinePaymentService $online,
        private PaymentService $payments,
        private ChartOfAccounts $chart
    ) {
    }

    /** Most that can still be refunded for a gateway transaction (paisa). */
    public function refundableFor(array $txn): int
    {
        if ($txn['status'] !== OnlinePaymentService::SUCCESS || $txn['payment_id'] === null) {
            return 0;
        }
        $open = Money::fromDb(\fetchRows(
            $this->db,
            "SELECT COALESCE(SUM(amount), 0) AS total FROM acc_refunds WHERE gateway_txn_id = :id AND status IN ('requested', 'processing', 'failed')",
            ['id' => (int) $txn['id']]
        )[0]['total']);

        return max(0, $this->payments->refundable((int) $txn['payment_id']) - $open);
    }

    /** @return array{refund_id: int, duplicate: bool} */
    public function request(AccessContext $actor, AuditTrail $audit, int $txnId, string $amountInput, string $reason, string $idempotencyKey): array
    {
        if (!$actor->can(Capability::REFUND_REQUEST)) {
            throw AccessDeniedException::forCapability(Capability::REFUND_REQUEST);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        $existing = \fetchRows($this->db, 'SELECT id FROM acc_refunds WHERE idempotency_key = :k', ['k' => $idempotencyKey]);
        if ($existing !== []) {
            return ['refund_id' => (int) $existing[0]['id'], 'duplicate' => true];
        }
        $amount = Money::parse($amountInput, 'রিফান্ডের পরিমাণ');
        if ($amount < 1) {
            throw new RuntimeException('রিফান্ডের পরিমাণ শূন্যের বেশি হতে হবে।');
        }
        $reason = FeeCatalog::text($reason, 200);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new RuntimeException('রিফান্ডের কারণ লিখুন।');
        }

        $this->db->beginTransaction();
        try {
            $txn = \fetchRows($this->db, 'SELECT * FROM acc_gateway_transactions WHERE id = :id FOR UPDATE', ['id' => $txnId])[0] ?? null;
            if ($txn === null || $txn['status'] !== OnlinePaymentService::SUCCESS) {
                throw new RuntimeException('শুধু সফল অনলাইন পেমেন্টের রিফান্ড করা যায়।');
            }
            if (strtotime((string) $txn['completed_at']) < AuditTrail::now()->modify('-' . self::REFUND_WINDOW_DAYS . ' days')->getTimestamp()) {
                throw new RuntimeException('পেমেন্টের ' . \bnDigits((string) self::REFUND_WINDOW_DAYS) . ' দিন পর গেটওয়েতে রিফান্ড করা যায় না।');
            }
            $max = $this->refundableFor($txn);
            if ($amount > $max) {
                throw new RuntimeException('সর্বোচ্চ ' . Money::format($max) . ' রিফান্ড করা যায় (আগের রিফান্ড ও ফি সমন্বয় বাদে)।');
            }
            $now = AuditTrail::now();
            $number = Sequence::format('RFD', $now->format('Y'), Sequence::next($this->db, 'RFD', $now->format('Y')));
            $this->db->prepare(
                'INSERT INTO acc_refunds (refund_no, idempotency_key, gateway_txn_id, payment_id, amount, reason, status, requested_by, requested_at)
                 VALUES (:no, :key, :txn, :payment, :amount, :reason, :status, :actor, :now)'
            )->execute([
                'no' => $number, 'key' => $idempotencyKey, 'txn' => $txnId, 'payment' => (int) $txn['payment_id'],
                'amount' => Money::toDb($amount), 'reason' => $reason, 'status' => self::REQUESTED,
                'actor' => $actor->teacherId(), 'now' => $now->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->lastInsertId();
            $audit->record('refund.request', 'refund', $id, null, ['refund_no' => $number, 'gateway_txn_id' => $txnId, 'amount' => Money::toDb($amount), 'reason' => $reason], 'রিফান্ড অনুরোধ ' . $number);
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            $raced = $exception->getCode() === '23000' ? \fetchRows($this->db, 'SELECT id FROM acc_refunds WHERE idempotency_key = :k', ['k' => $idempotencyKey]) : [];
            if ($raced !== []) {
                return ['refund_id' => (int) $raced[0]['id'], 'duplicate' => true];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['refund_id' => $id, 'duplicate' => false];
    }

    /** Head approves (sends to the gateway) or rejects; approving a failed refund retries it. */
    public function decide(AccessContext $actor, AuditTrail $audit, int $refundId, bool $approve, string $remark): string
    {
        if (!$actor->can(Capability::REFUND_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::REFUND_APPROVE);
        }
        $remark = FeeCatalog::text($remark, 255);
        $refund = $this->refund($refundId) ?? throw new RuntimeException('রিফান্ডটি পাওয়া যায়নি।');
        if (!in_array($refund['status'], [self::REQUESTED, self::FAILED], true)) {
            throw new RuntimeException('রিফান্ডটি আগেই নিষ্পত্তি হয়েছে বা প্রক্রিয়াধীন।');
        }
        if (!$approve) {
            if (mb_strlen($remark, 'UTF-8') < 3) {
                throw new RuntimeException('নামঞ্জুরের কারণ লিখুন।');
            }
            $this->transition($refundId, [$refund['status']], ['status' => self::REJECTED, 'decided_by' => $actor->teacherId(), 'decided_at' => AuditTrail::now()->format('Y-m-d H:i:s'), 'remark' => $remark]);
            $audit->record('refund.reject', 'refund', $refundId, ['status' => $refund['status']], ['status' => self::REJECTED, 'remark' => $remark], 'রিফান্ড নামঞ্জুর ' . $refund['refund_no']);

            return self::REJECTED;
        }
        $txn = $this->online->transaction((int) $refund['gateway_txn_id']);
        $amount = Money::fromDb($refund['amount']);
        if ($amount > $this->payments->refundable((int) $refund['payment_id'])) {
            throw new RuntimeException('রসিদে এত টাকা অবশিষ্ট নেই; রিফান্ডটি নামঞ্জুর করুন।');
        }
        $wallet = $this->chart->balance($this->chart->idOf(PostingService::custodyAccount((string) $txn['gateway'])));
        if ($amount > $wallet) {
            throw new RuntimeException('খাতায় ' . $this->online->gateway((string) $txn['gateway'])->label() . ' হিসাবে পর্যাপ্ত টাকা নেই (' . Money::format($wallet) . ')।');
        }
        $claimed = $this->transition($refundId, [self::REQUESTED, self::FAILED], [
            'status' => self::PROCESSING, 'decided_by' => $actor->teacherId(), 'decided_at' => AuditTrail::now()->format('Y-m-d H:i:s'),
            'remark' => $remark, 'gateway_error' => null,
        ]);
        if (!$claimed) {
            throw new RuntimeException('রিফান্ডটি এইমাত্র অন্য কেউ নিষ্পত্তি করেছেন।');
        }
        $audit->record('refund.approve', 'refund', $refundId, ['status' => $refund['status']], ['status' => self::PROCESSING, 'remark' => $remark], 'রিফান্ড অনুমোদন ' . $refund['refund_no']);

        return $this->send($refundId, $audit);
    }

    /**
     * Refunds whose gateway answer was lost are matched against Refund Status (cron).
     *
     * @return array<string, int>
     */
    public function reconcileProcessing(AuditTrail $audit, int $limit = 20): array
    {
        $counts = [];
        $rows = \fetchRows($this->db, "SELECT id FROM acc_refunds WHERE status = 'processing' AND (last_attempt_at IS NULL OR last_attempt_at < :t) ORDER BY id LIMIT " . max(1, min(100, $limit)), [
            't' => AuditTrail::now()->modify('-2 minutes')->format('Y-m-d H:i:s'),
        ]);
        foreach ($rows as $row) {
            try {
                $status = $this->confirmFromHistory((int) $row['id'], $audit);
            } catch (\Throwable $exception) {
                error_log('Refund reconcile failed for ' . $row['id'] . ': ' . $exception->getMessage());
                $status = 'error';
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function refund(int $id): ?array
    {
        return \fetchRows(
            $this->db,
            'SELECT r.*, g.merchant_invoice_no, g.trx_id, g.gateway, g.gateway_payment_id, p.receipt_no, p.name_snapshot,
                    rq.teacher_name AS requested_by_name, d.teacher_name AS decided_by_name, v.voucher_no
             FROM acc_refunds r
             JOIN acc_gateway_transactions g ON g.id = r.gateway_txn_id
             LEFT JOIN acc_payments p ON p.id = r.payment_id
             LEFT JOIN teacher_info rq ON rq.id = r.requested_by
             LEFT JOIN teacher_info d ON d.id = r.decided_by
             LEFT JOIN acc_vouchers v ON v.id = r.voucher_id
             WHERE r.id = :id',
            ['id' => $id]
        )[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $status = '', int $limit = 300): array
    {
        $sql = 'SELECT r.*, g.merchant_invoice_no, g.trx_id, g.gateway, p.receipt_no, p.name_snapshot, rq.teacher_name AS requested_by_name, v.voucher_no
                FROM acc_refunds r
                JOIN acc_gateway_transactions g ON g.id = r.gateway_txn_id
                LEFT JOIN acc_payments p ON p.id = r.payment_id
                LEFT JOIN teacher_info rq ON rq.id = r.requested_by
                LEFT JOIN acc_vouchers v ON v.id = r.voucher_id';
        $params = [];
        if (isset(self::STATUS_LABELS[$status])) {
            $sql .= ' WHERE r.status = :status';
            $params['status'] = $status;
        }

        return \fetchRows($this->db, $sql . ' ORDER BY r.requested_at DESC, r.id DESC LIMIT ' . max(1, min(1000, $limit)), $params);
    }

    /** @return list<array<string, mixed>> */
    public function forTransaction(int $txnId): array
    {
        return \fetchRows($this->db, 'SELECT r.*, v.voucher_no FROM acc_refunds r LEFT JOIN acc_vouchers v ON v.id = r.voucher_id WHERE r.gateway_txn_id = :id ORDER BY r.id', ['id' => $txnId]);
    }

    public function waitingCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM acc_refunds WHERE status IN ('requested', 'failed')")->fetchColumn();
    }

    private function send(int $refundId, AuditTrail $audit): string
    {
        $refund = $this->refund($refundId);
        $this->db->prepare('UPDATE acc_refunds SET attempts = attempts + 1, last_attempt_at = :now WHERE id = :id')
            ->execute(['now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'id' => $refundId]);
        $gateway = $this->online->gateway((string) $refund['gateway']);
        $result = $gateway->refund($refundId, (string) $refund['gateway_payment_id'], (string) $refund['trx_id'], Money::fromDb($refund['amount']), (string) $refund['reason'], (string) $refund['refund_no']);
        if ($result->is(GatewayResult::REFUNDED)) {
            if ($result->amount !== null && $result->amount !== Money::fromDb($refund['amount'])) {
                $this->db->prepare('UPDATE acc_refunds SET gateway_error = :e WHERE id = :id')->execute(['e' => 'গেটওয়ের রিফান্ড পরিমাণ ভিন্ন: ' . Money::format($result->amount), 'id' => $refundId]);
            }

            return $this->complete($refundId, $result->trxId, $audit);
        }
        if ($result->is(GatewayResult::ERROR)) {
            $this->transition($refundId, [self::PROCESSING], ['status' => self::FAILED, 'gateway_error' => mb_substr(trim($result->errorCode . ' ' . $result->message), 0, 255)]);
            $audit->record('refund.failed', 'refund', $refundId, ['status' => self::PROCESSING], ['status' => self::FAILED, 'error' => $result->errorCode], 'রিফান্ড ব্যর্থ ' . $refund['refund_no'] . ': ' . $result->message);

            return self::FAILED;
        }

        return $this->confirmFromHistory($refundId, $audit);
    }

    /** No direct answer: a completed refund of the same amount not yet booked is accepted as ours. */
    private function confirmFromHistory(int $refundId, AuditTrail $audit): string
    {
        $refund = $this->refund($refundId);
        if ($refund === null || $refund['status'] !== self::PROCESSING) {
            return (string) ($refund['status'] ?? 'missing');
        }
        $history = $this->online->gateway((string) $refund['gateway'])->refundHistory($refundId, (string) $refund['gateway_payment_id'], (string) $refund['trx_id']);
        if ($history !== null) {
            $booked = array_column(\fetchRows($this->db, 'SELECT refund_trx_id FROM acc_refunds WHERE refund_trx_id IS NOT NULL'), 'refund_trx_id');
            foreach ($history as $entry) {
                if ($entry['completed'] && $entry['amount'] === Money::fromDb($refund['amount']) && !in_array($entry['trx_id'], $booked, true)) {
                    return $this->complete($refundId, $entry['trx_id'], $audit);
                }
            }
        }
        $last = strtotime((string) ($refund['last_attempt_at'] ?? $refund['decided_at']));
        if ($history !== null && $last < AuditTrail::now()->modify('-' . self::UNCONFIRMED_AFTER_MINUTES . ' minutes')->getTimestamp()) {
            $this->transition($refundId, [self::PROCESSING], ['status' => self::FAILED, 'gateway_error' => 'গেটওয়েতে রিফান্ডটি পাওয়া যায়নি; প্রয়োজনে আবার অনুমোদন দিন।']);

            return self::FAILED;
        }

        return self::PROCESSING;
    }

    private function complete(int $refundId, string $refundTrxId, AuditTrail $audit): string
    {
        $this->db->beginTransaction();
        try {
            $refund = \fetchRows($this->db, 'SELECT * FROM acc_refunds WHERE id = :id FOR UPDATE', ['id' => $refundId])[0] ?? null;
            if ($refund === null || $refund['status'] === self::COMPLETED) {
                $this->db->commit();

                return self::COMPLETED;
            }
            $amount = Money::fromDb($refund['amount']);
            $paymentId = (int) $refund['payment_id'];
            $this->payments->refundAllocations(AccessContext::system('রিফান্ড'), $paymentId, $amount);
            $voucherId = \fetchRows(
                $this->db,
                "SELECT MAX(voucher_id) AS v FROM acc_payment_allocations WHERE payment_id = :id AND kind = 'refund'",
                ['id' => $paymentId]
            )[0]['v'] ?? null;
            $this->db->prepare('UPDATE acc_gateway_transactions SET refunded_amount = refunded_amount + :amount, updated_at = :now WHERE id = :id')
                ->execute(['amount' => Money::toDb($amount), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'id' => (int) $refund['gateway_txn_id']]);
            $this->db->prepare('UPDATE acc_refunds SET status = :status, refund_trx_id = :trx, completed_at = :now, voucher_id = :voucher WHERE id = :id')
                ->execute([
                    'status' => self::COMPLETED, 'trx' => mb_substr($refundTrxId, 0, 40), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
                    'voucher' => $voucherId === null ? null : (int) $voucherId, 'id' => $refundId,
                ]);
            $audit->record('refund.complete', 'refund', $refundId, ['status' => $refund['status']], ['status' => self::COMPLETED, 'refund_trx_id' => $refundTrxId, 'amount' => Money::toDb($amount)], 'রিফান্ড সম্পন্ন ' . $refund['refund_no']);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return self::COMPLETED;
    }

    /**
     * @param list<string> $from
     * @param array<string, mixed> $fields
     */
    private function transition(int $refundId, array $from, array $fields): bool
    {
        $sets = [];
        $params = ['id' => $refundId];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
        $placeholders = [];
        foreach (array_values($from) as $index => $status) {
            $placeholders[] = ':from' . $index;
            $params['from' . $index] = $status;
        }
        $statement = $this->db->prepare('UPDATE acc_refunds SET ' . implode(', ', $sets) . ' WHERE id = :id AND status IN (' . implode(', ', $placeholders) . ')');
        $statement->execute($params);

        return $statement->rowCount() === 1;
    }
}

