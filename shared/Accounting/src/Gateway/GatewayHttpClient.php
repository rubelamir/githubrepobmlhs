<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use PDO;
use School\Accounting\Audit\AuditTrail;

/**
 * JSON-over-HTTPS client for gateways: 30 second timeout, certificate and host
 * verification always on, and every request/response written to
 * acc_gateway_logs with credentials, tokens and wallet numbers masked.
 */
final class GatewayHttpClient
{
    public const TIMEOUT_SECONDS = 30;

    private const SECRET_KEYS = [
        'app_key', 'app_secret', 'username', 'password', 'id_token', 'refresh_token',
        'authorization', 'x-app-key', 'token', 'pin', 'otp', 'signature', 'hash',
    ];
    private const WALLET_KEYS = ['payeraccount', 'payerreference', 'customermsisdn', 'msisdn', 'organizationaccount'];

    /** @var (callable(string, string, array, array): array{status: int, body: string, error: string})|null */
    private $transport;

    public function __construct(private PDO $db, private string $gateway, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @param array{txn?: int, refund?: int} $context
     * @return array{status: int, json: array<string, mixed>|null, error: string}
     */
    public function postJson(string $url, string $operation, array $headers, array $body, array $context = []): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers;
        $started = microtime(true);
        $raw = $this->send($url, $payload, $headers);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $json = null;
        if ($raw['body'] !== '') {
            $decoded = json_decode($raw['body'], true);
            $json = is_array($decoded) ? $decoded : null;
        }
        $error = $raw['error'];
        if ($error === '' && $json === null) {
            $error = 'Response is not JSON (HTTP ' . $raw['status'] . ')';
        }
        $this->log($operation, $url, $raw['status'], ['headers' => $headers, 'body' => $body], $json ?? ['raw' => mb_substr($raw['body'], 0, 500)], $error, $duration, $context);

        return ['status' => $raw['status'], 'json' => $json, 'error' => $error];
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function mask(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (is_array($value)) {
                $masked[$key] = self::mask($value);
            } elseif (in_array($lower, self::SECRET_KEYS, true)) {
                $masked[$key] = $value === null || $value === '' ? $value : '***';
            } elseif (in_array($lower, self::WALLET_KEYS, true)) {
                $masked[$key] = self::maskWallet((string) $value);
            } elseif (is_string($value) && preg_match('/^(https?:\/\/[^?]+)\?/i', $value, $match) === 1 && str_contains($value, 'hash=')) {
                $masked[$key] = $match[1] . '?***';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /** "01770618575" → "017*****575" */
    public static function maskWallet(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length <= 6) {
            return $length === 0 ? '' : str_repeat('*', $length);
        }

        return substr($value, 0, 3) . str_repeat('*', $length - 6) . substr($value, -3);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, error: string}
     */
    private function send(string $url, string $payload, array $headers): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $payload, $headers, []);
        }
        if (!GatewayConfig::validBaseUrl($url)) {
            return ['status' => 0, 'body' => '', 'error' => 'Gateway URL must use HTTPS'];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => GatewayConfig::isLoopback($url) ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? (curl_error($curl) ?: 'Request failed') : '';
        curl_close($curl);

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }

    /**
     * @param array<mixed> $request
     * @param array<mixed> $response
     * @param array{txn?: int, refund?: int} $context
     */
    private function log(string $operation, string $url, int $status, array $request, array $response, string $error, int $duration, array $context): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO acc_gateway_logs (gateway, gateway_txn_id, refund_id, operation, endpoint, http_status, request_masked, response_masked, error, duration_ms, created_at)
                 VALUES (:gateway, :txn, :refund, :operation, :endpoint, :status, :request, :response, :error, :duration, :now)'
            )->execute([
                'gateway' => $this->gateway,
                'txn' => ($context['txn'] ?? 0) > 0 ? $context['txn'] : null,
                'refund' => ($context['refund'] ?? 0) > 0 ? $context['refund'] : null,
                'operation' => mb_substr($operation, 0, 30),
                'endpoint' => mb_substr((string) parse_url($url, PHP_URL_PATH), 0, 160),
                'status' => $status,
                'request' => json_encode(self::mask($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response' => json_encode(self::mask($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error' => $error === '' ? null : mb_substr($error, 0, 255),
                'duration' => $duration,
                'now' => AuditTrail::now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            error_log('Gateway log write failed: ' . $exception->getMessage());
        }
    }
}
