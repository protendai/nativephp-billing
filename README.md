# NativePHP Billing

In-app purchases for [NativePHP Mobile](https://nativephp.com) v4: Google Play Billing on Android and StoreKit 2 on
iOS, behind one facade and one set of events. Sell subscriptions (base plans, free trials, introductory prices,
upgrades and downgrades) and one-time products (consumable and non-consumable) from your Laravel app, and get what
you need to verify every purchase on your server (Play purchase token, App Store transaction id and signed JWS).

Built on Play Billing Library 8.0.0 and StoreKit 2.

## Platform support

| Platform | Status |
|---|---|
| Android 5.0+ (API 21) | Supported: Google Play Billing Library 8.0.0 |
| iOS 15+ | Supported: StoreKit 2. See [iOS differences](#ios-differences). |

## Installation

```bash
composer require protendai/nativephp-billing

php artisan native:plugin:register protendai/nativephp-billing
```

`native:plugin:register` adds `Protendai\Billing\BillingServiceProvider` to the `plugins()` list of your
`App\Providers\NativeServiceProvider`; only plugins listed there are compiled into the native app.

### Permissions

The plugin adds `com.android.vending.BILLING` to the Android manifest and the
`com.android.billingclient:billing:8.0.0` dependency. Nothing else: no runtime permission prompt, no Info.plist keys.
On iOS, In-App Purchase needs no entitlement or Info.plist key; it is on for every App ID.

### Play Console

1. Upload a build with the plugin to a testing track (Play only lists products for an app it has seen with the
   billing permission).
2. Create your products under *Monetize with Play → Products*: subscriptions with base plans (and offers such as a
   free trial), or one-time products.
3. Add your testers as *License testers* (Play Console → Settings → License testing) so they can buy without being
   charged.

There are no API keys or environment variables on the device. Server-side verification needs a Google Cloud
service account; see [Server-side verification](#server-side-verification).

### App Store Connect

1. Sign the *Paid Apps* agreement and add banking and tax details (Business → Agreements). Until it is active,
   StoreKit returns no products.
2. Under your app → *Subscriptions*, create one subscription group and one product per plan and period (for example
   `premium_monthly`, `premium_yearly`, `standard_monthly`). Rank them in the group: a higher plan is an upgrade.
   One-time products go under *In-App Purchases*.
3. Test with a Sandbox account (Settings → Developer → Sandbox Apple Account on the device) or a StoreKit
   configuration file in Xcode, and later through TestFlight.

## How it works

Every method returns a **request id** straight away and never blocks. The answer arrives later as an **event**
carrying the same id. Pass your own id, or keep the generated UUID and compare it in your listener (other screens may
make calls too).

| Method | Answer event |
|---|---|
| `products()` | `ProductsLoaded` |
| `subscribe()`, `buy()`, `purchase()` | `PurchaseUpdated` |
| `restore()` | `PurchasesRestored` |
| `acknowledge()` | `PurchaseAcknowledged` |
| `consume()` | `PurchaseConsumed` |

**Purchases are never acknowledged automatically.** Google refunds any purchase that is not acknowledged (or
consumed) within three days. Verify and acknowledge on your server (recommended), or call `acknowledge()` /
`consume()` on the device if you have no server. On iOS the app itself finishes each transaction: call
`acknowledge()` (or `consume()`) once your server has granted the purchase, or the App Store hands it to the app
again on every launch.

### iOS differences

The same calls work on both platforms, with these differences on iOS:

- **One product per plan and period.** Google has one subscription with base plans (`premium` / `monthly`); the App
  Store has a product per period (`premium_monthly`). Pick ids with `Billing::platform()`. On iOS `basePlanId` is
  ignored, and each subscription product lists one plan whose `basePlanId` is the product id.
- **Upgrades and downgrades** happen by buying another product in the same subscription group; `oldPurchaseToken`
  and `replacementMode` are ignored.
- **Offers.** An introductory offer (free trial or intro price) the account is eligible for is applied by the App
  Store; it is listed first in `offers` with `offerId` `introductory`. `offerId` / `offerTags` are ignored, and
  promotional offers are not supported yet.
- **purchaseToken is the App Store transaction id.** Purchases also carry `platform` (`ios`), `transactionId`,
  `originalTransactionId`, `expiryTime`, `revoked`, `environment` (`Sandbox`, `Production`, `Xcode`; iOS 16+) and
  `jws`, the signed transaction. `originalJson` and `signature` are Android only.
- **accountId** becomes the transaction's `appAccountToken`, which must be a UUID. A UUID is used as it is; any
  other id is turned into a name-based (version 5) UUID. `Billing::appAccountToken($accountId)` returns the same
  value, so your server can match a transaction to the buyer. The purchase payload's `accountId` is that UUID.
- **Updates nobody asked for** (renewals, Ask to Buy approvals, purchases on another device, offer codes) arrive as
  `PurchaseUpdated` with `id` null once the plugin has been used in this launch; refunds arrive with status `revoked`
  (already finished; nothing to grant).
- **Restore button.** `restore(sync: true)` asks the App Store to sync first, which may ask the user to sign in.
  App Review expects a Restore Purchases button; call it from there only. Plain `restore()` never prompts.

## Usage (PHP)

### A subscription screen (EDGE component)

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Protendai\Billing\Events\ProductsLoaded;
use Protendai\Billing\Events\PurchasesRestored;
use Protendai\Billing\Events\PurchaseUpdated;
use Protendai\Billing\Facades\Billing;

class Plans extends NativeComponent
{
    public array $prices = [];
    public ?string $buying = null;
    public ?string $note = null;

    public function mount(): void
    {
        if (Billing::available()) {
            Billing::products(['premium']); // subscription product ids from the Play Console
        }
    }

    #[On(ProductsLoaded::class)]
    public function productsLoaded(array $products = [], ?string $error = null): void
    {
        foreach ($products as $product) {
            foreach ($product['plans'] as $plan) {
                $this->prices[$product['id']][$plan['basePlanId']] = $plan['price']; // "ZAR 109.99"
            }
        }
        $this->note = $products ? null : $error;
    }

    public function subscribe(string $basePlanId): void
    {
        $this->buying = Billing::subscribe('premium', $basePlanId, accountId: (string) auth()->id());
    }

    #[On(PurchaseUpdated::class)]
    public function purchaseUpdated(string $status, array $purchases = [], ?string $message = null, ?string $id = null): void
    {
        match ($status) {
            'purchased' => $this->verifyOnServer($purchases),
            'pending' => $this->note = 'Waiting for the payment to go through.',
            'already_owned' => Billing::restore(id: $this->buying),
            'cancelled' => $this->buying = null,
            default => [$this->buying, $this->note] = [null, $message],
        };
    }

    #[On(PurchasesRestored::class)]
    public function purchasesRestored(array $purchases = [], ?string $id = null): void
    {
        $this->verifyOnServer(array_filter($purchases, fn ($p) => ! $p['pending']));
    }

    protected function verifyOnServer(array $purchases): void
    {
        foreach ($purchases as $purchase) {
            // POST $purchase['purchaseToken'] and $purchase['productId'] to your API, which checks
            // it with Google, grants the plan and acknowledges it.
        }
        $this->buying = null;
    }
}
```

### Livewire

Listen with `#[OnNative]` instead of `#[On]`:

```php
use Native\Mobile\Attributes\OnNative;
use Protendai\Billing\Events\PurchaseUpdated;

#[OnNative(PurchaseUpdated::class)]
public function purchaseUpdated(string $status, array $purchases = [], ?string $message = null): void
{
    // same as above
}
```

### Free trials and introductory prices

`products()` returns, for each subscription, `offers`: the base plans and every offer **this account is eligible
for**, with their pricing phases. Without an offer, `subscribe()` buys the base plan at its regular price. To apply
an offer, pass its id or tags:

```php
Billing::subscribe('premium', 'monthly', $accountId, offerId: 'free-trial-7d');
Billing::subscribe('premium', 'monthly', $accountId, offerTags: ['intro']); // cheapest eligible offer with all tags
```

An offer the account is no longer eligible for (it already used the trial) fails with code `offer_unavailable`.

### Upgrades and downgrades

```php
Billing::subscribe('premium', 'monthly', $accountId,
    oldPurchaseToken: $currentToken,
    replacementMode: 'charge_prorated_price',
);
```

Replacement modes: `with_time_proration` (default), `charge_prorated_price`, `charge_full_price`,
`without_proration`, `deferred`. Google sends the new purchase with `linkedPurchaseToken` pointing at the old one.

### One-time products

```php
Billing::products(['coins_100', 'remove_ads'], Billing::INAPP);

Billing::buy('coins_100', $accountId);

// Consumables: grant the coins (on your server), then consume so they can be bought again.
Billing::consume($purchaseToken);

// Non-consumables without a server: acknowledge within three days.
Billing::acknowledge($purchaseToken);
```

### Finishing interrupted purchases (restore on start)

A purchase can complete while your app is closed, the network drops before your server hears about it, or a pending
payment (cash at a shop) completes days later. Call `restore()` when the app starts or resumes and send any
unacknowledged purchase to your server:

```php
public function mount(): void
{
    if (Billing::available()) {
        $this->restoreId = Billing::restore();
    }
}

#[On(PurchasesRestored::class)]
public function purchasesRestored(array $purchases = [], ?string $id = null): void
{
    if ($id !== $this->restoreId) {
        return;
    }
    $event = new PurchasesRestored(purchases: $purchases);
    foreach ($event->unacknowledged() as $purchase) {
        // POST $purchase['purchaseToken'] to your verify endpoint
    }
}
```

`restore()` only returns what the account owns now: active (or grace-period) subscriptions and unconsumed one-time
products. Expired subscriptions and consumed products are not listed.

## Usage (JavaScript)

For Inertia with Vue or React. Import from the package source (`vendor/protendai/nativephp-billing/resources/js/index.js`),
for example with a Vite alias:

```js
// vite.config.js
resolve: { alias: { '@protendai/nativephp-billing': '/vendor/protendai/nativephp-billing/resources/js/index.js' } }
```

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { isAvailable, products, subscribe, onProductsLoaded, onPurchaseUpdated } from '@protendai/nativephp-billing';

const plans = ref([]);
const stops = [];

onMounted(async () => {
    stops.push(onProductsLoaded(({ products }) => { plans.value = products.flatMap(p => p.plans); }));
    stops.push(onPurchaseUpdated(async ({ status, purchases, message }) => {
        if (status === 'purchased') await axios.post('/api/purchases/verify', { token: purchases[0].purchaseToken });
    }));
    if (await isAvailable()) await products(['premium']);
});
onUnmounted(() => stops.forEach(stop => stop()));

const buy = (basePlanId) => subscribe({ productId: 'premium', basePlanId, accountId: userId });
</script>
```

Exports: `isAvailable()`, `platform()`, `products(ids, type?, id?)`, `purchase(options)`, `subscribe(options)`, `buy(options)`,
`restore(type?, id?, sync?)`, `acknowledge(token, id?)`, `consume(token, id?)`, `on(eventName, cb)`, `onProductsLoaded`,
`onPurchaseUpdated`, `onPurchasesRestored`, `onPurchaseAcknowledged`, `onPurchaseConsumed` (each returns an
unsubscribe function), and the constants `SUBS`, `INAPP`, `Status`, `Events`. Types are in `resources/js/index.d.ts`.
Calls resolve with `{ id, status: 'started' }` and reject with an `Error` whose `code` is
`INVALID_ARGUMENT` when the device refuses.

## Methods

Facade `Protendai\Billing\Facades\Billing` (or inject `Protendai\Billing\Billing`). All return the request id
except `available()`, `platform()` and `appAccountToken()`. All throw `Protendai\Billing\Exceptions\BillingException` (`$e->errorCode`:
`INVALID_ARGUMENT`) when the device refuses outright; no event follows then. Outside the mobile runtime they do
nothing and return the id.

| Method | Description |
|---|---|
| `available(): bool` | `true` in an Android or iOS build with the plugin; `false` outside the app. |
| `platform(): ?string` | `'android'`, `'ios'`, or null outside the app. |
| `appAccountToken(string $accountId): string` (static) | The UUID iOS stores for an `accountId`; use it on your server. |
| `products(array $productIds, string $type = Billing::SUBS, ?string $id = null)` | Load products. `Billing::INAPP` for one-time products. |
| `subscribe(string $productId, string $basePlanId, ?string $accountId = null, ?string $oldPurchaseToken = null, ?string $offerId = null, array $offerTags = [], ?string $replacementMode = null, ?string $profileId = null, ?string $id = null)` | Buy a subscription base plan, optionally an offer, optionally replacing another subscription. |
| `buy(string $productId, ?string $accountId = null, ?string $offerId = null, ?string $profileId = null, ?string $id = null)` | Buy a one-time product. |
| `purchase(string $productId, string $type = 'subs', array $options = [], ?string $id = null)` | Generic form; options: `basePlanId`, `offerId`, `offerTags`, `accountId`, `profileId`, `oldPurchaseToken`, `replacementMode`. |
| `restore(?string $type = null, ?string $id = null, bool $sync = false)` | What the account owns now; both types unless one is given. `$sync`: iOS Restore button. |
| `acknowledge(string $purchaseToken, ?string $id = null)` | Acknowledge on the device (Android apps without a server); finish the transaction (iOS). |
| `consume(string $purchaseToken, ?string $id = null)` | Consume a one-time purchase (also acknowledges it); finish it (iOS). |

`$accountId` / `$profileId` are your own opaque ids for the buyer (up to 64 characters; never an email or name).
Google stores them on the purchase (`accountId` / `profileId` in the payload) and returns them to your server as
`obfuscatedExternalAccountId` / `obfuscatedExternalProfileId`. Use them to check that a token belongs to the user who
sends it.

## Events

All in `Protendai\Billing\Events`. Every payload carries the request `id` (`null` for updates nobody asked for).
Declare listener parameters with defaults so new keys never break you.

### `ProductsLoaded`

`products`, `error`, `code`, `id`, `type`, `unfetched` (ids Play did not return: wrong id, inactive, or the other
product type).

```php
[
    'id' => 'premium', 'type' => 'subs', 'title' => 'Premium (My App)', 'name' => 'Premium', 'description' => '…',
    // Subscriptions: each base plan at its regular (last-phase) price.
    'plans' => [['basePlanId' => 'monthly', 'price' => '$5.99', 'micros' => 5990000, 'currency' => 'USD', 'period' => 'P1M', 'offerToken' => '…']],
    // Subscriptions: base plans and eligible offers with every pricing phase.
    'offers' => [[
        'basePlanId' => 'monthly', 'offerId' => 'free-trial-7d', 'offerTags' => ['intro'], 'offerToken' => '…',
        'freeTrial' => true, 'introductory' => false,
        'phases' => [
            ['price' => 'Free', 'micros' => 0, 'currency' => 'USD', 'period' => 'P1W', 'cycles' => 1, 'recurrence' => 'finite'],
            ['price' => '$5.99', 'micros' => 5990000, 'currency' => 'USD', 'period' => 'P1M', 'cycles' => 0, 'recurrence' => 'infinite'],
        ],
    ]],
    // One-time products: default price, plus purchase options and offers (Play Billing 8).
    'price' => '$0.99', 'micros' => 990000, 'currency' => 'USD',
    'oneTimeOffers' => [['offerId' => null, 'offerTags' => [], 'offerToken' => '…', 'price' => '$0.99', 'micros' => 990000, 'currency' => 'USD']],
]
```

Periods are ISO 8601 durations (`P1W`, `P1M`, `P3M`, `P1Y`). `micros` is the price in millionths of the currency
unit. `recurrence` is `infinite`, `finite` (repeats `cycles` times) or `none`.

### `PurchaseUpdated`

`status`, `purchases`, `message`, `code`, `id`. The purchase sheet closed, or a purchase changed while the app was
open (a pending payment completing: `id` is `null` then).

| `status` | Meaning | What to do |
|---|---|---|
| `purchased` | Paid. `purchases` lists it. | Verify on your server, grant, acknowledge/consume. Do not grant on this event alone. |
| `pending` | Waiting for payment (e.g. cash at a shop). | Don't grant. A later `PurchaseUpdated` or `restore()` shows it as purchased. |
| `cancelled` | The user closed the sheet. | Nothing. |
| `already_owned` | The account already owns it (code `item_already_owned`): an earlier purchase wasn't consumed, or the subscription is active. | Call `restore()` and verify that purchase (consumables: consume it). |
| `failed` | Anything else; `code` and `message` say why. | Show `message`. |
| `revoked` | iOS: Apple refunded or revoked a purchase while the app was open (already finished). | Your server hears it from App Store notifications; refresh what you show. |

`PurchaseUpdated::PURCHASED`, `PENDING`, `CANCELLED`, `ALREADY_OWNED`, `FAILED`, `REVOKED` and `$event->succeeded()` are
available on the event class.

Purchase shape (also used by `PurchasesRestored`):

```php
[
    'productId' => 'premium', 'productIds' => ['premium'], 'type' => 'subs',
    'purchaseToken' => '…', 'orderId' => 'GPA.1234-5678-9012-34567', // orderId is null while pending
    'purchaseTime' => 1761400000000, // ms since epoch
    'state' => 'purchased', 'pending' => false, // state: purchased | pending | unspecified
    'acknowledged' => false, 'autoRenewing' => true, 'quantity' => 1,
    'accountId' => '42', 'profileId' => '', 'packageName' => 'com.example.app',
    'originalJson' => '{…}', 'signature' => '…', // Android: for local signature checks against your license key
]

// iOS: purchaseToken and transactionId are the App Store transaction id, plus
// 'platform' => 'ios', 'originalTransactionId' => '…', 'expiryTime' => 1764000000000,
// 'revoked' => false, 'environment' => 'Sandbox', 'jws' => 'eyJ…' (signed transaction),
// and 'accountId' is the appAccountToken UUID. No originalJson or signature.
```

### `PurchasesRestored`

`purchases`, `error`, `code`, `id`. `$event->unacknowledged()` lists paid, unacknowledged purchases.

### `PurchaseAcknowledged` / `PurchaseConsumed`

`purchaseToken`, `ok`, `error`, `code`, `id`.

### Failure codes

| Code | Meaning |
|---|---|
| `user_canceled` | The user backed out. |
| `service_unavailable`, `network_error`, `service_disconnected` | Play couldn't be reached; try again. |
| `service_timeout` | Connecting to Play took longer than 20 seconds. |
| `billing_unavailable` | Billing isn't available (no Play Store, unsupported country, Play account issue). |
| `item_unavailable` | The product isn't available for purchase (inactive, not published to this track, wrong country). |
| `offer_unavailable` | The base plan or offer doesn't exist or this account isn't eligible. |
| `item_already_owned` | Already owned (see `already_owned`). |
| `item_not_owned` | Acknowledge/consume of something the account doesn't own. |
| `developer_error` | Bad request, e.g. the app isn't from Play / not uploaded yet, or a wrong offer token. |
| `feature_not_supported` | The Play Store on the device is too old for this. |
| `no_activity` | The app wasn't in the foreground to show the sheet. |
| `superseded` | A second purchase started before the first sheet answered. |
| `unverified` | iOS: StoreKit couldn't verify the transaction's signature. |
| `error` | Any other store error; see `message`. |

## Server-side verification

Treat `purchased` as a claim, not proof. Send the `purchaseToken` (and `productId`) to your server and:

1. **Verify with Google.** Call the Google Play Developer API with a service account that has access to your app in
   the Play Console (*Users and permissions*, "View financial data" and "Manage orders and subscriptions"):
   - subscriptions: `GET https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{package}/purchases/subscriptionsv2/tokens/{token}`
     and check `subscriptionState` (`SUBSCRIPTION_STATE_ACTIVE`, `_IN_GRACE_PERIOD`, `_CANCELED` until `expiryTime`),
     `lineItems[].productId` and `expiryTime`;
   - one-time products: `GET …/purchases/products/{productId}/tokens/{token}`, and check `purchaseState == 0`.
   - Match `externalAccountIdentifiers.obfuscatedExternalAccountId` (subscriptions) / `obfuscatedExternalAccountId`
     (products) with the user sending the token, so a token can't be replayed on another account.
2. **Grant** the plan or item, idempotently: one row per purchase token (a unique index), because the app's request
   and a Play notification for the same purchase often arrive at the same moment.
3. **Acknowledge** within three days, or Google refunds it:
   `POST …/purchases/subscriptions/{subscriptionId}/tokens/{token}:acknowledge` or
   `POST …/purchases/products/{productId}/tokens/{token}:acknowledge`. Consumables are consumed instead (on the device
   with `consume()` after your server granted them, or `POST …/purchases/products/{productId}/tokens/{token}:consume`).
4. **Upgrades**: a subscription with `linkedPurchaseToken` replaces that older token; end the old grant.

`originalJson` and `signature` let you verify a purchase offline against your app's Base64 RSA license key (Play
Console → Monetization setup), but that doesn't tell you about refunds or cancellations, so prefer the API.

### App Store (iOS)

1. **Verify with Apple.** Either call the App Store Server API
   (`GET https://api.storekit.itunes.apple.com/inApps/v1/transactions/{transactionId}`, or
   `api.storekit-sandbox.itunes.apple.com` for Sandbox and TestFlight) with a JWT signed by an In-App Purchase key
   from App Store Connect (Users and Access → Integrations), or verify the `jws` on the purchase yourself (ES256,
   certificate chain in its `x5c` header up to Apple Root CA G3). Check `bundleId`, `productId`, `expiresDate`,
   `revocationDate` and that `appAccountToken` equals `Billing::appAccountToken()` of the user sending it.
2. **Grant** idempotently, keyed by `originalTransactionId` (it stays the same across renewals and upgrades within a
   group).
3. Answer the app, which then calls `acknowledge()` to finish the transaction.
4. **App Store Server Notifications V2**: set your HTTPS endpoint in App Store Connect (App Information → App Store
   Server Notifications, production and sandbox). Each POST has a `signedPayload` (JWS): verify it, read
   `notificationType` (`DID_RENEW`, `EXPIRED`, `DID_CHANGE_RENEWAL_STATUS`, `REFUND`, `DID_FAIL_TO_RENEW`, ...)
   and the `signedTransactionInfo`, and update your records.

### Real-time developer notifications (RTDN)

Renewals, cancellations, grace periods, account holds, plan changes and refunds happen outside your app. Google
reports them through Cloud Pub/Sub:

1. Google Cloud console → Pub/Sub: create a topic. Grant `google-play-developer-notifications@system.gserviceaccount.com`
   the *Pub/Sub Publisher* role on it.
2. Create a **push** subscription to an HTTPS endpoint on your server, with a secret in the URL or an OIDC token so
   only Pub/Sub can call it.
3. Play Console → Monetize with Play → Monetization setup → Real-time developer notifications: enter the topic name
   (`projects/{project}/topics/{topic}`), choose subscriptions, voided purchases and one-time products, and send a test
   notification.
4. In the endpoint, base64-decode `message.data`. It contains `subscriptionNotification.purchaseToken`,
   `oneTimeProductNotification.purchaseToken` or `voidedPurchaseNotification.purchaseToken`: **re-verify that token
   with the API** (the notification itself carries no state you should trust) and update your records. Return 2xx;
   anything else makes Pub/Sub retry.

Also re-check subscriptions near their expiry on a schedule in case a notification is lost, and call `restore()` in
the app on start so purchases that never reached your server are sent again.

## Testing

Android: use license testers and the Play Console's test cards ("Test card, always approves", "…declines", "Slow test card"
for `pending`). Test subscriptions renew every few minutes. iOS: use a Sandbox Apple Account or a StoreKit
configuration file in Xcode; Sandbox subscriptions renew every few minutes too.

In your app's tests (`Native::test(...)`), the plugin registers FakeBridge macros:

```php
Native::test(Plans::class)
    ->call('subscribe', 'monthly')
    ->assertPurchaseStarted(fn (array $p) => $p['productId'] === 'premium' && $p['basePlanId'] === 'monthly');
```

`assertProductsRequested(?fn)`, `assertPurchaseStarted(?fn)`, `assertNoPurchaseStarted()`,
`assertPurchasesRestoreRequested(?fn)`, `assertPurchaseAcknowledged(?token)`, `assertPurchaseConsumed(?token)`.
Deliver results by calling your listener (`->call('purchaseUpdated', 'purchased', [...])`).

The plugin's own tests: `composer install && composer test`.

## Limitations

- iOS: promotional offers, win-back offers, offer codes redemption sheet and the manage subscriptions sheet are not
  wrapped yet.
- One purchase sheet at a time; starting another closes out the first request with code `superseded`.
- Events that arrive while the app has no live activity are held (up to 20) and delivered with the next billing call.
- No quantity selection for multi-quantity one-time products yet (`quantity` is reported).
- External offers, user choice billing and alternative billing are not supported.

## Roadmap

- iOS promotional offers (signed on your server) and `showManageSubscriptions`.
- Price change confirmation and in-app messaging (`showInAppMessages`) for payment declines.

## Support

Questions and bugs: [GitHub issues](https://github.com/protendai/nativephp-billing/issues).

## License

MIT. See [LICENSE](LICENSE).
