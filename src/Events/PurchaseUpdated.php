<?php

namespace Protendai\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The purchase sheet closed, or a purchase changed while the app was open
 * (a pending payment completing). $id is the one Billing::subscribe()/buy()
 * returned, or null for updates nobody asked for.
 *
 * $status: purchased, pending, cancelled, already_owned or failed.
 * $purchases: [{productId, productIds, type, purchaseToken, orderId, purchaseTime,
 *   state (purchased|pending|unspecified), pending, acknowledged, autoRenewing,
 *   quantity, accountId, profileId, packageName, originalJson, signature}].
 */
class PurchaseUpdated
{
    use Dispatchable;

    public const PURCHASED = 'purchased';

    public const PENDING = 'pending';

    public const CANCELLED = 'cancelled';

    public const ALREADY_OWNED = 'already_owned';

    public const FAILED = 'failed';

    /**
     * @param  array<int, array<string, mixed>>  $purchases
     * @param  string|null  $message  Readable reason, for cancelled / already_owned / failed
     * @param  string|null  $code  Stable failure code (see README)
     * @param  mixed  ...$extra  Keys added by newer native versions
     */
    public function __construct(
        public string $status,
        public array $purchases = [],
        public ?string $message = null,
        public ?string $code = null,
        public ?string $id = null,
        mixed ...$extra,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::PURCHASED;
    }
}
