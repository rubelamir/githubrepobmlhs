<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use RuntimeException;

/**
 * Gateway credentials. They are read only from the private config file outside
 * the web root (payment_gateways.<code>.*) or from environment variables
 * (BMLHS_<CODE>_APP_KEY, ...); nothing is stored in the database or the code.
 */
final class GatewayConfig
{
    public const BKASH_SANDBOX_URL = 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout';

    private const KEYS = ['base_url', 'app_key', 'app_secret', 'username', 'password'];

    /** @param array<string, string> $values */
    private function __construct(private string $gateway, private array $values)
    {
    }

    public static function for(string $gateway): self
    {
        $config = function_exists('deploymentPrivateConfig') ? \deploymentPrivateConfig() : [];
        $section = $config['payment_gateways'][$gateway] ?? [];
        $section = is_array($section) ? $section : [];
        $values = [];
        foreach (self::KEYS as $key) {
            $env = getenv('BMLHS_' . strtoupper($gateway) . '_' . strtoupper($key));
            $value = $env !== false && $env !== '' ? $env : ($section[$key] ?? '');
            $values[$key] = is_string($value) ? trim($value) : '';
        }
        if ($gateway === 'bkash' && $values['base_url'] === '') {
            $values['base_url'] = self::BKASH_SANDBOX_URL;
        }
        $values['base_url'] = rtrim($values['base_url'], '/');

        return new self($gateway, $values);
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    public function isComplete(): bool
    {
        foreach (self::KEYS as $key) {
            if ($this->values[$key] === '') {
                return false;
            }
        }

        return self::validBaseUrl($this->values['base_url']);
    }

    /** @return list<string> */
    public function missingKeys(): array
    {
        $missing = [];
        foreach (self::KEYS as $key) {
            if ($this->values[$key] === '' || ($key === 'base_url' && !self::validBaseUrl($this->values[$key]))) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function get(string $key): string
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new RuntimeException('Unknown gateway setting.');
        }

        return $this->values[$key];
    }

    public function isSandbox(): bool
    {
        return str_contains((string) parse_url($this->values['base_url'], PHP_URL_HOST), 'sandbox')
            || self::isLoopback($this->values['base_url']);
    }

    public function host(): string
    {
        return (string) parse_url($this->values['base_url'], PHP_URL_HOST);
    }

    /** HTTPS is required; plain HTTP is accepted only for a local test server. */
    public static function validBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return $scheme === 'https' || ($scheme === 'http' && self::isLoopback($url));
    }

    public static function isLoopback(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['127.0.0.1', 'localhost'], true);
    }
}
