<?php

namespace Protendai\Billing;

use Illuminate\Support\Str;
use Native\Mobile\Platform;
use Protendai\Billing\Exceptions\BillingException;

/**
 * In-app purchases for NativePHP Mobile: Google Play Billing on Android and
 * StoreKit 2 on iOS. Subscriptions and one-time products (consumable or not).
 *
 * Every method returns a request id straight away; the answer arrives later
 * as an event carrying the same id: ProductsLoaded, PurchaseUpdated,
 * PurchasesRestored, PurchaseAcknowledged or PurchaseConsumed.
 *
 * Purchases are never acknowledged automatically. Verify them on your server
 * and acknowledge them there (recommended), or call acknowledge() / consume().
 * Google refunds purchases that are not acknowledged within three days. On
 * iOS the app finishes transactions itself: call acknowledge() (or consume())
 * once your server has granted the purchase.
 */
class Billing
{
    /** Product type: auto-renewing subscriptions. */
    public const SUBS = 'subs';

    /** Product type: one-time products (consumable or non-consumable). */
    public const INAPP = 'inapp';

    /** Bridge function names, as declared in nativephp.json. */
    public const PRODUCTS = 'ProtendaiBilling.Products';

    public const PURCHASE = 'ProtendaiBilling.Purchase';

    public const RESTORE = 'ProtendaiBilling.Restore';

    public const ACKNOWLEDGE = 'ProtendaiBilling.Acknowledge';

    public const CONSUME = 'ProtendaiBilling.Consume';

    /** RFC 4122 namespace of the name-based iOS appAccountToken (see appAccountToken()). */
    public const ACCOUNT_TOKEN_NAMESPACE = 'c4cb9250-ea5d-49a3-aaca-c096eeb2f970';

    /** Subscription replacement modes for upgrades and downgrades (oldPurchaseToken). */
    public const REPLACEMENT_MODES = ['with_time_proration', 'charge_prorated_price', 'charge_full_price', 'without_proration', 'deferred'];

    /**
     * Whether purchases can be made here: an Android or iOS build with this
     * plugin. False outside the mobile runtime.
     */
    public function available(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        return ! function_exists('nativephp_can') || nativephp_can(self::PRODUCTS);
    }

    /**
     * The store this build sells through: 'ios' (App Store), 'android'
     * (Google Play), or null outside the mobile runtime. Use it to pick
     * product ids: on iOS each subscription period is its own product.
     */
    public function platform(): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        return $this->isIos() ? 'ios' : 'android';
    }

    /**
     * The appAccountToken iOS stores on a transaction bought with this
     * accountId: the id itself when it is a UUID, otherwise a name-based
     * (version 5) UUID. Your server matches App Store transactions to buyers
     * with it.
     */
    public static function appAccountToken(string $accountId): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $accountId)) {
            return strtolower($accountId);
        }

        $hash = sha1(hex2bin(str_replace('-', '', self::ACCOUNT_TOKEN_NAMESPACE)).$accountId);
        $bytes = str_split(substr($hash, 0, 32), 2);
        $bytes[6] = sprintf('%02x', (hexdec($bytes[6]) & 0x0F) | 0x50);
        $bytes[8] = sprintf('%02x', (hexdec($bytes[8]) & 0x3F) | 0x80);
        $hex = implode('', $bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /**
     * Load products from the store, with prices in the user's currency and,
     * for subscriptions, every base plan and offer this account may buy.
     * On iOS each subscription product has one plan, with its own id.
     *
     * @param  list<string>  $productIds
     * @param  string  $type  Billing::SUBS or Billing::INAPP
     * @return string The request id, echoed on ProductsLoaded
     */
    public function products(array $productIds, string $type = self::SUBS, ?string $id = null): string
    {
        return $this->call(self::PRODUCTS, [
            'productIds' => array_values(array_unique(array_map('strval', $productIds))),
            'type' => $type,
        ], $id);
    }

    /**
     * Open the store's purchase sheet for a subscription base plan.
     *
     * On iOS $basePlanId, the offer options, $oldPurchaseToken and
     * $replacementMode are ignored: buy the period's own product id, an
     * eligible introductory offer applies by itself, and products in one
     * subscription group replace each other.
     *
     * Without $offerId or $offerTags the base plan is bought at its regular
     * price. Pass an offer id (or tags) to apply a free trial or introductory
     * price; products() lists only the offers this account is eligible for.
     *
     * @param  string|null  $accountId  Your id for the buyer (max 64 chars, no personal data), returned on the purchase and to your server as obfuscatedExternalAccountId (Android) or appAccountToken (iOS, see appAccountToken())
     * @param  string|null  $oldPurchaseToken  The purchase token of the subscription this one replaces (upgrade/downgrade)
     * @param  list<string>  $offerTags  Pick the cheapest eligible offer carrying all of these tags
     * @param  string|null  $replacementMode  One of Billing::REPLACEMENT_MODES; default with_time_proration
     * @return string The request id, echoed on PurchaseUpdated
     */
    public function subscribe(
        string $productId,
        string $basePlanId,
        ?string $accountId = null,
        ?string $oldPurchaseToken = null,
        ?string $offerId = null,
        array $offerTags = [],
        ?string $replacementMode = null,
        ?string $profileId = null,
        ?string $id = null,
    ): string {
        return $this->purchase($productId, self::SUBS, [
            'basePlanId' => $basePlanId,
            'accountId' => $accountId,
            'oldPurchaseToken' => $oldPurchaseToken,
            'offerId' => $offerId,
            'offerTags' => $offerTags,
            'replacementMode' => $replacementMode,
            'profileId' => $profileId,
        ], $id);
    }

    /**
     * Open the store's purchase sheet for a one-time product.
     *
     * Afterwards, consume() consumables (so they can be bought again) and
     * acknowledge() everything else, unless your server does it.
     *
     * @param  string|null  $offerId  A one-time product offer or purchase option (Play Billing 8)
     * @return string The request id, echoed on PurchaseUpdated
     */
    public function buy(string $productId, ?string $accountId = null, ?string $offerId = null, ?string $profileId = null, ?string $id = null): string
    {
        return $this->purchase($productId, self::INAPP, [
            'accountId' => $accountId,
            'offerId' => $offerId,
            'profileId' => $profileId,
        ], $id);
    }

    /**
     * Open the purchase sheet with any option: basePlanId, offerId, offerTags,
     * accountId, profileId, oldPurchaseToken, replacementMode.
     *
     * @param  array<string, mixed>  $options
     */
    public function purchase(string $productId, string $type = self::SUBS, array $options = [], ?string $id = null): string
    {
        return $this->call(self::PURCHASE, ['productId' => $productId, 'type' => $type] + $options, $id);
    }

    /**
     * Ask the store what this account owns now: active subscriptions and
     * unconsumed one-time products, including pending and unacknowledged ones.
     * Call it when the app starts to finish purchases that were interrupted.
     *
     * @param  string|null  $type  Billing::SUBS, Billing::INAPP, or null for both
     * @param  bool  $sync  iOS: ask the App Store first (it may ask the user to sign in, so only from a Restore button). Ignored on Android.
     * @return string The request id, echoed on PurchasesRestored
     */
    public function restore(?string $type = null, ?string $id = null, bool $sync = false): string
    {
        return $this->call(self::RESTORE, ['type' => $type, 'sync' => $sync ?: null], $id);
    }

    /**
     * Acknowledge a purchase on the device, for apps without a server.
     * With a server, acknowledge there after verifying the purchase instead.
     * On iOS this finishes the transaction: call it once the purchase is
     * granted, server or not.
     *
     * @return string The request id, echoed on PurchaseAcknowledged
     */
    public function acknowledge(string $purchaseToken, ?string $id = null): string
    {
        return $this->call(self::ACKNOWLEDGE, ['purchaseToken' => $purchaseToken], $id);
    }

    /**
     * Consume a one-time purchase (consumables such as coins) so it can be
     * bought again. Consuming also acknowledges it. Grant the item first.
     *
     * @return string The request id, echoed on PurchaseConsumed
     */
    public function consume(string $purchaseToken, ?string $id = null): string
    {
        return $this->call(self::CONSUME, ['purchaseToken' => $purchaseToken], $id);
    }

    /**
     * @throws BillingException when the device refuses the request (bad arguments, a build without the plugin)
     */
    protected function call(string $method, array $parameters, ?string $id): string
    {
        $id ??= (string) Str::uuid();
        $parameters = array_filter(
            ['id' => $id] + $parameters,
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

        if (! function_exists('nativephp_call')) {
            return $id;
        }

        $response = json_decode((string) nativephp_call($method, json_encode((object) $parameters)), true);

        if (is_array($response) && ($response['status'] ?? null) === 'error') {
            throw new BillingException($response['message'] ?? 'Billing could not start', $response['code'] ?? null);
        }

        return $id;
    }

    protected function isIos(): bool
    {
        try {
            if (class_exists(Platform::class)) {
                return Platform::isIos();
            }

            return function_exists('isIos') && isIos();
        } catch (\Throwable) {
            return false;
        }
    }
}
