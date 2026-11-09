<?php

declare(strict_types=1);

namespace School\Accounting\Ledger;

use PDO;
use RuntimeException;
use School\Accounting\Access\AccessContext;
use School\Accounting\Audit\AuditTrail;

/**
 * Bills, receipts and bank slips attached to vouchers and deposits. Files are
 * stored outside the web root under random names; only PDF/JPG/PNG/WebP up to
 * 5 MB whose content matches the type are accepted. Attachments are evidence,
 * so they are never deleted.
 */
final class AttachmentStore
{
    public const DIRECTORY = 'acc_attachments';
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PER_OWNER = 10;
    public const OWNER_TYPES = ['voucher', 'deposit'];

    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $files one $_FILES entry (single or multiple)
     * @return int number stored
     */
    public function storeUploads(AccessContext $actor, AuditTrail $audit, string $ownerType, int $ownerId, array $files): int
    {
        if (!in_array($ownerType, self::OWNER_TYPES, true) || $ownerId < 1) {
            throw new RuntimeException('Invalid attachment owner.');
        }
        $normalized = self::normalize($files);
        if ($normalized === []) {
            return 0;
        }
        $existing = count($this->forOwner($ownerType, $ownerId));
        if ($existing + count($normalized) > self::MAX_PER_OWNER) {
            throw new RuntimeException('সর্বোচ্চ ' . bnDigits((string) self::MAX_PER_OWNER) . 'টি সংযুক্তি দেওয়া যায়।');
        }
        $validated = array_map(fn (array $file): array => $this->validate($file), $normalized);

        return $this->persist($actor, $audit, $ownerType, $ownerId, $validated);
    }

    /**
     * Checks uploads before the record they belong to is saved.
     *
     * @param array<string, mixed> $files
     */
    public function assertValid(array $files): void
    {
        $normalized = self::normalize($files);
        if (count($normalized) > self::MAX_PER_OWNER) {
            throw new RuntimeException('সর্বোচ্চ ' . bnDigits((string) self::MAX_PER_OWNER) . 'টি সংযুক্তি দেওয়া যায়।');
        }
        foreach ($normalized as $file) {
            $this->validate($file);
        }
    }

    /**
     * @param array<string, mixed> $files
     * @return list<array<string, mixed>>
     */
    private static function normalize(array $files): array
    {
        $normalized = [];
        if (is_array($files['name'] ?? null)) {
            foreach (array_keys($files['name']) as $index) {
                $normalized[] = [
                    'name' => $files['name'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ];
            }
        } elseif (isset($files['name'])) {
            $normalized[] = $files;
        }

        return array_values(array_filter($normalized, static fn (array $file): bool => (int) $file['error'] !== UPLOAD_ERR_NO_FILE));
    }

    /** @param list<array{tmp_name: string, original: string, mime: string, size: int, sha256: string}> $validated */
    private function persist(AccessContext $actor, AuditTrail $audit, string $ownerType, int $ownerId, array $validated): int
    {
        $directory = \privateMediaDirectoryPath(self::DIRECTORY);
        $stored = 0;
        foreach ($validated as $file) {
            $storedName = bin2hex(random_bytes(16)) . '.' . self::MIME_EXTENSIONS[$file['mime']];
            $target = $directory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $target)) {
                throw new RuntimeException('ফাইল সংরক্ষণ করা যায়নি।');
            }
            @chmod($target, 0600);
            $this->db->prepare(
                'INSERT INTO acc_attachments (owner_type, owner_id, stored_name, original_name, mime, size_bytes, sha256, uploaded_by, uploaded_at)
                 VALUES (:type, :owner, :stored, :original, :mime, :size, :sha, :actor, :now)'
            )->execute([
                'type' => $ownerType, 'owner' => $ownerId, 'stored' => $storedName, 'original' => $file['original'], 'mime' => $file['mime'],
                'size' => $file['size'], 'sha' => $file['sha256'], 'actor' => $actor->teacherId(), 'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
            ]);
            $audit->record('attachment.upload', $ownerType, $ownerId, null, ['file' => $file['original'], 'mime' => $file['mime'], 'size' => $file['size'], 'sha256' => $file['sha256']], 'সংযুক্তি: ' . $file['original']);
            $stored++;
        }

        return $stored;
    }

    /** @return list<array<string, mixed>> */
    public function forOwner(string $ownerType, int $ownerId): array
    {
        return \fetchRows(
            $this->db,
            'SELECT a.*, t.teacher_name AS uploaded_by_name FROM acc_attachments a LEFT JOIN teacher_info t ON t.id = a.uploaded_by
             WHERE a.owner_type = :type AND a.owner_id = :owner ORDER BY a.id',
            ['type' => $ownerType, 'owner' => $ownerId]
        );
    }

    /** @return array<string, mixed>|null */
    public function attachment(int $id): ?array
    {
        $rows = \fetchRows($this->db, 'SELECT * FROM acc_attachments WHERE id = :id', ['id' => $id]);

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $attachment */
    public function path(array $attachment): ?string
    {
        return \privateMediaFilePath(self::DIRECTORY, (string) $attachment['stored_name']);
    }

    /**
     * @param array<string, mixed> $file
     * @return array{tmp_name: string, original: string, mime: string, size: int, sha256: string}
     */
    private function validate(array $file): array
    {
        $original = mb_substr(basename(str_replace(["\0", '\\'], ['', '/'], (string) $file['name'])), 0, 150);
        $label = $original !== '' ? '"' . $original . '"' : 'ফাইল';
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException($label . ' ৫ MB-এর বেশি।');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException($label . ' আপলোড হয়নি; আবার চেষ্টা করুন।');
        }
        $size = (int) filesize((string) $file['tmp_name']);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException($label . ' ৫ MB-এর বেশি বা খালি।');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!isset(self::MIME_EXTENSIONS[$mime])) {
            throw new RuntimeException($label . ': শুধু PDF, JPG, PNG বা WebP ফাইল দেওয়া যায়।');
        }
        if ($mime === 'application/pdf') {
            $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw new RuntimeException($label . ' সঠিক PDF নয়।');
            }
        } elseif (@getimagesize((string) $file['tmp_name']) === false) {
            throw new RuntimeException($label . ' সঠিক ছবি নয়।');
        }
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf' => ['pdf'], 'jpg' => ['jpg', 'jpeg'], 'png' => ['png'], 'webp' => ['webp']][self::MIME_EXTENSIONS[$mime]];
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException($label . ': ফাইলের নাম ও ধরন মেলেনি।');
        }

        return [
            'tmp_name' => (string) $file['tmp_name'],
            'original' => $original,
            'mime' => $mime,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', (string) $file['tmp_name']),
        ];
    }
}
