<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

/**
 * Adapter slot for Nagad. The school does not yet have Nagad's official
 * merchant API documentation or sandbox access, so no request is sent: every
 * call reports "not available" and the gateway cannot be enabled. Implement
 * the methods from the official documentation when it is provided.
 */
final class NagadGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'nagad';
    }

    public function label(): string
    {
        return 'Nagad';
    }

    public function isReady(): bool
    {
        return false;
    }

    public function createPayment(int $txnId, string $invoiceNo, int $amount, string $payerReference, string $callbackUrl): GatewayResult
    {
        return self::unavailable();
    }

    public function executePayment(int $txnId, string $paymentRef): GatewayResult
    {
        return self::unavailable();
    }

    public function queryPayment(int $txnId, string $paymentRef): GatewayResult
    {
        return self::unavailable();
    }

    public function refund(int $refundId, string $paymentRef, string $trxId, int $amount, string $reason, string $sku): GatewayResult
    {
        return self::unavailable();
    }

    public function refundHistory(int $refundId, string $paymentRef, string $trxId): ?array
    {
        return null;
    }

    private static function unavailable(): GatewayResult
    {
        return GatewayResult::error('not_implemented', 'Nagad এখনো সক্রিয় নয় (অফিসিয়াল ডকুমেন্টেশন ও sandbox অ্যাক্সেস পাওয়ার পর যুক্ত হবে)।');
    }
}
