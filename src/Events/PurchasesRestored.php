<?php

namespace Protendai\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * What the Google account owns now, answering Billing::restore(): active
 * subscriptions and unconsumed one-time products, with the same purchase
 * shape as PurchaseUpdated (including pending and unacknowledged ones).
 */
class PurchasesRestored
{
    use Dispatchable;

    /**
     * @param  array<int, array<string, mixed>>  $purchases
     * @param  mixed  ...$extra  Keys added by newer native versions
     */
    public function __construct(
        public array $purchases = [],
        public ?string $error = null,
        public ?string $code = null,
        public ?string $id = null,
        mixed ...$extra,
    ) {}

    /**
     * Purchases that are paid for but not acknowledged yet: verify these on
     * your server (or acknowledge/consume them) within three days.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unacknowledged(): array
    {
        return array_values(array_filter(
            $this->purchases,
            fn (array $purchase) => empty($purchase['acknowledged']) && empty($purchase['pending']),
        ));
    }
}
