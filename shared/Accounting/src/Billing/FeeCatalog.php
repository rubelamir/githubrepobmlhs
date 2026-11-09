<?php

declare(strict_types=1);

namespace School\Accounting\Billing;

use PDO;
use PDOException;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Access\AccessDeniedException;
use School\Accounting\Access\Capability;
use School\Accounting\Audit\AuditTrail;

/**
 * Fee heads (monthly tuition, admission, exam …) and fee categories
 * (general, stipend, scholarship …). Neither is ever deleted, only deactivated.
 */
final class FeeCatalog
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function heads(bool $activeOnly = false): array
    {
        return \fetchRows(
            $this->db,
            'SELECT * FROM acc_fee_heads' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id'
        );
    }

    /** @return array<string, mixed>|null */
    public function head(int $id): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_fee_heads WHERE id = :id', ['id' => $id]);

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function categories(bool $activeOnly = false): array
    {
        return \fetchRows(
            $this->db,
            'SELECT * FROM acc_fee_categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_default DESC, sort_order, id'
        );
    }

    /** @return array<string, mixed>|null */
    public function category(int $id): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_fee_categories WHERE id = :id', ['id' => $id]);

        return $rows[0] ?? null;
    }

    public function defaultCategoryId(): int
    {
        $id = (int) $this->db->query('SELECT id FROM acc_fee_categories WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id < 1) {
            throw new RuntimeException('সাধারণ (ডিফল্ট) ফি-শ্রেণী পাওয়া যায়নি।');
        }

        return $id;
    }

    /** @param array<string, mixed> $input */
    public function saveHead(AccessContext $actor, AuditTrail $audit, ?int $id, array $input): int
    {
        $this->assertCanManage($actor);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = self::text((string) ($input['name_bn'] ?? ''), 120);
        $kind = (string) ($input['billing_kind'] ?? '');
        if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $code) !== 1) {
            throw new RuntimeException('কোড ইংরেজি বড় হাতের অক্ষর, সংখ্যা বা _ দিয়ে ২–৩০ অক্ষরের হতে হবে (যেমন TUITION)।');
        }
        if ($name === '') {
            throw new RuntimeException('ফি হেডের নাম লিখুন।');
        }
        if (!isset(BillingPeriod::KIND_LABELS[$kind])) {
            throw new RuntimeException('বিলের ধরন নির্বাচন করুন।');
        }
        $data = [
            'code' => $code,
            'name_bn' => $name,
            'billing_kind' => $kind,
            'late_fine_allowed' => 0,
            'income_account_id' => $this->incomeAccountInput($input['income_account_id'] ?? null),
            'sort_order' => max(0, min(9999, (int) ($input['sort_order'] ?? 0))),
        ];

        return $this->save('acc_fee_heads', 'fee_head', $audit, $id, $data, 'ফি হেড');
    }

    /** @param array<string, mixed> $input */
    public function saveCategory(AccessContext $actor, AuditTrail $audit, ?int $id, array $input): int
    {
        $this->assertCanManage($actor);
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $name = self::text((string) ($input['name_bn'] ?? ''), 100);
        if (preg_match('/^[a-z][a-z0-9_]{1,29}$/', $code) !== 1) {
            throw new RuntimeException('কোড ইংরেজি ছোট হাতের অক্ষর, সংখ্যা বা _ দিয়ে ২–৩০ অক্ষরের হতে হবে (যেমন half_free)।');
        }
        if ($name === '') {
            throw new RuntimeException('ফি-শ্রেণীর নাম লিখুন।');
        }
        $data = [
            'code' => $code,
            'name_bn' => $name,
            'description' => self::text((string) ($input['description'] ?? ''), 255),
            'requires_reference' => !empty($input['requires_reference']) ? 1 : 0,
            'sort_order' => max(0, min(9999, (int) ($input['sort_order'] ?? 0))),
        ];
        if ($id !== null) {
            $current = $this->category($id);
            if ($current !== null && (int) $current['is_default'] === 1 && $code !== (string) $current['code']) {
                throw new RuntimeException('সাধারণ (ডিফল্ট) ফি-শ্রেণীর কোড পরিবর্তন করা যাবে না।');
            }
        }

        return $this->save('acc_fee_categories', 'fee_category', $audit, $id, $data, 'ফি-শ্রেণী');
    }

    public function setHeadActive(AccessContext $actor, AuditTrail $audit, int $id, bool $active): void
    {
        $this->assertCanManage($actor);
        $this->setActive('acc_fee_heads', 'fee_head', $audit, $id, $active);
    }

    public function setCategoryActive(AccessContext $actor, AuditTrail $audit, int $id, bool $active): void
    {
        $this->assertCanManage($actor);
        $category = $this->category($id);
        if ($category !== null && (int) $category['is_default'] === 1 && !$active) {
            throw new RuntimeException('সাধারণ (ডিফল্ট) ফি-শ্রেণী নিষ্ক্রিয় করা যাবে না।');
        }
        $this->setActive('acc_fee_categories', 'fee_category', $audit, $id, $active);
    }

    /** @param array<string, mixed> $data */
    private function save(string $table, string $entity, AuditTrail $audit, ?int $id, array $data, string $label): int
    {
        $now = AuditTrail::now()->format('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            if ($id === null) {
                $columns = array_keys($data);
                $this->db->prepare(
                    'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ', is_active, created_at, updated_at)
                     VALUES (:' . implode(', :', $columns) . ', 1, :created_at, :updated_at)'
                )->execute($data + ['created_at' => $now, 'updated_at' => $now]);
                $id = (int) $this->db->lastInsertId();
                $audit->record($entity . '.create', $entity, $id, null, $data, $label . ' যোগ: ' . $data['name_bn']);
            } else {
                $before = $this->lockRow($table, $id);
                $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($data)));
                $this->db->prepare('UPDATE ' . $table . ' SET ' . $assignments . ', updated_at = :updated_at WHERE id = :id')
                    ->execute($data + ['updated_at' => $now, 'id' => $id]);
                $audit->record($entity . '.update', $entity, $id, array_intersect_key($before, $data), $data, $label . ' সম্পাদনা: ' . $data['name_bn']);
            }
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('এই কোডটি আগেই ব্যবহৃত হয়েছে।');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $id;
    }

    private function setActive(string $table, string $entity, AuditTrail $audit, int $id, bool $active): void
    {
        $this->db->beginTransaction();
        try {
            $before = $this->lockRow($table, $id);
            $this->db->prepare('UPDATE ' . $table . ' SET is_active = :active, updated_at = :now WHERE id = :id')
                ->execute(['active' => $active ? 1 : 0, 'now' => AuditTrail::now()->format('Y-m-d H:i:s'), 'id' => $id]);
            $audit->record(
                $entity . ($active ? '.activate' : '.deactivate'),
                $entity,
                $id,
                ['is_active' => (int) $before['is_active']],
                ['is_active' => $active ? 1 : 0],
                (string) $before['name_bn'] . ($active ? ' সক্রিয়' : ' নিষ্ক্রিয়')
            );
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function lockRow(string $table, int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $table . ' WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('রেকর্ডটি পাওয়া যায়নি।');
        }

        return $row;
    }

    /** Leaf income account chosen for a fee head; empty keeps the default (other fees). */
    private function incomeAccountInput(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id === 0 || !\tableExists($this->db, 'acc_accounts')) {
            return null;
        }
        $rows = \fetchRows($this->db, "SELECT id FROM acc_accounts WHERE id = :id AND account_type = 'income' AND is_group = 0 AND is_active = 1", ['id' => $id]);
        if ($rows === []) {
            throw new RuntimeException('আয়ের হিসাব সঠিক নয়।');
        }

        return $id;
    }

    private function assertCanManage(AccessContext $actor): void
    {
        if (!$actor->can(Capability::FEE_STRUCTURE_MANAGE)) {
            throw AccessDeniedException::forCapability(Capability::FEE_STRUCTURE_MANAGE);
        }
    }

    public static function text(string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max, 'UTF-8');
    }
}
