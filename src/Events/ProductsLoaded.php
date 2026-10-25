<?php

namespace Protendai\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Products from Google Play, answering Billing::products().
 *
 * Each product: {id, type, title, name, description,
 *   plans: [{basePlanId, price, micros, currency, period, offerToken}],      (subscriptions: base plans at their regular price)
 *   offers: [{basePlanId, offerId, offerTags, offerToken, freeTrial, introductory,
 *             phases: [{price, micros, currency, period, cycles, recurrence}]}],  (subscriptions: base plans and eligible offers)
 *   price, micros, currency,                                                  (one-time products)
 *   oneTimeOffers: [{offerId, offerTags, offerToken, price, micros, currency}]}  (one-time products, Play Billing 8 options/offers)
 *
 * Prices are formatted for the user's Play country ("ZAR 109.99"); micros are
 * the amount in millionths. Periods are ISO 8601 ("P1M", "P1Y", "P1W").
 */
class ProductsLoaded
{
    use Dispatchable;

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  list<string>  $unfetched  Ids Google Play didn't return (wrong id, not active, wrong type)
     * @param  string|null  $error  Readable reason when the request failed
     * @param  string|null  $code  Stable failure code (see README)
     * @param  mixed  ...$extra  Keys added by newer native versions
     */
    public function __construct(
        public array $products = [],
        public ?string $error = null,
        public ?string $code = null,
        public ?string $id = null,
        public ?string $type = null,
        public array $unfetched = [],
        mixed ...$extra,
    ) {}
}
