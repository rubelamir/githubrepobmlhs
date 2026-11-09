<?php

declare(strict_types=1);

namespace School\Accounting\Audit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use School\Accounting\Access\AccessContext;

/**
 * Append-only accounting audit trail with before/after snapshots.
 * There is intentionally no update or delete method.
 */
final class AuditTrail
{
    public const TIMEZONE = 'Asia/Dhaka';

    private const SENSITIVE_KEYS = [
        'app_key',
        'app_secret',
        'username',
        'password',
        'id_token',
        'refresh_token',
        'authorization',
        'x-app-key',
        'pin',
        'otp',
    ];

    public function __construct(private PDO $db, private AccessContext $actor)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        string $note = ''
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO acc_audit_log
                (actor_id, actor_name, actor_role, action, entity_type, entity_id,
                 before_json, after_json, note, ip_address, created_at)
             VALUES
                (:actor_id, :actor_name, :actor_role, :action, :entity_type, :entity_id,
                 :before_json, :after_json, :note, :ip_address, :created_at)'
        );
        $statement->execute([
            'actor_id' => $this->actor->teacherId() > 0 ? $this->actor->teacherId() : null,
            'actor_name' => self::truncate($this->actor->teacherName(), 120),
            'actor_role' => (string) $this->actor->primaryRole(),
            'action' => self::truncate($action, 60),
            'entity_type' => self::truncate($entityType, 60),
            'entity_id' => $entityId !== null && $entityId > 0 ? $entityId : null,
            'before_json' => $before === null ? null : self::encode(self::mask($before)),
            'after_json' => $after === null ? null : self::encode(self::mask($after)),
            'note' => $note === '' ? null : self::truncate($note, 255),
            'ip_address' => self::clientIp(),
            'created_at' => self::now()->format('Y-m-d H:i:s'),
        ]);

        if (function_exists('writeAuditLog')) {
            \writeAuditLog($this->db, $action, 'acc:' . $entityType, $entityId, $note !== '' ? $note : $action);
        }
    }

    /**
     * Replaces credential-like values recursively so they never reach the log.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value);
            }
        }

        return $data;
    }

    /**
     * Keys whose value differs between two snapshots (for highlighting in the viewer).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    public static function changedKeys(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after) || $before[$key] !== $after[$key]) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, self::SENSITIVE_KEYS, true)
            || str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_ends_with($key, '_token');
    }

    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length, 'UTF-8');
    }

    private static function clientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
