<?php

declare(strict_types=1);

namespace School\Accounting\Ledger;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Billing\FeeCatalog;
use School\Accounting\Support\Money;

/**
 * Month closing. Nothing can be posted, approved or reversed with a date in a
 * closed month; the head can reopen it with a recorded reason.
 */
final class PeriodClosing
{
    public const CLOSED = 'closed';
    public const REOPENED = 'reopened';

    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private PDO $db, private ChartOfAccounts $chart)
    {
    }

    public static function periodOf(string $date): string
    {
        return substr($date, 0, 7);
    }

    public static function validPeriod(string $period): bool
    {
        return preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    public function isClosed(string $period): bool
    {
        if (!isset($this->cache[$period])) {
            $rows = \fetchRows($this->db, 'SELECT status FROM acc_period_closings WHERE period = :period', ['period' => $period]);
            $this->cache[$period] = ($rows[0]['status'] ?? '') === self::CLOSED;
        }

        return $this->cache[$period];
    }

    public function assertOpen(string $date): void
    {
        $period = self::periodOf($date);
        if ($this->isClosed($period)) {
            throw new RuntimeException(self::label($period) . ' মাসের হিসাব বন্ধ (closed)। এই তারিখে কোনো এন্ট্রি দেওয়া যাবে না; প্রয়োজনে প্রধান শিক্ষক মাসটি পুনরায় খুলবেন।');
        }
    }

    public static function label(string $period): string
    {
        $months = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];

        return ($months[(int) substr($period, 5, 2) - 1] ?? $period) . ' ' . bnDigits(substr($period, 0, 4));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return \fetchRows(
            $this->db,
            'SELECT pc.*, c.teacher_name AS closed_by_name, r.teacher_name AS reopened_by_name FROM acc_period_closings pc
             LEFT JOIN teacher_info c ON c.id = pc.closed_by
             LEFT JOIN teacher_info r ON r.id = pc.reopened_by
             ORDER BY pc.period DESC'
        );
    }

    /**
     * What still blocks closing the month.
     *
     * @return list<string>
     */
    public function blockers(string $period): array
    {
        $start = $period . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $blockers = [];
        $vouchers = (int) \fetchRows($this->db, "SELECT COUNT(*) AS n FROM acc_vouchers WHERE period = :period AND status IN ('draft', 'submitted')", ['period' => $period])[0]['n'];
        if ($vouchers > 0) {
            $blockers[] = bnDigits((string) $vouchers) . 'টি ভাউচার খসড়া বা অনুমোদনের অপেক্ষায় আছে (অনুমোদন, ফেরত বা বাতিল করুন)।';
        }
        $deposits = (int) \fetchRows($this->db, "SELECT COUNT(*) AS n FROM acc_bank_deposits WHERE status = 'submitted' AND deposit_date BETWEEN :start AND :end", ['start' => $start, 'end' => $end])[0]['n'];
        if ($deposits > 0) {
            $blockers[] = bnDigits((string) $deposits) . 'টি ব্যাংক জমা যাচাইয়ের অপেক্ষায় আছে।';
        }
        $handovers = (int) \fetchRows($this->db, "SELECT COUNT(*) AS n FROM acc_handovers WHERE status = 'submitted' AND submitted_at <= :end", ['end' => $end . ' 23:59:59'])[0]['n'];
        if ($handovers > 0) {
            $blockers[] = bnDigits((string) $handovers) . 'টি শিক্ষক-হস্তান্তর গ্রহণের অপেক্ষায় আছে।';
        }
        if (tableExists($this->db, 'acc_settlements')) {
            $settlements = (int) \fetchRows($this->db, "SELECT COUNT(*) AS n FROM acc_settlements WHERE status = 'submitted' AND settlement_date BETWEEN :start AND :end", ['start' => $start, 'end' => $end])[0]['n'];
            if ($settlements > 0) {
                $blockers[] = bnDigits((string) $settlements) . 'টি গেটওয়ে সেটেলমেন্ট যাচাইয়ের অপেক্ষায় আছে।';
            }
        }

        return $blockers;
    }

    public function close(AccessContext $actor, AuditTrail $audit, string $period, string $note): void
    {
        if (!$actor->can(Capability::PERIOD_CLOSE)) {
            throw AccessDeniedException::forCapability(Capability::PERIOD_CLOSE);
        }
        if (!self::validPeriod($period)) {
            throw new RuntimeException('মাস নির্বাচন সঠিক নয়।');
        }
        $end = (new DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
        if ($end >= AuditTrail::now()->format('Y-m-d')) {
            throw new RuntimeException('মাস শেষ হওয়ার পরেই কেবল মাসটি বন্ধ করা যাবে।');
        }
        if ($this->isClosed($period)) {
            throw new RuntimeException(self::label($period) . ' আগেই বন্ধ করা হয়েছে।');
        }
        $blockers = $this->blockers($period);
        if ($blockers !== []) {
            throw new RuntimeException('মাস বন্ধ করা যাচ্ছে না: ' . implode(' ', $blockers));
        }
        $trial = $this->chart->trialBalance($end);
        $balances = array_map(static fn (array $row): array => [
            'code' => $row['code'],
            'debit' => Money::toDb($row['tb_debit']),
            'credit' => Money::toDb($row['tb_credit']),
        ], $trial['rows']);
        $note = FeeCatalog::text($note, 255);
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO acc_period_closings (period, status, closed_by, closed_at, close_note, balances_json)
             VALUES (:period, :status, :actor, :now, :note, :balances)
             ON DUPLICATE KEY UPDATE status = VALUES(status), closed_by = VALUES(closed_by), closed_at = VALUES(closed_at),
                 close_note = VALUES(close_note), balances_json = VALUES(balances_json)'
        )->execute([
            'period' => $period, 'status' => self::CLOSED, 'actor' => $actor->teacherId(), 'now' => $now, 'note' => $note,
            'balances' => json_encode($balances, JSON_UNESCAPED_UNICODE),
        ]);
        $this->cache[$period] = true;
        $audit->record('period.close', 'period', null, null, ['period' => $period, 'note' => $note, 'debit' => Money::toDb($trial['debit']), 'credit' => Money::toDb($trial['credit'])], self::label($period) . ' মাস বন্ধ');
    }

    public function reopen(AccessContext $actor, AuditTrail $audit, string $period, string $reason): void
    {
        if (!$actor->can(Capability::PERIOD_CLOSE)) {
            throw AccessDeniedException::forCapability(Capability::PERIOD_CLOSE);
        }
        $reason = FeeCatalog::text($reason, 255);
        if (mb_strlen($reason, 'UTF-8') < 5) {
            throw new RuntimeException('মাস পুনরায় খোলার কারণ লিখুন।');
        }
        if (!self::validPeriod($period) || !$this->isClosed($period)) {
            throw new RuntimeException('মাসটি বন্ধ অবস্থায় নেই।');
        }
        $this->db->prepare('UPDATE acc_period_closings SET status = :status, reopened_by = :actor, reopened_at = :now, reopen_reason = :reason WHERE period = :period')
            ->execute(['status' => self::REOPENED, 'actor' => $actor->teacherId(), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'reason' => $reason, 'period' => $period]);
        $this->cache[$period] = false;
        $audit->record('period.reopen', 'period', null, ['period' => $period, 'status' => self::CLOSED], ['status' => self::REOPENED, 'reason' => $reason], self::label($period) . ' মাস পুনরায় খোলা');
    }
}
