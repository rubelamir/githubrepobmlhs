<?php

declare(strict_types=1);

namespace School\Accounting\Ledger;

use PDO;
use School\Accounting\Support\Money;

/**
 * Turns fee collections, advance adjustments, receipt reversals and teacher
 * handovers into approved system vouchers. Every allocation row is posted
 * exactly once (its voucher_id is set), so calling these again is harmless.
 *
 *   collection by teacher   Dr 1120 teacher custody   Cr fee income / 2110 advance
 *   collection at office    Dr 1110 cash              Cr fee income / 2110 advance
 *   advance applied         Dr 2110 advance           Cr fee income
 *   receipt reversed        Dr fee income / 2110      Cr 1110 or 1120 (where the cash is)
 *   handover received       Dr 1110 cash              Cr 1120 teacher custody
 *   online payment          Dr 1130 bKash / 1140 Nagad  Cr fee income / 2110
 *     + charge paid by guardian  Dr 1130/1140         Cr 5160 gateway charge (recovery, not income)
 *   online refund           Dr fee income / 2110      Cr 1130/1140
 */
final class PostingService
{
    public function __construct(private PDO $db, private ChartOfAccounts $chart, private VoucherService $vouchers)
    {
    }

    /** Posts all not-yet-posted allocation rows of one payment. Call inside the payment's transaction. */
    public function postPayment(int $paymentId): void
    {
        $payment = \fetchRows($this->db, 'SELECT * FROM acc_payments WHERE id = :id', ['id' => $paymentId])[0] ?? null;
        if ($payment === null) {
            return;
        }
        $rows = \fetchRows(
            $this->db,
            'SELECT a.id, a.invoice_item_id, a.amount, a.kind, a.created_at, a.created_by, i.line_kind, h.income_account_id
             FROM acc_payment_allocations a
             LEFT JOIN acc_invoice_items i ON i.id = a.invoice_item_id
             LEFT JOIN acc_fee_heads h ON h.id = i.fee_head_id
             WHERE a.payment_id = :id AND a.voucher_id IS NULL
             ORDER BY a.id FOR UPDATE',
            ['id' => $paymentId]
        );
        if ($rows === []) {
            return;
        }
        $groups = [];
        foreach ($rows as $row) {
            $group = match ((string) $row['kind']) {
                'advance_applied' => 'adv:' . $row['created_at'],
                'refund' => 'refund:' . $row['created_at'],
                'reversal' => 'reversal',
                default => 'collect',
            };
            $groups[$group][] = $row;
        }
        $teacherId = (int) $payment['collected_by'];
        $name = (string) $payment['name_snapshot'];
        $receipt = (string) $payment['receipt_no'];
        foreach ($groups as $group => $groupRows) {
            [$type, $sourceKey, $narration, $cashCode] = match (true) {
                $group === 'collect' => [
                    VoucherService::RECEIPT,
                    'payment:' . $paymentId,
                    ($payment['gateway_txn_id'] !== null ? 'অনলাইন ফি আদায় — রসিদ ' : 'ফি আদায় — রসিদ ') . $receipt . ', ' . $name,
                    self::custodyAccount((string) $payment['collected_custody']),
                ],
                $group === 'reversal' => [
                    VoucherService::JOURNAL,
                    'payment:' . $paymentId . ':reversal',
                    'রসিদ রিভার্স — ' . $receipt . ', ' . $name . ($payment['reversal_reason'] ? ' (' . $payment['reversal_reason'] . ')' : ''),
                    self::custodyAccount((string) $payment['custody']),
                ],
                str_starts_with($group, 'refund:') => [
                    VoucherService::PAYMENT,
                    'payment:' . $paymentId . ':refund:' . $groupRows[0]['id'],
                    'অনলাইন পেমেন্ট রিফান্ড — রসিদ ' . $receipt . ', ' . $name,
                    self::custodyAccount((string) $payment['custody']),
                ],
                default => [
                    VoucherService::JOURNAL,
                    'payment:' . $paymentId . ':adv:' . $groupRows[0]['id'],
                    'অগ্রিম থেকে ফি সমন্বয় — রসিদ ' . $receipt . ', ' . $name,
                    null,
                ],
            };
            $lines = $this->linesFor($groupRows, $cashCode, $teacherId);
            if ($group === 'collect' && $lines !== [] && $payment['gateway_txn_id'] !== null) {
                $lines = array_merge($lines, $this->payerChargeLines((int) $payment['gateway_txn_id'], (string) $cashCode));
            }
            if ($lines !== []) {
                $date = substr((string) $groupRows[0]['created_at'], 0, 10);
                $voucherId = $this->vouchers->postSystem($type, $date, $narration, $lines, $sourceKey, (int) $groupRows[0]['created_by']);
            } else {
                $voucherId = null;
            }
            $ids = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $groupRows));
            $this->db->prepare('UPDATE acc_payment_allocations SET voucher_id = :voucher WHERE id IN (' . $ids . ')')
                ->execute(['voucher' => $voucherId ?? 0]);
            if ($group === 'collect' && $voucherId !== null) {
                $this->db->prepare('UPDATE acc_payments SET voucher_id = :voucher WHERE id = :id AND voucher_id IS NULL')
                    ->execute(['voucher' => $voucherId, 'id' => $paymentId]);
            }
        }
    }

    /** Call inside the transaction that marks the handover received. */
    public function postHandover(int $handoverId): void
    {
        $handover = \fetchRows($this->db, 'SELECT * FROM acc_handovers WHERE id = :id FOR UPDATE', ['id' => $handoverId])[0] ?? null;
        if ($handover === null || $handover['status'] !== 'received' || $handover['voucher_id'] !== null) {
            return;
        }
        $total = Money::fromDb($handover['total']);
        $teacherId = (int) $handover['teacher_id'];
        $voucherId = $this->vouchers->postSystem(
            VoucherService::CONTRA,
            substr((string) $handover['decided_at'], 0, 10),
            'শিক্ষকের হস্তান্তর গ্রহণ — ' . $handover['handover_no'],
            [
                ['account_id' => $this->chart->idOf(ChartOfAccounts::CASH), 'debit' => $total, 'credit' => 0, 'memo' => (string) $handover['handover_no']],
                ['account_id' => $this->chart->idOf(ChartOfAccounts::TEACHER_CUSTODY), 'debit' => 0, 'credit' => $total, 'party_type' => 'teacher', 'party_id' => $teacherId, 'memo' => (string) $handover['handover_no']],
            ],
            'handover:' . $handoverId,
            (int) $handover['decided_by']
        );
        $this->db->prepare('UPDATE acc_handovers SET voucher_id = :voucher WHERE id = :id')->execute(['voucher' => $voucherId, 'id' => $handoverId]);
    }

    /**
     * Posts anything recorded before the ledger existed (or missed by a failure).
     *
     * @return int number of payments/handovers posted
     */
    public function syncAll(): int
    {
        $count = 0;
        $payments = \fetchRows($this->db, 'SELECT DISTINCT payment_id FROM acc_payment_allocations WHERE voucher_id IS NULL ORDER BY payment_id LIMIT 500');
        $handovers = \fetchRows($this->db, "SELECT id FROM acc_handovers WHERE status = 'received' AND voucher_id IS NULL ORDER BY decided_at, id LIMIT 500");
        $jobs = [];
        foreach ($payments as $row) {
            $jobs[] = ['payment', (int) $row['payment_id']];
        }
        foreach ($handovers as $row) {
            $jobs[] = ['handover', (int) $row['id']];
        }
        foreach ($jobs as [$kind, $id]) {
            $this->db->beginTransaction();
            try {
                $kind === 'payment' ? $this->postPayment($id) : $this->postHandover($id);
                $this->db->commit();
                $count++;
            } catch (\Throwable $exception) {
                $this->db->rollBack();
                error_log('Accounting posting sync failed for ' . $kind . ' ' . $id . ': ' . $exception->getMessage());
            }
        }

        return $count;
    }

    public static function custodyAccount(string $custody): string
    {
        return match ($custody) {
            'teacher' => ChartOfAccounts::TEACHER_CUSTODY,
            'bkash' => ChartOfAccounts::BKASH,
            'nagad' => ChartOfAccounts::NAGAD,
            default => ChartOfAccounts::CASH,
        };
    }

    /**
     * A charge the guardian paid on top of the fee reaches the gateway account
     * too; it offsets the gateway's charge to the school instead of being income.
     *
     * @return list<array<string, mixed>>
     */
    private function payerChargeLines(int $gatewayTxnId, string $cashCode): array
    {
        $txn = \fetchRows($this->db, 'SELECT charge_amount, charge_bearer, merchant_invoice_no FROM acc_gateway_transactions WHERE id = :id', ['id' => $gatewayTxnId])[0] ?? null;
        $charge = $txn === null ? 0 : Money::fromDb($txn['charge_amount']);
        if ($txn === null || $txn['charge_bearer'] !== 'payer' || $charge <= 0) {
            return [];
        }
        $memo = 'অভিভাবক প্রদত্ত গেটওয়ে চার্জ — ' . $txn['merchant_invoice_no'];

        return [
            ['account_id' => $this->chart->idOf($cashCode), 'debit' => $charge, 'credit' => 0, 'memo' => $memo],
            ['account_id' => $this->chart->idOf(ChartOfAccounts::GATEWAY_CHARGE), 'debit' => 0, 'credit' => $charge, 'memo' => $memo],
        ];
    }

    /**
     * Allocation amounts are credits to income/advance (negative = debit);
     * the cash side takes the opposite of their sum.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function linesFor(array $rows, ?string $cashCode, int $teacherId): array
    {
        $byAccount = [];
        $sum = 0;
        foreach ($rows as $row) {
            $paisa = Money::fromDb($row['amount']);
            $accountId = $row['invoice_item_id'] === null
                ? $this->chart->idOf(ChartOfAccounts::ADVANCE)
                : $this->chart->incomeAccountForLine($row['income_account_id'] === null ? null : (int) $row['income_account_id'], (string) $row['line_kind']);
            $byAccount[$accountId] = ($byAccount[$accountId] ?? 0) + $paisa;
            $sum += $paisa;
        }
        $lines = [];
        if ($cashCode !== null && $sum !== 0) {
            $line = ['account_id' => $this->chart->idOf($cashCode), 'debit' => max(0, $sum), 'credit' => max(0, -$sum), 'memo' => ''];
            if ($cashCode === ChartOfAccounts::TEACHER_CUSTODY) {
                $line['party_type'] = 'teacher';
                $line['party_id'] = $teacherId;
            }
            $lines[] = $line;
        }
        foreach ($byAccount as $accountId => $paisa) {
            if ($paisa !== 0) {
                $lines[] = ['account_id' => $accountId, 'debit' => max(0, -$paisa), 'credit' => max(0, $paisa), 'memo' => ''];
            }
        }

        return $lines;
    }
}
