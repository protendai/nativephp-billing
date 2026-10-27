<?php

namespace Protendai\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Answers Billing::consume(): $ok is true when the store accepted it (on iOS: the transaction is finished).
 */
class PurchaseConsumed
{
    use Dispatchable;

    /**
     * @param  mixed  ...$extra  Keys added by newer native versions
     */
    public function __construct(
        public string $purchaseToken,
        public bool $ok = false,
        public ?string $error = null,
        public ?string $code = null,
        public ?string $id = null,
        mixed ...$extra,
    ) {}
}
