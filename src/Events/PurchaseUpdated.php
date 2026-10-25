<?php

namespace Ftree\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The purchase sheet closed. $status is purchased, pending, cancelled or failed;
 * $purchases are [{productId, purchaseToken, acknowledged}].
 */
class PurchaseUpdated
{
    use Dispatchable;

    public function __construct(public string $status, public array $purchases = [], public ?string $message = null) {}
}
