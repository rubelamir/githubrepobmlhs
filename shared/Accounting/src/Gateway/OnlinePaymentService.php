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
use School\Accounting\Collection\PaymentService;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Online fee payments.
 *
 *   initiated  row saved, Create Payment not answered yet
 *   pending    payer sent to the gateway; waiting for callback / verification
 *   success    the gateway confirmed (Execute or Query) and the receipt is recorded
 *   failed     create failed, or the payment ended without money taken
 *   cancelled  the payer cancelled (confirmed by Query, not by the callback alone)
 *   expired    never completed within the payment id lifetime (24 h)
 *   review     money taken but amount/invoice did not match: needs a person
 *
 * Callback parameters are only a hint; success is decided by a server-side
 * Execute/Query answer. Gateway calls are never made inside a DB transaction.
 */
final class OnlinePaymentService
{
    public const INITIATED = 'initiated';
    public const PENDING = 'pending';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const REVIEW = 'review';

    public const STATUS_LABELS = [
        self::INITIATED => 'শুরু হয়েছে',
        self::PENDING => 'পেন্ডিং',
        self::SUCCESS => 'সফল',
        self::FAILED => 'ব্যর্থ',
        self::CANCELLED => 'বাতিল',
        self::EXPIRED => 'মেয়াদোত্তীর্ণ',
        self::REVIEW => 'যাচাই প্রয়োজন',
    ];
    public const STATUS_BADGES = [
        self::INITIATED => 'text-bg-secondary',
        self::PENDING => 'text-bg-warning',
        self::SUCCESS => 'text-bg-success',
        self::FAILED => 'text-bg-danger',
        self::CANCELLED => 'text-bg-dark',
        self::EXPIRED => 'text-bg-light border',
        self::REVIEW => 'text-bg-danger',
    ];
    public const BEARERS = ['school' => 'স্কুল বহন করবে', 'payer' => 'অভিভাবক বহন করবেন'];

    private const MAX_PAYABLE = 50000000;
    private const PAYMENT_ID_LIFETIME_HOURS = 24;
    private const QUERY_INTERVAL_SECONDS = 120;

    /** @var array<string, PaymentGateway> */
    private array $gateways;

    /** @param list<PaymentGateway> $gateways */
    public function __construct(
        private PDO $db,
        private PaymentService $payments,
        array $gateways,
        private AccessContext $system,
        private AuditTrail $systemAudit
    ) {
        $this->gateways = [];
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->code()] = $gateway;
        }
    }

    /** @return array<string, PaymentGateway> */
    public function gateways(): array
    {
        return $this->gateways;
    }

    public function gateway(string $code): PaymentGateway
    {
        return $this->gateways[$code] ?? throw new RuntimeException('অজানা পেমেন্ট গেটওয়ে।');
    }

    /** @return array{gateway: string, enabled: bool, charge_bearer: string, charge_rate_bp: int, updated_at: ?string} */
    public function settings(string $code): array
    {
        $row = \fetchRows($this->db, 'SELECT * FROM acc_gateway_settings WHERE gateway = :g', ['g' => $code])[0] ?? [];

        return [
            'gateway' => $code,
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'charge_bearer' => isset(self::BEARERS[$row['charge_bearer'] ?? '']) ? (string) $row['charge_bearer'] : 'school',
            'charge_rate_bp' => (int) ($row['charge_rate_bp'] ?? 0),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $input enabled, charge_bearer, charge_rate */
    public function saveSettings(AccessContext $actor, AuditTrail $audit, string $code, array $input): void
    {
        if (!$actor->can(Capability::GATEWAY_SETTINGS)) {
            throw AccessDeniedException::forCapability(Capability::GATEWAY_SETTINGS);
        }
        $gateway = $this->gateway($code);
        $before = $this->settings($code);
        $enabled = !empty($input['enabled']);
        if ($enabled && !$gateway->isReady()) {
            throw new RuntimeException($gateway->label() . ' চালু করা যাচ্ছে না: অ্যাডাপ্টার বা ক্রেডেনশিয়াল প্রস্তুত নয়।');
        }
        $bearer = (string) ($input['charge_bearer'] ?? '');
        if (!isset(self::BEARERS[$bearer])) {
            throw new RuntimeException('গেটওয়ে চার্জ কে বহন করবে তা নির্বাচন করুন।');
        }
        $rate = Money::parsePercent((string) ($input['charge_rate'] ?? '0'));
        if ($rate > 1000) {
            throw new RuntimeException('গেটওয়ে চার্জের হার ১০%-এর বেশি হতে পারে না।');
        }
        $this->db->prepare(
            'INSERT INTO acc_gateway_settings (gateway, enabled, charge_bearer, charge_rate_bp, updated_by, updated_at)
             VALUES (:g, :enabled, :bearer, :rate, :actor, :now)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), charge_bearer = VALUES(charge_bearer), charge_rate_bp = VALUES(charge_rate_bp),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        )->execute([
            'g' => $code, 'enabled' => $enabled ? 1 : 0, 'bearer' => $bearer, 'rate' => $rate,
            'actor' => $actor->teacherId(), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
        ]);
        $after = $this->settings($code);
        unset($before['updated_at'], $after['updated_at']);
        $audit->record('gateway.settings', 'gateway', null, $before, $after, $gateway->label() . ' সেটিংস পরিবর্তন');
    }

    public function isAvailable(string $code): bool
    {
        return isset($this->gateways[$code]) && $this->settings($code)['enabled'] && $this->gateways[$code]->isReady();
    }

    /** @return list<string> */
    public function availableGateways(): array
    {
        return array_values(array_filter(array_keys($this->gateways), fn (string $code): bool => $this->isAvailable($code)));
    }

    /** @return array{fee: int, charge: int, payable: int, bearer: string} */
    public function quote(string $code, int $fee): array
    {
        $settings = $this->settings($code);
        $charge = Money::percentOf($fee, $settings['charge_rate_bp']);
        $payable = $settings['charge_bearer'] === 'payer' ? $fee + $charge : $fee;

        return ['fee' => $fee, 'charge' => $charge, 'payable' => $payable, 'bearer' => $settings['charge_bearer']];
    }

    /**
     * Saves the transaction and asks the gateway for a payment page.
     *
     * @param list<int> $invoiceIds invoices to settle (must have dues)
     * @return array{txn_id: int, redirect: string}
     */
    public function start(string $code, int $studentId, array $invoiceIds, string $idempotencyKey, string $clientIp, string $callbackUrl): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        if (!$this->isAvailable($code)) {
            throw new RuntimeException('এই মুহূর্তে অনলাইন পেমেন্ট চালু নেই।');
        }
        if (\fetchRows($this->db, 'SELECT id FROM acc_gateway_transactions WHERE idempotency_key = :k', ['k' => $idempotencyKey]) !== []) {
            throw new RuntimeException('এই পেমেন্টটি আগেই শুরু হয়েছে। ফলাফল দেখতে পোর্টালের "পেমেন্টের ইতিহাস" দেখুন।');
        }
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));
        if ($invoiceIds === []) {
            throw new RuntimeException('কোন ইনভয়েস পরিশোধ করবেন তা নির্বাচন করুন।');
        }
        $fee = 0;
        $covered = [];
        foreach ($this->payments->outstandingLines($studentId) as $line) {
            if (in_array((int) $line['invoice_id'], $invoiceIds, true)) {
                $fee += Money::fromDb($line['net']) - Money::fromDb($line['paid']);
                $covered[(int) $line['invoice_id']] = true;
            }
        }
        if ($fee < 100 || count($covered) !== count($invoiceIds)) {
            throw new RuntimeException('নির্বাচিত ইনভয়েসে পরিশোধযোগ্য বকেয়া নেই; পাতাটি আবার খুলুন।');
        }
        $quote = $this->quote($code, $fee);
        if ($quote['payable'] > self::MAX_PAYABLE) {
            throw new RuntimeException('একবারে এত টাকা অনলাইনে পরিশোধ করা যাবে না; ইনভয়েস কম নির্বাচন করুন।');
        }
        $ip = mb_substr($clientIp, 0, 45);

        $this->db->beginTransaction();
        try {
            $now = AuditTrail::now();
            $invoiceNo = Sequence::format('OLP', $now->format('Y'), Sequence::next($this->db, 'OLP', $now->format('Y')));
            $this->db->prepare(
                'INSERT INTO acc_gateway_transactions
                    (gateway, merchant_invoice_no, idempotency_key, student_id, invoice_ids, fee_amount, charge_amount, charge_bearer, payable_amount,
                     status, source, client_ip, created_at, updated_at)
                 VALUES (:g, :inv, :key, :student, :invoices, :fee, :charge, :bearer, :payable, :status, :source, :ip, :now, :now2)'
            )->execute([
                'g' => $code, 'inv' => $invoiceNo, 'key' => $idempotencyKey, 'student' => $studentId,
                'invoices' => implode(',', $invoiceIds), 'fee' => Money::toDb($quote['fee']), 'charge' => Money::toDb($quote['charge']),
                'bearer' => $quote['bearer'], 'payable' => Money::toDb($quote['payable']), 'status' => self::INITIATED,
                'source' => 'guardian', 'ip' => $ip, 'now' => $now->format('Y-m-d H:i:s'), 'now2' => $now->format('Y-m-d H:i:s'),
            ]);
            $txnId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('এই পেমেন্টটি আগেই শুরু হয়েছে।');
            }
            throw $exception;
        }

        $result = $this->gateway($code)->createPayment($txnId, $invoiceNo, $quote['payable'], 'SID' . $studentId, $callbackUrl);
        if (!$result->is(GatewayResult::CREATED)) {
            $this->update($txnId, [
                'status' => self::FAILED,
                'error_code' => $result->errorCode !== '' ? $result->errorCode : 'create',
                'error_message' => $result->message,
            ]);
            throw new RuntimeException('পেমেন্ট শুরু করা যায়নি' . ($result->message !== '' ? ': ' . $result->message : '') . '। কিছুক্ষণ পর আবার চেষ্টা করুন।');
        }
        $this->update($txnId, ['status' => self::PENDING, 'gateway_payment_id' => $result->paymentRef, 'gateway_status' => 'Initiated']);

        return ['txn_id' => $txnId, 'redirect' => $result->redirectUrl];
    }

    /**
     * Gateway callback: the status parameter only decides whether to try Execute;
     * the transaction becomes successful only from the gateway's own answer.
     *
     * @return int transaction id (0 when the payment reference is unknown)
     */
    public function handleCallback(string $code, string $paymentRef, string $callbackStatus): int
    {
        $paymentRef = trim($paymentRef);
        if ($paymentRef === '' || strlen($paymentRef) > 80 || !isset($this->gateways[$code])) {
            return 0;
        }
        $txn = \fetchRows($this->db, 'SELECT * FROM acc_gateway_transactions WHERE gateway = :g AND gateway_payment_id = :ref', ['g' => $code, 'ref' => $paymentRef])[0] ?? null;
        if ($txn === null) {
            return 0;
        }
        $hint = match (strtolower(trim($callbackStatus))) {
            'success' => 'success',
            'cancel', 'cancelled' => 'cancel',
            default => 'failure',
        };
        $txnId = (int) $txn['id'];
        if (in_array($txn['status'], [self::SUCCESS, self::REVIEW], true)) {
            return $txnId;
        }
        $this->db->prepare('UPDATE acc_gateway_transactions SET callback_status = :hint, updated_at = :now WHERE id = :id AND status IN (:p, :i)')
            ->execute(['hint' => $hint, 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'id' => $txnId, 'p' => self::PENDING, 'i' => self::INITIATED]);
        if ($hint === 'success') {
            $this->execute($txnId);
        } else {
            $this->verify($txnId, $hint);
        }

        return $txnId;
    }

    /**
     * Re-checks pending transactions with the gateway (cron). Completed ones are
     * recorded, abandoned ones become cancelled/failed/expired.
     *
     * @return array<string, int> counts by resulting status
     */
    public function reconcilePending(int $limit = 50): array
    {
        $now = AuditTrail::now();
        $counts = [];
        $stale = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        $this->db->prepare('UPDATE acc_gateway_transactions SET status = :failed, error_message = :msg, updated_at = :now WHERE status = :initiated AND created_at < :stale')
            ->execute(['failed' => self::FAILED, 'msg' => 'পেমেন্ট শুরু হয়নি (গেটওয়ের উত্তর পাওয়া যায়নি)', 'now' => $now->format('Y-m-d H:i:s'), 'initiated' => self::INITIATED, 'stale' => $stale]);
        $rows = \fetchRows(
            $this->db,
            'SELECT id FROM acc_gateway_transactions
             WHERE status = :pending AND created_at < :older AND (last_queried_at IS NULL OR last_queried_at < :queried)
             ORDER BY created_at LIMIT ' . max(1, min(200, $limit)),
            [
                'pending' => self::PENDING,
                'older' => $now->modify('-2 minutes')->format('Y-m-d H:i:s'),
                'queried' => $now->modify('-' . self::QUERY_INTERVAL_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            ]
        );
        foreach ($rows as $row) {
            try {
                $status = $this->verify((int) $row['id'], null, true);
            } catch (\Throwable $exception) {
                error_log('Gateway reconcile failed for txn ' . $row['id'] . ': ' . $exception->getMessage());
                $status = 'error';
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /** Staff "check again" for one transaction. */
    public function recheck(AccessContext $actor, int $txnId): string
    {
        if (!$actor->can(Capability::GATEWAY_SETTINGS) && !$actor->can(Capability::REFUND_REQUEST) && !$actor->can(Capability::REFUND_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::GATEWAY_SETTINGS);
        }
        $txn = $this->transaction($txnId) ?? throw new RuntimeException('লেনদেনটি পাওয়া যায়নি।');
        if (!in_array($txn['status'], [self::PENDING, self::FAILED, self::CANCELLED, self::EXPIRED], true) || $txn['gateway_payment_id'] === null) {
            return (string) $txn['status'];
        }

        return $this->verify($txnId, null, true, true);
    }

    /** @return array<string, mixed>|null */
    public function transaction(int $id): ?array
    {
        return \fetchRows(
            $this->db,
            'SELECT g.*, s.name_ban, s.name_eng, s.cls_roll, c.class_name_ban, sec.section_name_ban, p.receipt_no
             FROM acc_gateway_transactions g
             LEFT JOIN students_info s ON s.id = g.student_id
             LEFT JOIN cls c ON c.id = s.cls
             LEFT JOIN sections sec ON sec.id = s.sections
             LEFT JOIN acc_payments p ON p.id = g.payment_id
             WHERE g.id = :id',
            ['id' => $id]
        )[0] ?? null;
    }

    /**
     * @param array{status?: string, gateway?: string, from?: string, to?: string, q?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function search(array $filter, int $limit = 500): array
    {
        $sql = 'SELECT g.*, s.name_ban, s.name_eng, s.cls_roll, c.class_name_ban, sec.section_name_ban, p.receipt_no
                FROM acc_gateway_transactions g
                LEFT JOIN students_info s ON s.id = g.student_id
                LEFT JOIN cls c ON c.id = s.cls
                LEFT JOIN sections sec ON sec.id = s.sections
                LEFT JOIN acc_payments p ON p.id = g.payment_id
                WHERE 1 = 1';
        $params = [];
        if (isset(self::STATUS_LABELS[$filter['status'] ?? ''])) {
            $sql .= ' AND g.status = :status';
            $params['status'] = $filter['status'];
        }
        if (isset($this->gateways[$filter['gateway'] ?? ''])) {
            $sql .= ' AND g.gateway = :gateway';
            $params['gateway'] = $filter['gateway'];
        }
        if (($filter['from'] ?? '') !== '') {
            $sql .= ' AND g.created_at >= :from';
            $params['from'] = $filter['from'] . ' 00:00:00';
        }
        if (($filter['to'] ?? '') !== '') {
            $sql .= ' AND g.created_at < :to';
            $params['to'] = (new \DateTimeImmutable($filter['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (g.merchant_invoice_no = :q1 OR g.trx_id = :q2 OR g.gateway_payment_id = :q3 OR p.receipt_no = :q4)';
            $params += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q];
        }
        $sql .= ' ORDER BY g.created_at DESC, g.id DESC LIMIT ' . max(1, min(2000, $limit));

        return \fetchRows($this->db, $sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public function forStudent(int $studentId, int $limit = 20): array
    {
        return \fetchRows(
            $this->db,
            'SELECT g.*, p.receipt_no FROM acc_gateway_transactions g LEFT JOIN acc_payments p ON p.id = g.payment_id
             WHERE g.student_id = :s ORDER BY g.created_at DESC, g.id DESC LIMIT ' . max(1, min(100, $limit)),
            ['s' => $studentId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function logs(int $txnId): array
    {
        return \fetchRows($this->db, 'SELECT * FROM acc_gateway_logs WHERE gateway_txn_id = :id ORDER BY id', ['id' => $txnId]);
    }

    /** @return array<string, array{count: int, amount: int}> */
    public function statusSummary(string $from, string $to): array
    {
        $summary = [];
        foreach (\fetchRows(
            $this->db,
            'SELECT status, COUNT(*) AS n, SUM(payable_amount) AS amount FROM acc_gateway_transactions
             WHERE created_at >= :from AND created_at < :to GROUP BY status',
            ['from' => $from . ' 00:00:00', 'to' => (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']
        ) as $row) {
            $summary[(string) $row['status']] = ['count' => (int) $row['n'], 'amount' => Money::fromDb($row['amount'])];
        }

        return $summary;
    }

    public function attentionCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM acc_gateway_transactions WHERE status = 'review'")->fetchColumn();
    }

    /** Execute once per payment id (the gateway allows a single attempt), then settle the outcome. */
    private function execute(int $txnId): string
    {
        $claimed = $this->db->prepare(
            'UPDATE acc_gateway_transactions SET execute_attempted_at = :now, updated_at = :now2
             WHERE id = :id AND execute_attempted_at IS NULL AND gateway_payment_id IS NOT NULL AND status IN (:p, :f, :c)'
        );
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $claimed->execute(['now' => $now, 'now2' => $now, 'id' => $txnId, 'p' => self::PENDING, 'f' => self::FAILED, 'c' => self::CANCELLED]);
        if ($claimed->rowCount() !== 1) {
            return $this->verify($txnId, null);
        }
        $txn = $this->transaction($txnId);
        $result = $this->gateway((string) $txn['gateway'])->executePayment($txnId, (string) $txn['gateway_payment_id']);
        if ($result->is(GatewayResult::COMPLETED)) {
            return $this->finalize($txnId, $result);
        }
        if ($result->is(GatewayResult::ERROR)) {
            $this->update($txnId, ['error_code' => $result->errorCode, 'error_message' => $result->message]);
        }

        return $this->verify($txnId, null);
    }

    /**
     * Query the gateway and move the transaction to the state it reports.
     *
     * @param ?string $hint callback status when known ('cancel'/'failure')
     */
    private function verify(int $txnId, ?string $hint, bool $mayExecute = false, bool $manual = false): string
    {
        $txn = $this->transaction($txnId);
        if ($txn === null || $txn['gateway_payment_id'] === null) {
            return $txn['status'] ?? 'missing';
        }
        if (in_array($txn['status'], [self::SUCCESS, self::REVIEW], true)) {
            return (string) $txn['status'];
        }
        $hint ??= $txn['callback_status'];
        $result = $this->gateway((string) $txn['gateway'])->queryPayment($txnId, (string) $txn['gateway_payment_id']);
        $now = AuditTrail::now();
        $this->db->prepare('UPDATE acc_gateway_transactions SET last_queried_at = :now, query_attempts = query_attempts + 1, updated_at = :now2 WHERE id = :id')
            ->execute(['now' => $now->format('Y-m-d H:i:s'), 'now2' => $now->format('Y-m-d H:i:s'), 'id' => $txnId]);
        if ($result->is(GatewayResult::COMPLETED)) {
            return $this->finalize($txnId, $result);
        }
        $age = $now->getTimestamp() - strtotime((string) $txn['created_at']);
        $expired = $age > self::PAYMENT_ID_LIFETIME_HOURS * 3600;
        if ($result->is(GatewayResult::INITIATED)) {
            $this->update($txnId, ['gateway_status' => mb_substr($result->message, 0, 30)]);
            if ($txn['status'] !== self::PENDING && !$manual) {
                return (string) $txn['status'];
            }
            if ($txn['execute_attempted_at'] !== null) {
                return $this->close($txnId, $hint === 'cancel' ? self::CANCELLED : self::FAILED, 'গেটওয়ে পেমেন্টটি সম্পন্ন করেনি');
            }
            // The payer verified on the gateway page but the callback never reached us (closed browser).
            if ($mayExecute && $result->verified && $hint !== 'cancel' && $hint !== 'failure') {
                return $this->execute($txnId);
            }
            if ($hint === 'cancel') {
                return $this->close($txnId, self::CANCELLED, 'অভিভাবক পেমেন্ট বাতিল করেছেন');
            }
            if ($hint === 'failure') {
                return $this->close($txnId, self::FAILED, 'গেটওয়েতে যাচাই ব্যর্থ হয়েছে');
            }
            if ($expired) {
                return $this->close($txnId, self::EXPIRED, 'নির্ধারিত সময়ে পেমেন্ট সম্পন্ন হয়নি');
            }

            return self::PENDING;
        }
        if ($result->is(GatewayResult::ERROR)) {
            $this->update($txnId, ['error_code' => $result->errorCode, 'error_message' => $result->message]);
        }
        if ($expired && $txn['status'] === self::PENDING) {
            return $this->close($txnId, self::EXPIRED, 'নির্ধারিত সময়ে যাচাই করা যায়নি');
        }

        return (string) $txn['status'];
    }

    /** Records the receipt for a gateway-confirmed payment (exactly once). */
    private function finalize(int $txnId, GatewayResult $result): string
    {
        $this->db->beginTransaction();
        try {
            $txn = \fetchRows($this->db, 'SELECT * FROM acc_gateway_transactions WHERE id = :id FOR UPDATE', ['id' => $txnId])[0] ?? null;
            if ($txn === null) {
                throw new RuntimeException('Gateway transaction vanished.');
            }
            if (in_array($txn['status'], [self::SUCCESS, self::REVIEW], true)) {
                $this->db->commit();

                return (string) $txn['status'];
            }
            $payable = Money::fromDb($txn['payable_amount']);
            $problems = [];
            if ($result->amount !== $payable) {
                $problems[] = 'পরিমাণ মেলেনি (' . Money::format((int) $result->amount) . ' ≠ ' . Money::format($payable) . ')';
            }
            if ($result->invoiceNo !== '' && $result->invoiceNo !== $txn['merchant_invoice_no']) {
                $problems[] = 'ইনভয়েস নম্বর মেলেনি';
            }
            if ($result->paymentRef !== '' && $result->paymentRef !== $txn['gateway_payment_id']) {
                $problems[] = 'পেমেন্ট আইডি মেলেনি';
            }
            $now = AuditTrail::now()->format('Y-m-d H:i:s');
            $fields = [
                'trx_id' => mb_substr($result->trxId, 0, 40),
                'gateway_status' => 'Completed',
                'paid_amount' => $result->amount === null ? null : Money::toDb($result->amount),
                'payer_account_masked' => GatewayHttpClient::maskWallet($result->payerAccount),
                'completed_at' => $now,
                'error_code' => null,
                'error_message' => null,
            ];
            if ($problems !== []) {
                $fields['status'] = self::REVIEW;
                $fields['error_message'] = mb_substr(implode('; ', $problems), 0, 255);
                $this->update($txnId, $fields);
                $this->systemAudit->record('gateway.review', 'gateway_txn', $txnId, ['status' => $txn['status']], ['status' => self::REVIEW, 'problems' => $problems], 'অনলাইন পেমেন্ট যাচাই প্রয়োজন: ' . $txn['merchant_invoice_no']);
                $this->db->commit();

                return self::REVIEW;
            }
            $this->update($txnId, $fields);
            $txn = array_merge($txn, $fields);
            $paymentId = $this->payments->recordOnline($this->system, $this->systemAudit, $txn, $this->gateway((string) $txn['gateway'])->label());
            $this->update($txnId, ['status' => self::SUCCESS, 'payment_id' => $paymentId]);
            $this->systemAudit->record(
                'gateway.success',
                'gateway_txn',
                $txnId,
                ['status' => $txn['status']],
                ['status' => self::SUCCESS, 'trx_id' => $fields['trx_id'], 'payment_id' => $paymentId, 'amount' => Money::toDb($payable)],
                'অনলাইন পেমেন্ট সফল: ' . $txn['merchant_invoice_no'] . ' (TrxID ' . $fields['trx_id'] . ')'
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->notifyReceipt($paymentId);

        return self::SUCCESS;
    }

    private function close(int $txnId, string $status, string $message): string
    {
        $statement = $this->db->prepare(
            'UPDATE acc_gateway_transactions SET status = :status, error_message = COALESCE(error_message, :msg), updated_at = :now
             WHERE id = :id AND status NOT IN (:s, :r)'
        );
        $statement->execute(['status' => $status, 'msg' => $message, 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'id' => $txnId, 's' => self::SUCCESS, 'r' => self::REVIEW]);

        return $status;
    }

    /** @param array<string, mixed> $fields */
    private function update(int $txnId, array $fields): void
    {
        $fields['updated_at'] = AuditTrail::now()->format('Y-m-d H:i:s');
        $sets = [];
        $params = ['id' => $txnId];
        foreach ($fields as $column => $value) {
            if (preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new RuntimeException('Invalid column.');
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = is_string($value) ? mb_substr($value, 0, 255) : $value;
        }
        $this->db->prepare('UPDATE acc_gateway_transactions SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function notifyReceipt(int $paymentId): void
    {
        if (!function_exists('sendSchoolSms')) {
            return;
        }
        try {
            $payment = $this->payments->payment($paymentId);
            $mobile = \fetchRows($this->db, 'SELECT mobile FROM students_info WHERE id = :id', ['id' => (int) ($payment['student_id'] ?? 0)])[0]['mobile'] ?? '';
            if ($payment === null || trim((string) $mobile) === '') {
                return;
            }
            $text = 'ফি পরিশোধ সফল। রসিদ ' . $payment['receipt_no'] . ', ' . $payment['name_snapshot'] . ', ' . Money::format(Money::fromDb($payment['amount'])) . '। ধন্যবাদ।';
            $sms = \sendSchoolSms($this->db, (string) $mobile, $text, 'acc_online_receipt');
            $this->payments->setSmsStatus($paymentId, $sms['ok'] ? 'sent' : 'failed');
        } catch (\Throwable $exception) {
            error_log('Online receipt SMS failed: ' . $exception->getMessage());
        }
    }
}
