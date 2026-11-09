<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use RuntimeException;

/** AES-256-GCM for gateway tokens at rest, keyed from the app signing secret. */
final class TokenCipher
{
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Token encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /** '' when the value cannot be decrypted (e.g. the signing secret changed). */
    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return is_string($plain) ? $plain : '';
    }

    private static function key(): string
    {
        return hash('sha256', 'acc-gateway-token|' . \appSigningKey(), true);
    }
}
