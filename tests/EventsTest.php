<?php

use Protendai\Billing\Events\ProductsLoaded;
use Protendai\Billing\Events\PurchaseAcknowledged;
use Protendai\Billing\Events\PurchaseConsumed;
use Protendai\Billing\Events\PurchasesRestored;
use Protendai\Billing\Events\PurchaseUpdated;

/** A purchase exactly as BillingFunctions.kt describes it. */
function purchasePayload(array $overrides = []): array
{
    return $overrides + [
        'productId' => 'premium', 'productIds' => ['premium'], 'type' => 'subs', 'purchaseToken' => 'tok-1',
        'orderId' => 'GPA.1234-5678-9012-34567', 'purchaseTime' => 1761400000000, 'state' => 'purchased',
        'pending' => false, 'acknowledged' => false, 'autoRenewing' => true, 'quantity' => 1,
        'accountId' => 'account-1', 'profileId' => '', 'packageName' => 'com.example.app',
        'originalJson' => '{"orderId":"GPA.1234-5678-9012-34567"}', 'signature' => 'c2lnbmF0dXJl',
    ];
}

// The core builds events with new $class(...$payload), so payload keys bind by name.
it('builds ProductsLoaded from a subscription payload', function () {
    $event = new ProductsLoaded(...[
        'id' => 'p1', 'type' => 'subs', 'unfetched' => ['old_sku'],
        'products' => [[
            'id' => 'premium', 'type' => 'subs', 'title' => 'Premium (App)', 'name' => 'Premium', 'description' => '',
            'plans' => [['basePlanId' => 'monthly', 'price' => '$5.99', 'micros' => 5990000, 'currency' => 'USD', 'period' => 'P1M', 'offerToken' => 'ot-1']],
            'offers' => [
                ['basePlanId' => 'monthly', 'offerId' => null, 'offerTags' => [], 'offerToken' => 'ot-1', 'freeTrial' => false, 'introductory' => false,
                    'phases' => [['price' => '$5.99', 'micros' => 5990000, 'currency' => 'USD', 'period' => 'P1M', 'cycles' => 0, 'recurrence' => 'infinite']]],
                ['basePlanId' => 'monthly', 'offerId' => 'trial-7d', 'offerTags' => ['intro'], 'offerToken' => 'ot-2', 'freeTrial' => true, 'introductory' => false,
                    'phases' => [['price' => 'Free', 'micros' => 0, 'currency' => 'USD', 'period' => 'P1W', 'cycles' => 1, 'recurrence' => 'finite']]],
            ],
            'oneTimeOffers' => [],
        ]],
    ]);

    expect($event->id)->toBe('p1')->and($event->unfetched)->toBe(['old_sku'])
        ->and($event->products[0]['offers'][1]['freeTrial'])->toBeTrue()
        ->and($event->error)->toBeNull();
});

it('builds ProductsLoaded from a failure payload', function () {
    $event = new ProductsLoaded(...['id' => 'p1', 'type' => 'inapp', 'products' => [], 'unfetched' => ['coins'], 'error' => 'Couldn’t reach Google Play.', 'code' => 'service_unavailable']);

    expect($event->code)->toBe('service_unavailable');
});

it('builds PurchaseUpdated for every status', function (string $status, ?string $code) {
    $payload = ['id' => 'buy', 'status' => $status, 'purchases' => $status === 'purchased' ? [purchasePayload()] : []];
    if ($code) {
        $payload += ['message' => 'Something', 'code' => $code];
    }
    $event = new PurchaseUpdated(...$payload);

    expect($event->status)->toBe($status)->and($event->succeeded())->toBe($status === 'purchased');
})->with([
    ['purchased', null],
    ['pending', null],
    ['cancelled', 'user_canceled'],
    ['already_owned', 'item_already_owned'],
    ['failed', 'developer_error'],
]);

it('builds PurchaseUpdated for an unrequested update (null id)', function () {
    $event = new PurchaseUpdated(...['id' => null, 'status' => 'purchased', 'purchases' => [purchasePayload(['pending' => false])]]);

    expect($event->id)->toBeNull()->and($event->purchases[0]['originalJson'])->toContain('orderId');
});

it('ignores keys added by newer native versions', function () {
    $event = new PurchaseUpdated(...['status' => 'failed', 'somethingNew' => 1]);

    expect($event->status)->toBe('failed');
});

it('lists unacknowledged, paid purchases on restore', function () {
    $event = new PurchasesRestored(...['id' => 'r', 'purchases' => [
        purchasePayload(['purchaseToken' => 'a']),
        purchasePayload(['purchaseToken' => 'b', 'acknowledged' => true]),
        purchasePayload(['purchaseToken' => 'c', 'pending' => true, 'state' => 'pending']),
    ]]);

    expect(array_column($event->unacknowledged(), 'purchaseToken'))->toBe(['a']);
});

it('builds the acknowledge and consume answers', function () {
    $ack = new PurchaseAcknowledged(...['id' => 'a', 'purchaseToken' => 'tok', 'ok' => true]);
    $consumed = new PurchaseConsumed(...['id' => 'c', 'purchaseToken' => 'tok', 'ok' => false, 'error' => 'Not owned', 'code' => 'item_not_owned']);

    expect($ack->ok)->toBeTrue()->and($consumed->code)->toBe('item_not_owned');
});
