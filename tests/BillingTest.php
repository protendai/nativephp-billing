<?php

use Protendai\Billing\Billing;
use Protendai\Billing\Exceptions\BillingException;
use Protendai\Billing\Facades\Billing as BillingFacade;
use Native\Mobile\Platform;

function lastCall($bridge): array
{
    return end($bridge->calls);
}

describe('available()', function () {
    it('is true in an Android build with the plugin', function () {
        expect(app(Billing::class)->available())->toBeTrue();
    });

    it('is false on iOS, where StoreKit is not implemented yet', function () {
        Platform::set(Platform::IOS);

        expect(app(Billing::class)->available())->toBeFalse();
    });

    it('is false when the app was built without the plugin', function () {
        $this->bridge->withoutCapability(Billing::PRODUCTS);

        expect(app(Billing::class)->available())->toBeFalse();
    });
});

describe('argument shaping', function () {
    it('loads subscription products by default, with a request id', function () {
        $id = BillingFacade::products(['premium', 'premium', 'standard']);

        expect(lastCall($this->bridge))->toBe([
            'method' => 'ProtendaiBilling.Products',
            'params' => ['id' => $id, 'productIds' => ['premium', 'standard'], 'type' => 'subs'],
        ])->and($id)->toBeString()->not->toBeEmpty();
    });

    it('loads one-time products and keeps a caller id', function () {
        expect(BillingFacade::products(['coins_100'], Billing::INAPP, 'shop'))->toBe('shop')
            ->and(lastCall($this->bridge)['params'])->toBe(['id' => 'shop', 'productIds' => ['coins_100'], 'type' => 'inapp']);
    });

    it('subscribes to a base plan, leaving out options that are not set', function () {
        BillingFacade::subscribe('premium', 'monthly', 'account-1', id: 'buy');

        expect(lastCall($this->bridge))->toBe([
            'method' => 'ProtendaiBilling.Purchase',
            'params' => ['id' => 'buy', 'productId' => 'premium', 'type' => 'subs', 'basePlanId' => 'monthly', 'accountId' => 'account-1'],
        ]);
    });

    it('passes offers, tags and replacement for upgrades', function () {
        BillingFacade::subscribe('premium', 'yearly', 'acc', 'old-token', 'trial-7d', ['intro'], 'charge_prorated_price', 'profile', 'up');

        expect(lastCall($this->bridge)['params'])->toBe([
            'id' => 'up', 'productId' => 'premium', 'type' => 'subs', 'basePlanId' => 'yearly', 'accountId' => 'acc',
            'oldPurchaseToken' => 'old-token', 'offerId' => 'trial-7d', 'offerTags' => ['intro'],
            'replacementMode' => 'charge_prorated_price', 'profileId' => 'profile',
        ]);
    });

    it('buys a one-time product', function () {
        BillingFacade::buy('coins_100', 'acc', id: 'coins');

        expect(lastCall($this->bridge)['params'])->toBe(['id' => 'coins', 'productId' => 'coins_100', 'type' => 'inapp', 'accountId' => 'acc']);
    });

    it('restores both product types unless one is named', function () {
        BillingFacade::restore(id: 'r1');
        expect(lastCall($this->bridge)['params'])->toBe(['id' => 'r1']);

        BillingFacade::restore(Billing::SUBS, 'r2');
        expect(lastCall($this->bridge)['params'])->toBe(['id' => 'r2', 'type' => 'subs']);
    });

    it('acknowledges and consumes by purchase token', function () {
        BillingFacade::acknowledge('tok-1', 'a');
        expect(lastCall($this->bridge))->toBe(['method' => 'ProtendaiBilling.Acknowledge', 'params' => ['id' => 'a', 'purchaseToken' => 'tok-1']]);

        BillingFacade::consume('tok-2', 'c');
        expect(lastCall($this->bridge))->toBe(['method' => 'ProtendaiBilling.Consume', 'params' => ['id' => 'c', 'purchaseToken' => 'tok-2']]);
    });
});

describe('results', function () {
    it('throws when the device refuses, with the bridge error code', function () {
        $this->bridge->respondTo('ProtendaiBilling.Purchase', ['status' => 'error', 'code' => 'UNSUPPORTED', 'message' => 'Android only']);

        try {
            BillingFacade::buy('coins_100');
            $this->fail('No exception');
        } catch (BillingException $e) {
            expect($e->errorCode)->toBe('UNSUPPORTED')->and($e->getMessage())->toBe('Android only');
        }
    });

    it('returns the id when the request started', function () {
        $this->bridge->respondTo('ProtendaiBilling.Restore', ['id' => 'x', 'status' => 'started']);

        expect(BillingFacade::restore(id: 'x'))->toBe('x');
    });
});

describe('test macros', function () {
    it('reads in billing terms', function () {
        BillingFacade::products(['premium']);
        BillingFacade::subscribe('premium', 'monthly');
        BillingFacade::restore();
        BillingFacade::acknowledge('tok-1');
        BillingFacade::consume('tok-2');

        $this->bridge->assertProductsRequested(fn (array $p) => $p['productIds'] === ['premium'])
            ->assertPurchaseStarted(fn (array $p) => $p['basePlanId'] === 'monthly')
            ->assertPurchasesRestoreRequested()
            ->assertPurchaseAcknowledged('tok-1')
            ->assertPurchaseConsumed('tok-2');
    });
});
