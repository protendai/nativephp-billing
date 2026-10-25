<?php

namespace Protendai\Billing\Testing;

use Native\Mobile\Testing\FakeBridge;
use Protendai\Billing\Billing;

/**
 * Billing test vocabulary, registered as FakeBridge macros so app tests read in billing terms:
 *
 *     Native::test(Shop::class)
 *         ->tap('buy-coins')
 *         ->assertPurchaseStarted(fn (array $p) => $p['productId'] === 'coins_100');
 *
 * Each filter receives the decoded bridge parameters (id, productId, type, ...).
 */
class BillingMacros
{
    public static function register(): void
    {
        /** Assert Billing::products() was called, optionally matching its params (productIds, type, id). */
        FakeBridge::macro('assertProductsRequested', function (?callable $filter = null) {
            return $this->assertCalled(Billing::PRODUCTS, $filter);
        });

        /** Assert Billing::subscribe(), buy() or purchase() opened the purchase sheet. */
        FakeBridge::macro('assertPurchaseStarted', function (?callable $filter = null) {
            return $this->assertCalled(Billing::PURCHASE, $filter);
        });

        FakeBridge::macro('assertNoPurchaseStarted', function () {
            return $this->assertNotCalled(Billing::PURCHASE);
        });

        /** Assert Billing::restore() asked Google Play for the account's purchases. */
        FakeBridge::macro('assertPurchasesRestoreRequested', function (?callable $filter = null) {
            return $this->assertCalled(Billing::RESTORE, $filter);
        });

        /** Assert Billing::acknowledge() was called, optionally for one purchase token. */
        FakeBridge::macro('assertPurchaseAcknowledged', function (?string $purchaseToken = null) {
            return $this->assertCalled(Billing::ACKNOWLEDGE, fn (array $p) => $purchaseToken === null || ($p['purchaseToken'] ?? null) === $purchaseToken);
        });

        /** Assert Billing::consume() was called, optionally for one purchase token. */
        FakeBridge::macro('assertPurchaseConsumed', function (?string $purchaseToken = null) {
            return $this->assertCalled(Billing::CONSUME, fn (array $p) => $purchaseToken === null || ($p['purchaseToken'] ?? null) === $purchaseToken);
        });
    }
}
