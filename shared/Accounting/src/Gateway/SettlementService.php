<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Ledger\ChartOfAccounts;
use School\Accounting\Ledger\PeriodClosing;
use School\Accounting\Ledger\PostingService;
use School\Accounting\Ledger\VoucherService;
use School\Accounting\Support\Money;
use School\Accounting\Support\Sequence;

/**
 * Gateway settlements: money moving from the bKash/Nagad merchant account to
 * a bank account, less the gateway's charge. Recorded by the accounts office
 * from the gateway statement and verified by the head:
 *
 *   Dr bank (net) + Dr 5160 gateway charge   Cr 1130 bKash / 1140 Nagad (gross)
 */
final class SettlementService
{
    public const SUBMITTED = 'submitted';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';

    public const STATUS_LABELS = [self::SUBMITTED => 'যাচাইয়ের অপেক্ষায়', self::VERIFIED => 'যাচাইকৃত', self::REJECTED => 'ফেরত'];
    public const STATUS_BADGES = [self::SUBMITTED => 'text-bg-warning', self::VERIFIED => 'text-bg-success', self::REJECTED => 'text-bg-secondary'];

    public function __construct(
        private PDO $db,
        private ChartOfAccounts $chart,
        private VoucherService $vouchers,
        private PeriodClosing $periods
    ) {
    }

    /** Ledger balance of the gateway account minus settlements awaiting verification. */
    public function available(string $gateway): int
    {
        $pending = Money::fromDb(\fetchRows(
            $this->db,
            "SELECT COALESCE(SUM(gross), 0) AS total FROM acc_settlements WHERE gateway = :g AND status = 'submitted'",
            ['g' => $gateway]
        )[0]['total']);

        return $this->chart->balance($this->chart->idOf(PostingService::custodyAccount($gateway))) - $pending;
    }

    /**
     * @param array<string, mixed> $input gateway, settlement_date, gross, charge, bank_account_id, reference, note
     * @return array{settlement_id: int, duplicate: bool}
     */
    public function submit(AccessContext $actor, AuditTrail $audit, string $idempotencyKey, array $input): array
    {
        if (!$actor->can(Capability::BANK_RECONCILE)) {
            throw AccessDeniedException::forCapability(Capability::BANK_RECONCILE);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        $existing = \fetchRows($this->db, 'SELECT id FROM acc_settlements WHERE idempotency_key = :k', ['k' => $idempotencyKey]);
        if ($existing !== []) {
            return ['settlement_id' => (int) $existing[0]['id'], 'duplicate' => true];
        }
        $gateway = (string) ($input['gateway'] ?? '');
        if (!in_array($gateway, ['bkash', 'nagad'], true)) {
            throw new RuntimeException('গেটওয়ে নির্বাচন করুন।');
        }
        $date = (string) ($input['settlement_date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date > AuditTrail::now()->format('Y-m-d')) {
            throw new RuntimeException('সেটেলমেন্টের তারিখ সঠিক নয় (ভবিষ্যতের তারিখ দেওয়া যাবে না)।');
        }
        $this->periods->assertOpen($date);
        $gross = Money::parse((string) ($input['gross'] ?? ''), 'মোট সেটেলমেন্ট');
        $charge = Money::parse((string) ($input['charge'] ?? '0'), 'গেটওয়ে চার্জ');
        if ($gross < 1 || $charge >= $gross) {
            throw new RuntimeException('মোট পরিমাণ শূন্যের বেশি এবং চার্জ মোটের চেয়ে কম হতে হবে।');
        }
        $bank = $this->bankAccount((int) ($input['bank_account_id'] ?? 0));
        $reference = FeeCatalog::text((string) ($input['reference'] ?? ''), 80);
        if ($reference === '') {
            throw new RuntimeException('গেটওয়ে স্টেটমেন্ট/সেটেলমেন্ট রেফারেন্স লিখুন।');
        }
        $note = FeeCatalog::text((string) ($input['note'] ?? ''), 255);

        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM acc_accounts WHERE code = :code FOR UPDATE')->execute(['code' => PostingService::custodyAccount($gateway)]);
            $available = $this->available($gateway);
            if ($gross > $available) {
                throw new RuntimeException('খাতায় গেটওয়ে হিসাবে এত টাকা নেই (সেটেলযোগ্য ' . Money::format(max(0, $available)) . ')।');
            }
            $now = AuditTrail::now();
            $number = Sequence::format('STL', $now->format('Y'), Sequence::next($this->db, 'STL', $now->format('Y')));
            $this->db->prepare(
                'INSERT INTO acc_settlements (settlement_no, idempotency_key, gateway, settlement_date, gross, charge, net, bank_account_id, reference, status, note, submitted_by, submitted_at)
                 VALUES (:no, :key, :g, :date, :gross, :charge, :net, :bank, :ref, :status, :note, :actor, :now)'
            )->execute([
                'no' => $number, 'key' => $idempotencyKey, 'g' => $gateway, 'date' => $date, 'gross' => Money::toDb($gross),
                'charge' => Money::toDb($charge), 'net' => Money::toDb($gross - $charge), 'bank' => (int) $bank['id'], 'ref' => $reference,
                'status' => self::SUBMITTED, 'note' => $note, 'actor' => $actor->teacherId(), 'now' => $now->format('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->lastInsertId();
            $audit->record('settlement.submit', 'settlement', $id, null, [
                'settlement_no' => $number, 'gateway' => $gateway, 'gross' => Money::toDb($gross), 'charge' => Money::toDb($charge), 'bank_account_id' => (int) $bank['id'], 'reference' => $reference,
            ], 'গেটওয়ে সেটেলমেন্ট ' . $number);
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            $raced = $exception->getCode() === '23000' ? \fetchRows($this->db, 'SELECT id FROM acc_settlements WHERE idempotency_key = :k', ['k' => $idempotencyKey]) : [];
            if ($raced !== []) {
                return ['settlement_id' => (int) $raced[0]['id'], 'duplicate' => true];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['settlement_id' => $id, 'duplicate' => false];
    }

    public function decide(AccessContext $actor, AuditTrail $audit, int $id, bool $verify, string $remark): void
    {
        if (!$actor->can(Capability::VOUCHER_APPROVE)) {
            throw AccessDeniedException::forCapability(Capability::VOUCHER_APPROVE);
        }
        $remark = FeeCatalog::text($remark, 255);
        $this->db->beginTransaction();
        try {
            $row = \fetchRows($this->db, 'SELECT * FROM acc_settlements WHERE id = :id FOR UPDATE', ['id' => $id])[0] ?? null;
            if ($row === null || $row['status'] !== self::SUBMITTED) {
                throw new RuntimeException('সেটেলমেন্টটি পাওয়া যায়নি বা আগেই নিষ্পত্তি হয়েছে।');
            }
            $voucherId = null;
            if ($verify) {
                $gross = Money::fromDb($row['gross']);
                $charge = Money::fromDb($row['charge']);
                $walletCode = PostingService::custodyAccount((string) $row['gateway']);
                $lines = [
                    ['account_id' => (int) $row['bank_account_id'], 'debit' => $gross - $charge, 'credit' => 0, 'memo' => 'রেফ ' . $row['reference']],
                    ['account_id' => $this->chart->idOf($walletCode), 'debit' => 0, 'credit' => $gross, 'memo' => (string) $row['settlement_no']],
                ];
                if ($charge > 0) {
                    $lines[] = ['account_id' => $this->chart->idOf(ChartOfAccounts::GATEWAY_CHARGE), 'debit' => $charge, 'credit' => 0, 'memo' => 'সেটেলমেন্ট চার্জ ' . $row['settlement_no']];
                }
                $voucherId = $this->vouchers->postSystem(
                    $charge > 0 ? VoucherService::JOURNAL : VoucherService::CONTRA,
                    (string) $row['settlement_date'],
                    'গেটওয়ে সেটেলমেন্ট — ' . $row['settlement_no'] . ', রেফ ' . $row['reference'],
                    $lines,
                    'settlement:' . $id,
                    $actor->teacherId()
                );
                if ($this->chart->balance($this->chart->idOf($walletCode)) < 0) {
                    throw new RuntimeException('খাতায় গেটওয়ে হিসাবে পর্যাপ্ত টাকা নেই; সেটেলমেন্টটি যাচাই করা যাচ্ছে না।');
                }
            } elseif (mb_strlen($remark, 'UTF-8') < 3) {
                throw new RuntimeException('ফেরতের কারণ লিখুন।');
            }
            $status = $verify ? self::VERIFIED : self::REJECTED;
            $this->db->prepare('UPDATE acc_settlements SET status = :status, decided_by = :actor, decided_at = :now, remark = :remark, voucher_id = :voucher WHERE id = :id')
                ->execute(['status' => $status, 'actor' => $actor->teacherId(), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'remark' => $remark, 'voucher' => $voucherId, 'id' => $id]);
            $audit->record($verify ? 'settlement.verify' : 'settlement.reject', 'settlement', $id, ['status' => self::SUBMITTED], ['status' => $status, 'remark' => $remark, 'voucher_id' => $voucherId], ($verify ? 'সেটেলমেন্ট যাচাই ' : 'সেটেলমেন্ট ফেরত ') . $row['settlement_no']);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function search(string $status = '', int $limit = 300): array
    {
        $sql = 'SELECT s.*, a.code AS bank_code, a.name_bn AS bank_name, sb.teacher_name AS submitted_by_name, d.teacher_name AS decided_by_name, v.voucher_no
                FROM acc_settlements s
                JOIN acc_accounts a ON a.id = s.bank_account_id
                LEFT JOIN teacher_info sb ON sb.id = s.submitted_by
                LEFT JOIN teacher_info d ON d.id = s.decided_by
                LEFT JOIN acc_vouchers v ON v.id = s.voucher_id';
        $params = [];
        if (isset(self::STATUS_LABELS[$status])) {
            $sql .= ' WHERE s.status = :status';
            $params['status'] = $status;
        }

        return \fetchRows($this->db, $sql . ' ORDER BY s.settlement_date DESC, s.id DESC LIMIT ' . max(1, min(1000, $limit)), $params);
    }

    public function submittedCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM acc_settlements WHERE status = 'submitted'")->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function bankAccount(int $id): array
    {
        foreach ($this->chart->bankAccounts() as $account) {
            if ((int) $account['id'] === $id) {
                return $account;
            }
        }
        throw new RuntimeException('ব্যাংক হিসাব নির্বাচন করুন (হিসাবের খাতে "ব্যাংক হিসাবসমূহ"-এর নিচে যোগ করা হিসাব)।');
    }
}
