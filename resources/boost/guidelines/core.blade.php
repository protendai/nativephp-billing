## protendai/nativephp-billing

In-app purchases for NativePHP Mobile v4: Google Play Billing 8 on Android and StoreKit 2 on iOS (15+). Subscriptions (base plans, free trials, introductory offers, upgrades/downgrades) and one-time products (consumable and non-consumable).

### Methods (facade `Protendai\Billing\Facades\Billing`, or inject `Protendai\Billing\Billing`)

Every method returns a request id straight away (pass your own `$id` or get a UUID); the answer arrives later as an event carrying that id. They throw `Protendai\Billing\Exceptions\BillingException` (`->errorCode`: `INVALID_ARGUMENT`) when the device refuses outright; no event follows then. Outside the mobile runtime they do nothing and return the id.

- `Billing::available(): bool`: an Android or iOS build with the plugin. Check before showing buy buttons.
- `Billing::platform(): ?string`: `'android'`, `'ios'` or null. Use it to pick product ids (see iOS below).
- `Billing::products(array $productIds, string $type = Billing::SUBS, ?string $id = null): string`: prices in the user's currency, plans and the offers this account is eligible for. `Billing::INAPP` for one-time products. → `ProductsLoaded`.
- `Billing::subscribe(string $productId, string $basePlanId, ?string $accountId = null, ?string $oldPurchaseToken = null, ?string $offerId = null, array $offerTags = [], ?string $replacementMode = null, ?string $profileId = null, ?string $id = null): string`: opens the purchase sheet. No offer → regular base plan price. `$offerId` / `$offerTags` apply a trial or intro price. `$oldPurchaseToken` + `$replacementMode` (`with_time_proration` default, `charge_prorated_price`, `charge_full_price`, `without_proration`, `deferred`) switch plans. → `PurchaseUpdated`.
- `Billing::buy(string $productId, ?string $accountId = null, ?string $offerId = null, ?string $profileId = null, ?string $id = null): string`: one-time product. → `PurchaseUpdated`.
- `Billing::purchase(string $productId, string $type = 'subs', array $options = [], ?string $id = null): string`: generic form of the two above.
- `Billing::restore(?string $type = null, ?string $id = null, bool $sync = false): string`: what the account owns now (active subs, unconsumed one-time products, incl. pending/unacknowledged). → `PurchasesRestored`. Call on app start to finish interrupted purchases. `$sync` (iOS) asks the App Store first and may ask the user to sign in: only from a Restore button.
- `Billing::acknowledge(string $purchaseToken, ?string $id = null): string`: Android: for apps without a server. iOS: finishes the transaction; always call it once the purchase is granted. → `PurchaseAcknowledged`.
- `Billing::consume(string $purchaseToken, ?string $id = null): string`: consumables, after granting the item; also acknowledges (iOS: finishes). → `PurchaseConsumed`.
- `$accountId` is your own opaque id for the buyer (max 64 chars, never an email). Android returns it as `obfuscatedExternalAccountId`; iOS stores it as the transaction's `appAccountToken` (a UUID: `Billing::appAccountToken($accountId)` gives the same value on the server).

### iOS differences

- Each subscription period is its own App Store product (e.g. `premium_monthly`, `premium_yearly`) in one subscription group; `basePlanId`, offers, `oldPurchaseToken` and `replacementMode` are ignored. Plans in one group replace each other on purchase.
- An eligible introductory offer is applied by the App Store; it is listed first in `offers` with `offerId` `introductory`.
- `purchaseToken` is the transaction id. A purchase also carries `platform` `ios`, `transactionId`, `originalTransactionId`, `expiryTime`, `revoked`, `environment` and `jws` (signed transaction). Verify with the App Store Server API (`GET /inApps/v1/transactions/{transactionId}`) or by checking the JWS signature, then call `acknowledge()` in the app.
- `PurchaseUpdated` may arrive with `id` null for renewals, Ask to Buy approvals and purchases on other devices, and with status `revoked` for refunds.

### Events (`#[On(...)]` from `Native\Mobile\Attributes\On` in EDGE components, `#[OnNative(...)]` in Livewire)

- `Protendai\Billing\Events\ProductsLoaded`: `products`, `error`, `code`, `id`, `type`, `unfetched` (ids the store didn't return). Product: `id`, `type`, `title`, `name`, `description`, `plans` (`basePlanId`, `price`, `micros`, `currency`, `period` ISO 8601, `offerToken` on Android), `offers` (`basePlanId`, `offerId` or null, `offerTags`, `offerToken` on Android, `freeTrial`, `introductory`, `phases`[`price`, `micros`, `currency`, `period`, `cycles`, `recurrence`]), and for one-time products `price`, `micros`, `currency`, `oneTimeOffers`. iOS adds `kind` and `subscriptionGroupId`.
- `Protendai\Billing\Events\PurchaseUpdated`: `status`, `purchases`, `message`, `code`, `id` (null for updates nobody asked for, e.g. a pending payment completing). Status: `purchased`, `pending` (cash/slow payment or Ask to Buy: do not grant yet), `cancelled`, `already_owned` (the account owns it: call `restore()` and verify that purchase), `failed` (`code` says why), `revoked` (iOS refund).
- `Protendai\Billing\Events\PurchasesRestored`: `purchases`, `error`, `code`, `id`; `$event->unacknowledged()` lists paid purchases still to verify/acknowledge.
- `Protendai\Billing\Events\PurchaseAcknowledged` / `PurchaseConsumed`: `purchaseToken`, `ok`, `error`, `code`, `id`.
- Purchase shape: `productId`, `productIds`, `type`, `purchaseToken`, `orderId`, `purchaseTime` (ms), `state` (`purchased`/`pending`/`unspecified`), `pending`, `acknowledged`, `autoRenewing`, `quantity`, `accountId`, `profileId`, `packageName`, and on Android `originalJson`, `signature`.
- Failure codes: `user_canceled`, `service_unavailable`, `billing_unavailable`, `item_unavailable`, `developer_error`, `item_already_owned`, `item_not_owned`, `service_disconnected`, `feature_not_supported`, `network_error`, `error`, plus plugin codes `service_timeout`, `offer_unavailable`, `no_activity`, `superseded`, `unverified` (iOS).
- Compare the event `id` with the id you stored: other screens may call too. Declare payload parameters with defaults (`array $purchases = []`, `?string $code = null`).

### Rules

- Never grant on `purchased` alone: send the purchase to your server and verify it there. Android: Google Play Developer API (`purchases.subscriptionsv2.get` / `purchases.products.get`), grant, then acknowledge (or consume) on the server; unacknowledged purchases are refunded after 3 days. iOS: App Store Server API or the JWS, grant, then `acknowledge()` in the app.
- Server notifications: Real-time developer notifications (Pub/Sub) on Android, App Store Server Notifications V2 on iOS, so renewals, cancellations and refunds reach the server. Make the server idempotent per token / original transaction id (unique index), since the app and the notification often arrive together.
- Android permission `com.android.vending.BILLING` is added by the plugin; min Android 5.0 (API 21), iOS 15.

### JavaScript (`resources/js/index.js`)

```js
import { platform, products, subscribe, buy, restore, acknowledge, consume, onPurchaseUpdated, Events, SUBS, INAPP } from '@protendai/nativephp-billing';
onPurchaseUpdated(({ id, status, purchases }) => { if (status === 'purchased') verify(purchases[0]); });
const ios = (await platform()) === 'ios';
await subscribe({ productId: ios ? 'premium_monthly' : 'premium', basePlanId: 'monthly', accountId: userId });
```

### Tests

Under a test runner the plugin registers FakeBridge macros: `assertProductsRequested(?fn)`, `assertPurchaseStarted(?fn)`, `assertNoPurchaseStarted()`, `assertPurchasesRestoreRequested(?fn)`, `assertPurchaseAcknowledged(?token)`, `assertPurchaseConsumed(?token)`. Drive results with `->call('handler', ...)` or by emitting the events.
