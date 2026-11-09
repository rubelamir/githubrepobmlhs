<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

/**
 * One online payment provider. Implementations talk to the provider only;
 * recording money is done by OnlinePaymentService after a server-side
 * confirmation (execute or query), never from callback parameters.
 */
interface PaymentGateway
{
    public function code(): string;

    public function label(): string;

    /** True when the adapter is implemented and its credentials are configured. */
    public function isReady(): bool;

    /** @param int $amount paisa */
    public function createPayment(int $txnId, string $invoiceNo, int $amount, string $payerReference, string $callbackUrl): GatewayResult;

    public function executePayment(int $txnId, string $paymentRef): GatewayResult;

    public function queryPayment(int $txnId, string $paymentRef): GatewayResult;

    /** @param int $amount paisa */
    public function refund(int $refundId, string $paymentRef, string $trxId, int $amount, string $reason, string $sku): GatewayResult;

    /**
     * Completed refunds of one payment as reported by the gateway.
     *
     * @return list<array{trx_id: string, amount: int, completed: bool}>|null null when the gateway gave no usable answer
     */
    public function refundHistory(int $refundId, string $paymentRef, string $trxId): ?array;
}
