<?php

namespace Ftree\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Subscriptions the phone's Google account has: [{productId, purchaseToken, acknowledged, accountId}].
 */
class PurchasesRestored
{
    use Dispatchable;

    public function __construct(public array $purchases = [], public ?string $error = null) {}
}
