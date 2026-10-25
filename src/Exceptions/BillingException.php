<?php

namespace Protendai\Billing\Exceptions;

use RuntimeException;

/**
 * The device refused the request before starting it, so no event will follow.
 *
 * $errorCode is UNSUPPORTED on iOS (StoreKit is not implemented yet) and
 * INVALID_ARGUMENT for bad arguments (missing productId, unknown type...).
 */
class BillingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
