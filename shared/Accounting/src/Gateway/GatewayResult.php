<?php

declare(strict_types=1);

namespace School\Accounting\Gateway;

/**
 * Normalised outcome of one gateway call.
 *
 *   completed  the gateway confirms the money was taken (trx id present)
 *   initiated  the payment exists but is not completed (not verified, cancelled or failed)
 *   created    a payment was created; redirect the payer to $redirectUrl
 *   refunded   a refund completed
 *   error      the gateway answered with an error code
 *   unknown    no usable answer (timeout, network, malformed body): state must be queried again
 */
final class GatewayResult
{
    public const COMPLETED = 'completed';
    public const INITIATED = 'initiated';
    public const CREATED = 'created';
    public const REFUNDED = 'refunded';
    public const ERROR = 'error';
    public const UNKNOWN = 'unknown';

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $state,
        public readonly array $data = [],
        public readonly string $errorCode = '',
        public readonly string $message = '',
        public readonly string $paymentRef = '',
        public readonly string $trxId = '',
        public readonly string $redirectUrl = '',
        public readonly ?int $amount = null,
        public readonly string $invoiceNo = '',
        public readonly string $payerAccount = '',
        public readonly bool $verified = false
    ) {
    }

    public static function unknown(string $message): self
    {
        return new self(self::UNKNOWN, [], '', $message);
    }

    public static function error(string $code, string $message, array $data = []): self
    {
        return new self(self::ERROR, $data, $code, $message);
    }

    public function is(string $state): bool
    {
        return $this->state === $state;
    }
}
