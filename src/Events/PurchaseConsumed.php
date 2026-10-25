<?php

namespace Protendai\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Answers Billing::consume(): $ok is true when Google Play accepted it.
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
