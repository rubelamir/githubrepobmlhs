<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

use PDO;
use RuntimeException;
use School\Accounting\Audit\AuditTrail;
use School\Accounting\Support\Money;

/**
 * bKash Tokenized Checkout (without agreement), API v2, as documented at
 * developer.bka.sh: grant/refresh token, create, execute, query payment,
 * refund and refund status. Endpoint paths and field names follow that
 * documentation; the base URL (sandbox or live) comes from GatewayConfig.
 *
 * Token rules from the documentation: the id_token lives 3600 s and is reused
 * until shortly before expiry; Grant/Refresh may be called at most twice per
 * hour, so tokens are cached (encrypted) in acc_gateway_tokens and the calls
 * are counted.
 */
final class BkashGateway implements PaymentGateway
{
    private const TOKEN_CALLS_PER_HOUR = 2;
    private const TOKEN_EARLY_REFRESH_SECONDS = 300;
    private const ALREADY_COMPLETED_CODES = ['2062', '2068', '2117'];

    private ?GatewayHttpClient $http = null;

    public function __construct(private PDO $db, private GatewayConfig $config, ?GatewayHttpClient $http = null)
    {
        $this->http = $http;
    }

    public function code(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
    }

    public function isReady(): bool
    {
        return $this->config->isComplete();
    }

    public function createPayment(int $txnId, string $invoiceNo, int $amount, string $payerReference, string $callbackUrl): GatewayResult
    {
        $response = $this->call('/payment/create', 'create', [
            'payerReference' => self::safeText($payerReference, 255),
            'callbackURL' => $callbackUrl,
            'amount' => Money::toDb($amount),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => self::safeText($invoiceNo, 255),
        ], ['txn' => $txnId]);
        if ($response instanceof GatewayResult) {
            return $response;
        }
        $paymentId = (string) ($response['paymentId'] ?? $response['paymentID'] ?? '');
        $url = (string) ($response['bkashURL'] ?? $response['bKashURL'] ?? '');
        if ($paymentId === '' || $url === '' || !GatewayConfig::validBaseUrl($url)) {
            return $this->errorFrom($response) ?? GatewayResult::unknown('Create Payment response has no paymentId/bkashURL.');
        }

        return new GatewayResult(GatewayResult::CREATED, $response, '', '', $paymentId, '', $url, self::amount($response['amount'] ?? null));
    }

    public function executePayment(int $txnId, string $paymentRef): GatewayResult
    {
        $response = $this->call('/payment/execute', 'execute', ['paymentId' => $paymentRef], ['txn' => $txnId]);

        return $response instanceof GatewayResult ? $response : $this->paymentState($response, 'merchantInvoiceNumber');
    }

    public function queryPayment(int $txnId, string $paymentRef): GatewayResult
    {
        $response = $this->call('/query/payment', 'query', ['paymentId' => $paymentRef], ['txn' => $txnId]);

        return $response instanceof GatewayResult ? $response : $this->paymentState($response, 'merchantInvoice');
    }

    public function refund(int $refundId, string $paymentRef, string $trxId, int $amount, string $reason, string $sku): GatewayResult
    {
        $response = $this->call('/refund/payment/transaction', 'refund', [
            'paymentId' => $paymentRef,
            'refundAmount' => Money::toDb($amount),
            'trxId' => $trxId,
            'reason' => self::safeText($reason, 255),
            'sku' => self::safeText($sku, 255),
        ], ['refund' => $refundId]);
        if ($response instanceof GatewayResult) {
            return $response;
        }
        if (($response['refundTransactionStatus'] ?? '') === 'Completed' && ($response['refundTrxId'] ?? '') !== '') {
            return new GatewayResult(GatewayResult::REFUNDED, $response, '', '', $paymentRef, (string) $response['refundTrxId'], '', self::amount($response['refundAmount'] ?? null));
        }

        return $this->errorFrom($response) ?? GatewayResult::unknown('Refund response has no completed refund.');
    }

    public function refundHistory(int $refundId, string $paymentRef, string $trxId): ?array
    {
        $response = $this->call('/refund/payment/status', 'refund_status', ['paymentId' => $paymentRef, 'trxId' => $trxId], ['refund' => $refundId]);
        if ($response instanceof GatewayResult || !isset($response['refundTransactions']) || !is_array($response['refundTransactions'])) {
            return null;
        }
        $history = [];
        foreach ($response['refundTransactions'] as $row) {
            if (!is_array($row) || ($row['refundTrxId'] ?? '') === '') {
                continue;
            }
            $history[] = [
                'trx_id' => (string) $row['refundTrxId'],
                'amount' => self::amount($row['refundAmount'] ?? null) ?? 0,
                'completed' => ($row['refundTransactionStatus'] ?? '') === 'Completed',
            ];
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $body
     * @param array{txn?: int, refund?: int} $context
     * @return array<string, mixed>|GatewayResult decoded body, or a result when the call failed
     */
    private function call(string $path, string $operation, array $body, array $context): array|GatewayResult
    {
        if (!$this->isReady()) {
            return GatewayResult::error('not_configured', 'bKash-এর ক্রেডেনশিয়াল কনফিগার করা হয়নি।');
        }
        try {
            $token = $this->token();
        } catch (RuntimeException $exception) {
            return GatewayResult::error('token', $exception->getMessage());
        }
        $response = $this->client()->postJson(
            $this->config->get('base_url') . $path,
            $operation,
            ['Authorization' => $token, 'X-App-Key' => $this->config->get('app_key')],
            $body,
            $context
        );
        if ($response['json'] === null) {
            return GatewayResult::unknown($response['error'] !== '' ? $response['error'] : 'No response from bKash.');
        }

        return $response['json'];
    }

    /** @param array<string, mixed> $response */
    private function paymentState(array $response, string $invoiceKey): GatewayResult
    {
        $status = (string) ($response['transactionStatus'] ?? '');
        $paymentId = (string) ($response['paymentId'] ?? $response['paymentID'] ?? '');
        $trxId = (string) ($response['trxId'] ?? '');
        $amount = self::amount($response['amount'] ?? null);
        $invoice = (string) ($response[$invoiceKey] ?? $response['merchantInvoiceNumber'] ?? $response['merchantInvoice'] ?? '');
        $payer = (string) ($response['payerAccount'] ?? '');
        $verified = ($response['verificationStatus'] ?? '') === 'Complete';
        if ($status === 'Completed' && $trxId !== '') {
            return new GatewayResult(GatewayResult::COMPLETED, $response, '', '', $paymentId, $trxId, '', $amount, $invoice, $payer, true);
        }
        if ($status !== '') {
            return new GatewayResult(GatewayResult::INITIATED, $response, '', $status, $paymentId, '', '', $amount, $invoice, $payer, $verified);
        }
        $error = $this->errorFrom($response);
        if ($error !== null && in_array($error->errorCode, self::ALREADY_COMPLETED_CODES, true)) {
            return GatewayResult::unknown($error->message);
        }

        return $error ?? GatewayResult::unknown('Unrecognised bKash response.');
    }

    /** @param array<string, mixed> $response */
    private function errorFrom(array $response): ?GatewayResult
    {
        $code = (string) ($response['externalCode'] ?? $response['statusCode'] ?? $response['errorCode'] ?? '');
        if ($code === '' || $code === '0000') {
            return null;
        }
        $message = (string) ($response['errorMessageEn'] ?? $response['statusMessage'] ?? $response['errorMessage'] ?? 'bKash error');

        return GatewayResult::error($code, mb_substr(trim($message), 0, 200), $response);
    }

    /** Cached id_token; refreshes or grants a new one only when needed (max twice per hour). */
    private function token(): string
    {
        $lock = $this->db->query("SELECT GET_LOCK('acc_bkash_token', 15)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new RuntimeException('bKash টোকেন এখন ব্যস্ত; একটু পরে চেষ্টা করুন।');
        }
        try {
            $row = \fetchRows($this->db, "SELECT * FROM acc_gateway_tokens WHERE gateway = 'bkash'")[0] ?? null;
            $now = AuditTrail::now();
            $nowText = $now->format('Y-m-d H:i:s');
            if ($row !== null && $row['id_token'] !== null && (string) $row['expires_at'] > $nowText) {
                $token = TokenCipher::decrypt((string) $row['id_token']);
                if ($token !== '') {
                    return $token;
                }
            }
            $calls = array_values(array_filter(
                json_decode((string) ($row['calls_json'] ?? '[]'), true) ?: [],
                static fn (mixed $time): bool => is_int($time) && $time > time() - 3600
            ));
            if (count($calls) >= self::TOKEN_CALLS_PER_HOUR) {
                throw new RuntimeException('bKash টোকেনের ঘণ্টাপ্রতি সীমা পূর্ণ হয়েছে; কিছুক্ষণ পর চেষ্টা করুন।');
            }
            $refresh = $row !== null && $row['refresh_token'] !== null && (string) $row['refresh_expires_at'] > $nowText
                ? TokenCipher::decrypt((string) $row['refresh_token'])
                : '';
            $body = ['app_key' => $this->config->get('app_key'), 'app_secret' => $this->config->get('app_secret')];
            if ($refresh !== '') {
                $body['refresh_token'] = $refresh;
            }
            $calls[] = time();
            $this->saveCalls($calls);
            $response = $this->client()->postJson(
                $this->config->get('base_url') . ($refresh !== '' ? '/auth/refresh-token' : '/auth/grant-token'),
                $refresh !== '' ? 'refresh_token' : 'grant_token',
                ['username' => $this->config->get('username'), 'password' => $this->config->get('password')],
                $body
            );
            $json = $response['json'] ?? [];
            $idToken = (string) ($json['id_token'] ?? '');
            if ($idToken === '') {
                $message = (string) ($json['statusMessage'] ?? $json['errorMessageEn'] ?? $response['error']);
                throw new RuntimeException('bKash টোকেন পাওয়া যায়নি' . ($message !== '' ? ': ' . mb_substr($message, 0, 120) : '') . '।');
            }
            $lifetime = max(600, (int) ($json['expires_in'] ?? 3600));
            $newRefresh = (string) ($json['refresh_token'] ?? $refresh);
            $this->db->prepare(
                "INSERT INTO acc_gateway_tokens (gateway, id_token, refresh_token, expires_at, refresh_expires_at, calls_json, updated_at)
                 VALUES ('bkash', :token, :refresh, :expires, :refresh_expires, :calls, :now)
                 ON DUPLICATE KEY UPDATE id_token = VALUES(id_token), refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at),
                     refresh_expires_at = VALUES(refresh_expires_at), calls_json = VALUES(calls_json), updated_at = VALUES(updated_at)"
            )->execute([
                'token' => TokenCipher::encrypt($idToken),
                'refresh' => $newRefresh !== '' ? TokenCipher::encrypt($newRefresh) : null,
                'expires' => $now->modify('+' . ($lifetime - self::TOKEN_EARLY_REFRESH_SECONDS) . ' seconds')->format('Y-m-d H:i:s'),
                'refresh_expires' => $now->modify('+29 days')->format('Y-m-d H:i:s'),
                'calls' => json_encode($calls),
                'now' => $nowText,
            ]);

            return $idToken;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('acc_bkash_token')");
        }
    }

    /** @param list<int> $calls */
    private function saveCalls(array $calls): void
    {
        $this->db->prepare(
            "INSERT INTO acc_gateway_tokens (gateway, calls_json, updated_at) VALUES ('bkash', :calls, :now)
             ON DUPLICATE KEY UPDATE calls_json = VALUES(calls_json), updated_at = VALUES(updated_at)"
        )->execute(['calls' => json_encode($calls), 'now' => AuditTrail::now()->format('Y-m-d H:i:s')]);
    }

    private function client(): GatewayHttpClient
    {
        return $this->http ??= new GatewayHttpClient($this->db, 'bkash');
    }

    /** bKash rejects <, > and & in reference fields. */
    private static function safeText(string $value, int $max): string
    {
        return mb_substr(trim(str_replace(['<', '>', '&'], ' ', $value)), 0, $max);
    }

    private static function amount(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        try {
            return Money::fromDb(is_float($value) ? number_format($value, 2, '.', '') : (string) $value);
        } catch (RuntimeException) {
            return null;
        }
    }
}
