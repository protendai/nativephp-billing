## protendai/nativephp-billing

Google Play Billing (Play Billing Library 8) for NativePHP Mobile v4: subscriptions (base plans, free trials, introductory offers, upgrades/downgrades) and one-time products (consumable and non-consumable). **Android only**: on iOS `Billing::available()` is false and every call throws `BillingException` with `errorCode` `UNSUPPORTED`.

### Methods (facade `Protendai\Billing\Facades\Billing`, or inject `Protendai\Billing\Billing`)

Every method returns a request id straight away (pass your own `$id` or get a UUID); the answer arrives later as an event carrying that id. They throw `Protendai\Billing\Exceptions\BillingException` (`->errorCode`: `UNSUPPORTED`, `INVALID_ARGUMENT`) when the device refuses outright; no event follows then. Outside the mobile runtime they do nothing and return the id.

- `Billing::available(): bool` — Android build with the plugin. Check before showing buy buttons.
- `Billing::products(array $productIds, string $type = Billing::SUBS, ?string $id = null): string` — prices in the user's currency, base plans and the offers this account is eligible for. `Billing::INAPP` for one-time products. → `ProductsLoaded`.
- `Billing::subscribe(string $productId, string $basePlanId, ?string $accountId = null, ?string $oldPurchaseToken = null, ?string $offerId = null, array $offerTags = [], ?string $replacementMode = null, ?string $profileId = null, ?string $id = null): string` — opens the purchase sheet. No offer → regular base plan price. `$offerId` / `$offerTags` apply a trial or intro price. `$oldPurchaseToken` + `$replacementMode` (`with_time_proration` default, `charge_prorated_price`, `charge_full_price`, `without_proration`, `deferred`) switch plans. → `PurchaseUpdated`.
- `Billing::buy(string $productId, ?string $accountId = null, ?string $offerId = null, ?string $profileId = null, ?string $id = null): string` — one-time product. → `PurchaseUpdated`.
- `Billing::purchase(string $productId, string $type = 'subs', array $options = [], ?string $id = null): string` — generic form of the two above.
- `Billing::restore(?string $type = null, ?string $id = null): string` — what the account owns now (active subs, unconsumed one-time products, incl. pending/unacknowledged). → `PurchasesRestored`. Call on app start to finish interrupted purchases.
- `Billing::acknowledge(string $purchaseToken, ?string $id = null): string` — for apps without a server. → `PurchaseAcknowledged`.
- `Billing::consume(string $purchaseToken, ?string $id = null): string` — consumables, after granting the item; also acknowledges. → `PurchaseConsumed`.
- `$accountId` is your own opaque id for the buyer (max 64 chars, never an email); Google returns it on the purchase and to your server as `obfuscatedExternalAccountId`.

### Events (`#[On(...)]` from `Native\Mobile\Attributes\On` in EDGE components, `#[OnNative(...)]` in Livewire)

- `Protendai\Billing\Events\ProductsLoaded`: `products`, `error`, `code`, `id`, `type`, `unfetched` (ids Play didn't return). Product: `id`, `type`, `title`, `name`, `description`, `plans` (base plans: `basePlanId`, `price`, `micros`, `currency`, `period` ISO 8601, `offerToken`), `offers` (`basePlanId`, `offerId` or null, `offerTags`, `offerToken`, `freeTrial`, `introductory`, `phases`[`price`, `micros`, `currency`, `period`, `cycles`, `recurrence`]), and for one-time products `price`, `micros`, `currency`, `oneTimeOffers`.
- `Protendai\Billing\Events\PurchaseUpdated`: `status`, `purchases`, `message`, `code`, `id` (null for updates nobody asked for, e.g. a pending payment completing). Status: `purchased`, `pending` (cash/slow payment: do not grant yet), `cancelled`, `already_owned` (the account owns it: call `restore()` and verify that purchase), `failed` (`code` says why).
- `Protendai\Billing\Events\PurchasesRestored`: `purchases`, `error`, `code`, `id`; `$event->unacknowledged()` lists paid purchases still to verify/acknowledge.
- `Protendai\Billing\Events\PurchaseAcknowledged` / `PurchaseConsumed`: `purchaseToken`, `ok`, `error`, `code`, `id`.
- Purchase shape: `productId`, `productIds`, `type`, `purchaseToken`, `orderId`, `purchaseTime` (ms), `state` (`purchased`/`pending`/`unspecified`), `pending`, `acknowledged`, `autoRenewing`, `quantity`, `accountId`, `profileId`, `packageName`, `originalJson`, `signature`.
- Failure codes: `user_canceled`, `service_unavailable`, `billing_unavailable`, `item_unavailable`, `developer_error`, `item_already_owned`, `item_not_owned`, `service_disconnected`, `feature_not_supported`, `network_error`, `error`, plus plugin codes `service_timeout`, `offer_unavailable`, `no_activity`, `superseded`.
- Compare the event `id` with the id you stored: other screens may call too. Declare payload parameters with defaults (`array $purchases = []`, `?string $code = null`).

### Rules

- Never grant on `purchased` alone: send `purchaseToken` (+ productId) to your server, verify with the Google Play Developer API (`purchases.subscriptionsv2.get` / `purchases.products.get`), grant, then acknowledge (or consume) there. Unacknowledged purchases are refunded after 3 days.
- Set up Real-time developer notifications (Pub/Sub) so renewals, cancellations and refunds reach the server; re-verify the token on each notification and make the server idempotent per token (unique index), since the app and the notification often arrive together.
- Android permission `com.android.vending.BILLING` is added by the plugin; min Android 5.0 (API 21).

### JavaScript (`resources/js/index.js`)

```js
import { products, subscribe, buy, restore, acknowledge, consume, onPurchaseUpdated, Events, SUBS, INAPP } from '@protendai/nativephp-billing';
onPurchaseUpdated(({ id, status, purchases }) => { if (status === 'purchased') verify(purchases[0].purchaseToken); });
await subscribe({ productId: 'premium', basePlanId: 'monthly', accountId: userId });
```

### Tests

Under a test runner the plugin registers FakeBridge macros: `assertProductsRequested(?fn)`, `assertPurchaseStarted(?fn)`, `assertNoPurchaseStarted()`, `assertPurchasesRestoreRequested(?fn)`, `assertPurchaseAcknowledged(?token)`, `assertPurchaseConsumed(?token)`. Drive results with `->call('handler', ...)` or by emitting the events.
