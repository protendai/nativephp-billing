# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-25

First public release, extracted from an in-app plugin.

### Added
- One-time products (consumable and non-consumable) next to subscriptions: `products($ids, Billing::INAPP)`, `buy()`.
- Subscription offers: every eligible base plan and offer with its pricing phases, free trial / introductory flags
  and offer tokens; `subscribe()` takes `offerId` or `offerTags`.
- One-time product purchase options and offers (Play Billing 8 `oneTimeOffers`).
- `acknowledge()` and `consume()` for apps without a server (`PurchaseAcknowledged`, `PurchaseConsumed` events).
- `restore()` covers both product types and reports pending and unacknowledged purchases;
  `PurchasesRestored::unacknowledged()`.
- Purchases expose `purchaseToken`, `orderId`, `purchaseTime`, `state`, `autoRenewing`, `quantity`, `accountId`,
  `profileId`, `packageName`, `originalJson` and `signature` for server-side verification.
- Request ids on every call, echoed on every event; stable failure `code`s next to readable messages;
  `unfetched` product ids.
- Upgrade/downgrade `replacementMode`, `profileId`.
- `BillingException` when the device refuses a call (bad arguments, iOS).
- iOS stub: every bridge function answers `UNSUPPORTED` instead of staying silent.
- JavaScript library (`resources/js`) with TypeScript types, Boost guidelines, FakeBridge test macros, Pest tests,
  README with server-side verification and Real-time developer notifications.

### Fixed
- The activity is held weakly and refreshed on every call, so events and the purchase sheet use the live activity
  after it is recreated; events that find no live activity are delivered with the next call.
- Callers waiting for the Play connection are always answered: on setup failure, on a disconnect during setup, and
  after a 20 second timeout (the UI could stay on "buying" forever). Uses Play Billing 8
  `enableAutoServiceReconnection()` once connected.
- `productIds` sent from PHP arrive as a `JSONArray`, which the old code read as an empty list, so no products loaded.
- `android.min_version` lowered from 33 to 21 (Play Billing 8's minimum).
- `already_owned` and every other status and failure code are documented.

### Changed
- Package `protendai/nativephp-billing`, PHP namespace `Protendai\Billing`, Kotlin package `com.protendai.billing`,
  bridge namespace `ProtendaiBilling`; MIT licence.
- Native sources moved to `resources/android/src` and `resources/ios/Sources`.

[Unreleased]: https://github.com/protendai/nativephp-billing/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/protendai/nativephp-billing/releases/tag/v1.0.0
