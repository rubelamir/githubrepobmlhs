<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Support\Money;

/**
 * Rates per fee head × session × class/section × fee category, versioned by effective date.
 * section_id 0 is the class-wide rate; a section-specific rate overrides it.
 */
final class FeeStructureRepository
{
    public function __construct(private PDO $db, private FeeCatalog $catalog)
    {
    }

    /** @return list<string> */
    public function effectiveDates(string $sessionYear, int $classId, int $sectionId): array
    {
        $rows = \fetchRows(
            $this->db,
            'SELECT DISTINCT effective_from FROM acc_fee_structures
             WHERE session_year = :session AND class_id = :class AND section_id = :section AND is_active = 1
             ORDER BY effective_from',
            ['session' => $sessionYear, 'class' => $classId, 'section' => $sectionId]
        );

        return array_map(static fn (array $row): string => (string) $row['effective_from'], $rows);
    }

    /**
     * Active rows saved for exactly this effective date, keyed [head_id][category_id].
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function grid(string $sessionYear, int $classId, int $sectionId, string $effectiveFrom): array
    {
        $grid = [];
        foreach (\fetchRows(
            $this->db,
            'SELECT * FROM acc_fee_structures
             WHERE session_year = :session AND class_id = :class AND section_id = :section
               AND effective_from = :effective AND is_active = 1',
            ['session' => $sessionYear, 'class' => $classId, 'section' => $sectionId, 'effective' => $effectiveFrom]
        ) as $row) {
            $grid[(int) $row['fee_head_id']][(int) $row['fee_category_id']] = $row;
        }

        return $grid;
    }

    /**
     * Saves a whole grid. An empty amount removes (deactivates) the rate for that cell.
     *
     * @param array<int|string, array<int|string, string>> $amounts  [head_id][category_id] => "1200"
     * @param array<int|string, array<string, string>> $settings     [head_id] => due_day
     * @return int number of changed cells
     */
    public function saveGrid(
        AccessContext $actor,
        AuditTrail $audit,
        string $sessionYear,
        int $classId,
        int $sectionId,
        string $effectiveFrom,
        array $amounts,
        array $settings
    ): int {
        if (!$actor->can(Capability::FEE_STRUCTURE_MANAGE)) {
            throw AccessDeniedException::forCapability(Capability::FEE_STRUCTURE_MANAGE);
        }
        self::assertSession($sessionYear);
        $effective = self::parseDate($effectiveFrom, 'কার্যকর তারিখ');
        if ($effective->format('Y') !== $sessionYear) {
            throw new RuntimeException('কার্যকর তারিখ নির্বাচিত সেশনের (' . \bnDigits($sessionYear) . ') মধ্যে হতে হবে।');
        }
        $this->assertClassSection($classId, $sectionId);

        $heads = $this->catalog->heads(true);
        $categories = $this->catalog->categories(true);
        $existing = $this->grid($sessionYear, $classId, $sectionId, $effectiveFrom);
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $changed = 0;

        $this->db->beginTransaction();
        try {
            foreach ($heads as $head) {
                $headId = (int) $head['id'];
                $headSettings = $settings[$headId] ?? [];
                $dueDay = (int) ($headSettings['due_day'] ?? 10);
                if ($dueDay < 1 || $dueDay > 31) {
                    throw new RuntimeException($head['name_bn'] . ': পরিশোধের দিন ১ থেকে ৩১-এর মধ্যে হতে হবে।');
                }
                $lateFine = 0;
                $graceDays = 0;

                foreach ($categories as $category) {
                    $categoryId = (int) $category['id'];
                    $raw = (string) ($amounts[$headId][$categoryId] ?? '');
                    $amount = Money::parseOptional($raw, $head['name_bn'] . ' (' . $category['name_bn'] . ')');
                    $current = $existing[$headId][$categoryId] ?? null;
                    $after = $amount === null ? null : [
                        'amount' => Money::toDb($amount),
                        'due_day' => $dueDay,
                        'late_fine_amount' => Money::toDb($lateFine),
                        'grace_days' => $graceDays,
                    ];
                    $before = $current === null ? null : [
                        'amount' => Money::toDb(Money::fromDb($current['amount'])),
                        'due_day' => (int) $current['due_day'],
                        'late_fine_amount' => Money::toDb(Money::fromDb($current['late_fine_amount'])),
                        'grace_days' => (int) $current['grace_days'],
                    ];
                    if ($before === $after) {
                        continue;
                    }
                    $this->db->prepare(
                        'INSERT INTO acc_fee_structures
                            (fee_head_id, session_year, class_id, section_id, fee_category_id, effective_from,
                             amount, due_day, late_fine_amount, grace_days, is_active, updated_by, created_at, updated_at)
                         VALUES (:head, :session, :class, :section, :category, :effective,
                                 :amount, :due_day, :late_fine, :grace, :active, :actor, :created_at, :updated_at)
                         ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_day = VALUES(due_day),
                             late_fine_amount = VALUES(late_fine_amount), grace_days = VALUES(grace_days),
                             is_active = VALUES(is_active), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
                    )->execute([
                        'head' => $headId,
                        'session' => $sessionYear,
                        'class' => $classId,
                        'section' => $sectionId,
                        'category' => $categoryId,
                        'effective' => $effectiveFrom,
                        'amount' => $after['amount'] ?? $before['amount'],
                        'due_day' => $dueDay,
                        'late_fine' => Money::toDb($lateFine),
                        'grace' => $graceDays,
                        'active' => $after === null ? 0 : 1,
                        'actor' => $actor->teacherId(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $context = [
                        'fee_head' => (string) $head['code'],
                        'fee_category' => (string) $category['code'],
                        'session_year' => $sessionYear,
                        'class_id' => $classId,
                        'section_id' => $sectionId,
                        'effective_from' => $effectiveFrom,
                    ];
                    $audit->record(
                        $after === null ? 'fee_rate.remove' : ($before === null ? 'fee_rate.create' : 'fee_rate.update'),
                        'fee_structure',
                        null,
                        $before === null ? null : $context + $before,
                        $after === null ? null : $context + $after,
                        'ফি হার: ' . $head['name_bn'] . ' — ' . $category['name_bn']
                    );
                    $changed++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $changed;
    }

    /**
     * All active rows that may apply to students of this class/section.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForClass(string $sessionYear, int $classId, int $sectionId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT * FROM acc_fee_structures
             WHERE session_year = :session AND class_id = :class AND section_id IN (0, :section) AND is_active = 1',
            ['session' => $sessionYear, 'class' => $classId, 'section' => $sectionId]
        );
    }

    /**
     * Picks the rate for one student and fee head.
     * Priority: the student's category over general, then section-specific over
     * class-wide, then the latest effective date on or before $onDate.
     *
     * @param list<array<string, mixed>> $rows from rowsForClass()
     * @return array{amount: int, standard: int, category_id: int, due_day: int, late_fine: int, grace_days: int}|null
     */
    public static function resolve(array $rows, int $headId, int $sectionId, int $categoryId, int $generalCategoryId, string $onDate): ?array
    {
        $pick = static function (int $wantedCategory) use ($rows, $headId, $sectionId, $onDate): ?array {
            $best = null;
            foreach ($rows as $row) {
                if ((int) $row['fee_head_id'] !== $headId
                    || (int) $row['fee_category_id'] !== $wantedCategory
                    || (int) ($row['is_active'] ?? 1) !== 1
                    || (string) $row['effective_from'] > $onDate) {
                    continue;
                }
                $rowSection = (int) $row['section_id'];
                if ($rowSection !== 0 && $rowSection !== $sectionId) {
                    continue;
                }
                if ($best === null) {
                    $best = $row;
                    continue;
                }
                $bestSpecific = (int) $best['section_id'] !== 0;
                $rowSpecific = $rowSection !== 0;
                if ($rowSpecific !== $bestSpecific) {
                    if ($rowSpecific) {
                        $best = $row;
                    }
                    continue;
                }
                if ((string) $row['effective_from'] > (string) $best['effective_from']) {
                    $best = $row;
                }
            }

            return $best;
        };

        $general = $pick($generalCategoryId);
        $own = $categoryId !== $generalCategoryId ? $pick($categoryId) : null;
        $applied = $own ?? $general;
        if ($applied === null) {
            return null;
        }
        $amount = Money::fromDb($applied['amount']);

        return [
            'amount' => $amount,
            'standard' => $general !== null ? Money::fromDb($general['amount']) : $amount,
            'category_id' => (int) $applied['fee_category_id'],
            'due_day' => (int) $applied['due_day'],
            'late_fine' => Money::fromDb($applied['late_fine_amount']),
            'grace_days' => (int) $applied['grace_days'],
        ];
    }

    private function assertClassSection(int $classId, int $sectionId): void
    {
        $class = \fetchRows($this->db, 'SELECT id FROM cls WHERE id = :id', ['id' => $classId]);
        if ($class === []) {
            throw new RuntimeException('শ্রেণী নির্বাচন করুন।');
        }
        if ($sectionId > 0) {
            $section = \fetchRows($this->db, 'SELECT id FROM sections WHERE id = :id AND class_id = :class', ['id' => $sectionId, 'class' => $classId]);
            if ($section === []) {
                throw new RuntimeException('শাখাটি এই শ্রেণীর নয়।');
            }
        }
    }

    public static function assertSession(string $sessionYear): void
    {
        if (preg_match('/^\d{4}$/', $sessionYear) !== 1) {
            throw new RuntimeException('সেশন সঠিক নয়।');
        }
    }

    public static function parseDate(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException($label . ' সঠিক নয়।');
        }

        return $date;
    }
}
