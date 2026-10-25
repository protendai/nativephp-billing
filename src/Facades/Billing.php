<?php

namespace Protendai\Billing\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool available()
 * @method static string products(array $productIds, string $type = 'subs', ?string $id = null)
 * @method static string subscribe(string $productId, string $basePlanId, ?string $accountId = null, ?string $oldPurchaseToken = null, ?string $offerId = null, array $offerTags = [], ?string $replacementMode = null, ?string $profileId = null, ?string $id = null)
 * @method static string buy(string $productId, ?string $accountId = null, ?string $offerId = null, ?string $profileId = null, ?string $id = null)
 * @method static string purchase(string $productId, string $type = 'subs', array $options = [], ?string $id = null)
 * @method static string restore(?string $type = null, ?string $id = null)
 * @method static string acknowledge(string $purchaseToken, ?string $id = null)
 * @method static string consume(string $purchaseToken, ?string $id = null)
 *
 * @see \Protendai\Billing\Billing
 */
class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Protendai\Billing\Billing::class;
    }
}
