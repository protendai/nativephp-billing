<?php

namespace Ftree\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Products from Google Play: [{id, title, plans: [{basePlanId, price, period, micros, currency}]}].
 */
class ProductsLoaded
{
    use Dispatchable;

    public function __construct(public array $products = [], public ?string $error = null) {}
}
