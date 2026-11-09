<?php

declare(strict_types=1);

namespace School\Accounting\Access;

use RuntimeException;

final class AccessDeniedException extends RuntimeException
{
    public static function forCapability(string $capability): self
    {
        return new self('এই কাজের অনুমতি আপনার নেই: ' . Capability::label($capability) . '।');
    }
}
