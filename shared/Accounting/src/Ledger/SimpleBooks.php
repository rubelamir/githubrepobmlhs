<?php

declare(strict_types=1);

namespace School\Accounting\Ledger;

use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Support\Money;

/**
 * Everyday money movements written as ordinary vouchers.
 * The screen asks for আয়, খরচ, জমা and উত্তোলন; debit and credit stay inside.
 */
final class SimpleBooks
{
    public const FUND = '3100';
    public const STAFF_ADVANCE = '1180';
    public const SALARY = '5110';

    /** Fee income is recorded only by fee collection, so it is not offered here. */
    public const FEE_INCOME_CODES = ['4110', '4120', '4125', '4130', '4140', '4190'];

    public function __construct(private PDO $db, private ChartOfAccounts $chart, private VoucherService $vouchers)
    {
    }

    /**
     * @return array{voucher_id: int, duplicate: bool}
     */
    public function spend(AccessContext $actor, AuditTrail $audit, string $date, int $expenseAccountId, int $moneyAccountId, string $amount, string $narration, string $idempotencyKey): array
    {
        $this->leaf($expenseAccountId, 'expense');
        $this->moneyAccount($moneyAccountId);

        return $this->queue($actor, $audit, VoucherService::PAYMENT, $date, $narration, $moneyAccountId, $expenseAccountId, $amount, $idempotencyKey);
    }

    /**
     * Donation, grant or bank interest. Student fees stay on the collection page.
     *
     * @return array{voucher_id: int, duplicate: bool}
     */
    public function otherIncome(AccessContext $actor, AuditTrail $audit, string $date, int $incomeAccountId, int $moneyAccountId, string $amount, string $narration, string $idempotencyKey): array
    {
        $account = $this->leaf($incomeAccountId, 'income');
        if (in_array((string) $account['code'], self::FEE_INCOME_CODES, true)) {
            throw new RuntimeException('ফি আয় ফি আদায় থেকেই হয়। এখানে দান, অনুদান বা সুদ লিখুন।');
        }
        $this->moneyAccount($moneyAccountId);

        return $this->queue($actor, $audit, VoucherService::RECEIPT, $date, $narration, $incomeAccountId, $moneyAccountId, $amount, $idempotencyKey);
    }

    /**
     * Cash, bKash, Nagad and bank accounts moving money between themselves.
     *
     * @return array{voucher_id: int, duplicate: bool}
     */
    public function transfer(AccessContext $actor, AuditTrail $audit, string $date, int $fromAccountId, int $toAccountId, string $amount, string $narration, string $idempotencyKey): array
    {
        if ($fromAccountId === $toAccountId) {
            throw new RuntimeException('টাকা একই জায়গা থেকে একই জায়গায় সরানো যায় না।');
        }
        $this->moneyAccount($fromAccountId);
        $this->moneyAccount($toAccountId);

        return $this->queue($actor, $audit, VoucherService::CONTRA, $date, $narration, $fromAccountId, $toAccountId, $amount, $idempotencyKey);
    }

    /**
     * Money already given to a staff member and not yet adjusted against salary.
     *
     * @return array{voucher_id: int, duplicate: bool}
     */
    public function staffAdvance(AccessContext $actor, AuditTrail $audit, string $date, int $moneyAccountId, string $amount, string $narration, string $idempotencyKey): array
    {
        $this->moneyAccount($moneyAccountId);

        return $this->queue($actor, $audit, VoucherService::PAYMENT, $date, $narration, $moneyAccountId, $this->chart->idOf(self::STAFF_ADVANCE), $amount, $idempotencyKey);
    }

    /**
     * Clears part of a staff advance against salary. No cash moves.
     *
     * @return array{voucher_id: int, duplicate: bool}
     */
    public function adjustAdvance(AccessContext $actor, AuditTrail $audit, string $date, string $amount, string $narration, string $idempotencyKey): array
    {
        $paisa = Money::parse($amount, 'টাকার পরিমাণ');
        $advanceId = $this->chart->idOf(self::STAFF_ADVANCE);
        if ($this->chart->balance($advanceId) < $paisa) {
            throw new RuntimeException('সমন্বয়ের টাকা বকেয়া অগ্রিমের চেয়ে বেশি। আগে অগ্রিম যাচাই হওয়া চাই।');
        }

        return $this->queue($actor, $audit, VoucherService::JOURNAL, $date, $narration, $advanceId, $this->chart->idOf(self::SALARY), $amount, $idempotencyKey);
    }

    /**
     * Starting balance after the old test entries were removed. Posted at once,
     * once per account, until the head reverses it.
     */
    public function openingBalance(AccessContext $actor, AuditTrail $audit, string $date, int $moneyAccountId, string $amount, string $idempotencyKey): int
    {
        if (!$actor->can(\School\Accounting\Access\Capability::VOUCHER_CREATE)) {
            throw \School\Accounting\Access\AccessDeniedException::forCapability(\School\Accounting\Access\Capability::VOUCHER_CREATE);
        }
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('ফর্মটি পুরনো হয়ে গেছে; পাতাটি আবার খুলে চেষ্টা করুন।');
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new RuntimeException('তারিখ সঠিক নয়।');
        }
        $account = $this->moneyAccount($moneyAccountId);
        $paisa = Money::parse($amount, 'প্রারম্ভিক স্থিতি');
        $prefix = 'opening:' . $moneyAccountId . ':';
        $existing = \fetchRows(
            $this->db,
            "SELECT id FROM acc_vouchers WHERE source_key LIKE :prefix AND status = 'approved' AND reversed_by_id IS NULL LIMIT 1",
            ['prefix' => $prefix . '%']
        );
        if ($existing !== []) {
            throw new RuntimeException('এই হিসাবের প্রারম্ভিক স্থিতি আগেই দেওয়া আছে। ভুল হলে প্রধান শিক্ষক সেটি বাতিল করলে আবার দেওয়া যাবে।');
        }
        $narration = 'প্রারম্ভিক স্থিতি — ' . (string) $account['name_bn'];
        $sourceKey = $prefix . substr(preg_replace('/[^a-f0-9]/', '', $idempotencyKey) ?? '', 0, 12);
        if (strlen($sourceKey) > 60) {
            $sourceKey = substr($sourceKey, 0, 60);
        }

        $this->db->beginTransaction();
        try {
            $id = $this->vouchers->postSystem(VoucherService::RECEIPT, $date, $narration, [
                ['account_id' => $moneyAccountId, 'debit' => $paisa, 'credit' => 0],
                ['account_id' => $this->chart->idOf(self::FUND), 'debit' => 0, 'credit' => $paisa],
            ], $sourceKey, $actor->teacherId());
            $audit->record('books.opening', 'voucher', $id, null, [
                'account' => (string) $account['code'],
                'amount' => Money::toDb($paisa),
                'date' => $date,
            ], $narration);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function moneyAccount(int $id): array
    {
        $account = $this->chart->account($id);
        if ($account === null || (int) $account['is_active'] !== 1 || (int) $account['is_group'] === 1
            || !in_array((string) $account['money_kind'], ChartOfAccounts::SPENDABLE_KINDS, true)) {
            throw new RuntimeException('নগদ, বিকাশ, নগদ (Nagad) বা ব্যাংক বাছুন।');
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function leaf(int $id, string $type): array
    {
        $account = $this->chart->account($id);
        if ($account === null || (int) $account['is_active'] !== 1 || (int) $account['is_group'] === 1
            || (string) $account['account_type'] !== $type) {
            throw new RuntimeException($type === 'expense' ? 'খরচের খাত বাছুন।' : 'আয়ের খাত বাছুন।');
        }

        return $account;
    }

    /**
     * Money leaves $creditAccount and arrives in $debitAccount.
     *
     * @return array{voucher_id: int, duplicate: bool}
     */
    private function queue(
        AccessContext $actor,
        AuditTrail $audit,
        string $type,
        string $date,
        string $narration,
        int $creditAccountId,
        int $debitAccountId,
        string $amount,
        string $idempotencyKey
    ): array {
        $narration = FeeCatalog::text($narration, 255);
        if (mb_strlen($narration, 'UTF-8') < 3) {
            throw new RuntimeException('কীসের টাকা, সংক্ষেপে লিখুন।');
        }
        Money::parse($amount, 'টাকার পরিমাণ');
        $result = $this->vouchers->saveDraft($actor, $audit, null, [
            'type' => $type,
            'voucher_date' => $date,
            'narration' => $narration,
            'lines' => [
                ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => ''],
                ['account_id' => $creditAccountId, 'debit' => '', 'credit' => $amount],
            ],
        ], $idempotencyKey);
        if (!$result['duplicate']) {
            $this->vouchers->submit($actor, $audit, $result['voucher_id']);
        }

        return $result;
    }
}
