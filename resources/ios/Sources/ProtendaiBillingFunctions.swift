import CryptoKit
import Foundation
import StoreKit

/// StoreKit 2 for NativePHP Mobile (iOS 15+). Bridge namespace: "ProtendaiBilling.*".
///
/// Same functions, parameters and events as Android. Every function returns
/// straight away with {id, status: "started"} (or a bridge error for bad
/// arguments) and answers later with one event carrying the same id:
/// ProductsLoaded, PurchaseUpdated, PurchasesRestored, PurchaseAcknowledged
/// or PurchaseConsumed.
///
/// iOS differences:
/// - Each subscription period is its own App Store product, so basePlanId is
///   ignored (a product's single plan reports its own id as basePlanId).
///   Products in one subscription group replace each other, so
///   oldPurchaseToken and replacementMode are ignored too.
/// - An introductory offer the account is eligible for is applied by the App
///   Store itself; offerId and offerTags are ignored.
/// - purchaseToken is the transaction id. Acknowledge and Consume both finish
///   the transaction. Verify purchases with the App Store Server API
///   (transactionId) or the signed transaction on the purchase ("jws").
/// - accountId becomes the transaction's appAccountToken, which must be a
///   UUID: a UUID is used as it is, anything else is turned into a name-based
///   UUID (version 5) that Billing::appAccountToken() computes the same way.
///
/// Purchases are never finished automatically (except revoked ones, which
/// grant nothing): verify them on a server, then call Acknowledge or Consume.
enum ProtendaiBillingFunctions {

    /// Load products and their offers.
    /// Parameters: productIds (list of strings), type ("subs" | "inapp", default "subs"), id.
    /// Event: ProductsLoaded {id, type, products, unfetched, error, code}.
    class Products: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = Params.id(parameters)
            let ids = Params.stringList(parameters["productIds"])
            guard let type = Params.productType(parameters["type"]) else {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "type must be \"subs\" or \"inapp\"")
            }
            if ids.isEmpty {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "productIds must list at least one product id")
            }

            Task { @MainActor in
                StoreBilling.listen()
                await StoreBilling.products(id: id, ids: ids, type: type)
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    /// Open the App Store purchase sheet.
    /// Parameters: productId, type ("subs" | "inapp", default "subs"), accountId, id.
    /// basePlanId, offerId, offerTags, profileId, oldPurchaseToken and
    /// replacementMode are accepted for Android parity and ignored.
    /// Event: PurchaseUpdated {id, status, purchases, message, code}.
    class Purchase: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = Params.id(parameters)
            guard let productId = Params.string(parameters["productId"]) else {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "productId is required")
            }
            guard let type = Params.productType(parameters["type"]) else {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "type must be \"subs\" or \"inapp\"")
            }
            if let mode = Params.string(parameters["replacementMode"]), !Params.replacementModes.contains(mode.lowercased()) {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "unknown replacementMode")
            }
            let accountId = Params.string(parameters["accountId"])

            Task { @MainActor in
                StoreBilling.listen()
                await StoreBilling.purchase(id: id, productId: productId, type: type, accountId: accountId)
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    /// Purchases this Apple account owns now: active subscriptions, owned
    /// non-consumables and unfinished consumables.
    /// Parameters: type ("subs" | "inapp", or omitted for both), sync (true to
    /// ask the App Store first; it may ask the user to sign in, so only from a
    /// Restore button), id.
    /// Event: PurchasesRestored {id, purchases, error, code}.
    class Restore: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let id = Params.id(parameters)
            let types: Set<String>
            if Params.string(parameters["type"]) == nil {
                types = [Params.subs, Params.inapp]
            } else if let type = Params.productType(parameters["type"]) {
                types = [type]
            } else {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "type must be \"subs\" or \"inapp\"")
            }
            let sync = Params.bool(parameters["sync"])

            Task { @MainActor in
                StoreBilling.listen()
                await StoreBilling.restore(id: id, types: types, sync: sync)
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    /// Finish a purchase once it has been granted (on iOS the app finishes
    /// transactions; a server can't).
    /// Parameters: purchaseToken (the transaction id), id.
    /// Event: PurchaseAcknowledged {id, purchaseToken, ok, error, code}.
    class Acknowledge: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            Finisher.start(parameters, event: "PurchaseAcknowledged")
        }
    }

    /// Finish a consumable so it can be bought again. Same as Acknowledge on iOS.
    /// Parameters: purchaseToken (the transaction id), id.
    /// Event: PurchaseConsumed {id, purchaseToken, ok, error, code}.
    class Consume: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            Finisher.start(parameters, event: "PurchaseConsumed")
        }
    }
}

extension ProtendaiBillingFunctions {

    enum Finisher {
        static func start(_ parameters: [String: Any], event: String) -> [String: Any] {
            let id = Params.id(parameters)
            guard let token = Params.string(parameters["purchaseToken"]) else {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "purchaseToken is required")
            }
            guard let transactionId = UInt64(token) else {
                return BridgeResponse.error(code: "INVALID_ARGUMENT", message: "purchaseToken must be an App Store transaction id")
            }

            Task { @MainActor in
                StoreBilling.listen()
                await StoreBilling.finish(transactionId)
                StoreBilling.dispatch(event, ["id": id, "purchaseToken": token, "ok": true])
            }

            return BridgeResponse.success(data: ["id": id, "status": "started"])
        }
    }

    /// Why something didn't work: a stable code for apps (the Android codes
    /// where they mean the same), and a readable message.
    struct Failure {
        let code: String
        let message: String

        static func of(_ error: Error) -> Failure {
            if let error = error as? StoreKitError {
                switch error {
                case .userCancelled:
                    return Failure(code: "user_canceled", message: "The purchase was cancelled.")
                case .networkError:
                    return Failure(code: "network_error", message: "Couldn’t reach the App Store. Check the connection and try again.")
                case .notAvailableInStorefront:
                    return Failure(code: "item_unavailable", message: "That item isn’t available in this country’s App Store.")
                case .notEntitled:
                    return Failure(code: "item_not_owned", message: "This Apple account doesn’t own that item.")
                case .systemError:
                    return Failure(code: "service_unavailable", message: "The App Store couldn’t do that. Try again in a moment.")
                default:
                    return Failure(code: "error", message: error.localizedDescription)
                }
            }
            if let error = error as? Product.PurchaseError {
                switch error {
                case .productUnavailable:
                    return Failure(code: "item_unavailable", message: "That item isn’t available in the App Store.")
                case .purchaseNotAllowed:
                    return Failure(code: "billing_unavailable", message: "Purchases aren’t allowed on this device or account.")
                case .ineligibleForOffer, .invalidOfferIdentifier, .invalidOfferPrice, .invalidOfferSignature, .missingOfferParameters:
                    return Failure(code: "offer_unavailable", message: "That offer isn’t available for this account.")
                case .invalidQuantity:
                    return Failure(code: "developer_error", message: "Invalid quantity.")
                default:
                    return Failure(code: "error", message: error.localizedDescription)
                }
            }
            return Failure(code: "error", message: error.localizedDescription)
        }

        func put(into payload: inout [String: Any], messageKey: String = "error") {
            payload[messageKey] = message
            payload["code"] = code
        }
    }

    enum Params {
        static let subs = "subs"
        static let inapp = "inapp"
        static let replacementModes: Set<String> = ["with_time_proration", "charge_prorated_price", "charge_full_price", "without_proration", "deferred"]

        static func id(_ parameters: [String: Any]) -> String {
            string(parameters["id"]) ?? UUID().uuidString.lowercased()
        }

        /// Strings only; NSNull and blanks count as missing.
        static func string(_ value: Any?) -> String? {
            guard let text = value as? String, !text.trimmingCharacters(in: .whitespaces).isEmpty else { return nil }
            return text
        }

        static func stringList(_ value: Any?) -> [String] {
            let items: [Any]
            if let list = value as? [Any] {
                items = list
            } else if let text = value as? String {
                items = [text]
            } else {
                items = []
            }
            var seen = Set<String>()
            return items.compactMap { string($0) }.filter { seen.insert($0).inserted }
        }

        static func bool(_ value: Any?) -> Bool {
            if let flag = value as? Bool { return flag }
            if let number = value as? NSNumber { return number.boolValue }
            if let text = value as? String { return ["1", "true", "yes"].contains(text.lowercased()) }
            return false
        }

        static func productType(_ value: Any?) -> String? {
            switch string(value)?.lowercased() {
            case nil: return subs
            case "subs", "subscription": return subs
            case "inapp", "one_time": return inapp
            default: return nil
            }
        }
    }

    /// accountId → appAccountToken. Must match Billing::appAccountToken() in PHP.
    enum AccountToken {
        /// RFC 4122 namespace for the name-based (version 5) tokens.
        static let namespace = UUID(uuidString: "c4cb9250-ea5d-49a3-aaca-c096eeb2f970")!

        static func uuid(for accountId: String) -> UUID {
            if let uuid = UUID(uuidString: accountId) { return uuid }

            var data = withUnsafeBytes(of: namespace.uuid) { Data($0) }
            data.append(Data(accountId.utf8))
            var b = Array(Insecure.SHA1.hash(data: data).prefix(16))
            b[6] = (b[6] & 0x0F) | 0x50
            b[8] = (b[8] & 0x3F) | 0x80
            return UUID(uuid: (b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7], b[8], b[9], b[10], b[11], b[12], b[13], b[14], b[15]))
        }
    }

    /// StoreKit state, touched on the main actor only.
    @MainActor
    enum StoreBilling {
        /// Listens to Transaction.updates: renewals, Ask to Buy approvals,
        /// purchases on other devices, offer codes and refunds.
        private static var updates: Task<Void, Never>?

        /// Verified transactions not finished yet, by id.
        private static var open: [UInt64: Transaction] = [:]

        static func listen() {
            guard updates == nil else { return }
            updates = Task.detached {
                for await result in Transaction.updates {
                    await StoreBilling.update(result)
                }
            }
        }

        private static func update(_ result: VerificationResult<Transaction>) async {
            guard case .verified(let transaction) = result else { return }
            let type = typeOf(transaction.productType)
            if transaction.revocationDate != nil {
                // Refunded or revoked: nothing to grant, and the server hears it from App Store notifications.
                await transaction.finish()
                let purchase = await Describe.purchase(transaction, jws: result.jwsRepresentation, acknowledged: true)
                purchaseResult(nil, type, "revoked", [purchase], nil)
                return
            }
            open[transaction.id] = transaction
            let purchase = await Describe.purchase(transaction, jws: result.jwsRepresentation, acknowledged: false)
            purchaseResult(nil, type, "purchased", [purchase], nil)
        }

        static func products(id: String, ids: [String], type: String) async {
            var payload: [String: Any] = ["id": id, "type": type]
            do {
                let matching = try await Product.products(for: ids).filter { typeOf($0.type) == type }
                var products: [[String: Any]] = []
                for product in matching {
                    products.append(await Describe.product(product))
                }
                let fetched = Set(matching.map { $0.id })
                payload["products"] = products
                payload["unfetched"] = ids.filter { !fetched.contains($0) }
            } catch {
                payload["products"] = [[String: Any]]()
                payload["unfetched"] = ids
                Failure.of(error).put(into: &payload)
            }
            dispatch("ProductsLoaded", payload)
        }

        static func purchase(id: String, productId: String, type: String, accountId: String?) async {
            guard AppStore.canMakePayments else {
                purchaseResult(id, type, "failed", [], Failure(code: "billing_unavailable", message: "In-app purchases are turned off on this device."))
                return
            }
            do {
                guard let product = try await Product.products(for: [productId]).first, typeOf(product.type) == type else {
                    purchaseResult(id, type, "failed", [], Failure(code: "item_unavailable", message: "This product isn’t available in the App Store."))
                    return
                }
                var options: Set<Product.PurchaseOption> = []
                if let accountId = accountId {
                    options.insert(.appAccountToken(AccountToken.uuid(for: accountId)))
                }

                switch try await product.purchase(options: options) {
                case .success(let result):
                    switch result {
                    case .verified(let transaction):
                        open[transaction.id] = transaction
                        let purchase = await Describe.purchase(transaction, jws: result.jwsRepresentation, acknowledged: false)
                        purchaseResult(id, type, "purchased", [purchase], nil)
                    case .unverified(_, let error):
                        purchaseResult(id, type, "failed", [], Failure(code: "unverified", message: "The App Store couldn’t verify this purchase: \(error.localizedDescription)"))
                    }
                case .userCancelled:
                    purchaseResult(id, type, "cancelled", [], Failure(code: "user_canceled", message: "The purchase was cancelled."))
                case .pending:
                    // Ask to Buy, or a payment that needs action: the result comes later through Transaction.updates.
                    purchaseResult(id, type, "pending", [], nil)
                @unknown default:
                    purchaseResult(id, type, "failed", [], Failure(code: "error", message: "The App Store gave an answer this version doesn’t know."))
                }
            } catch {
                let failure = Failure.of(error)
                purchaseResult(id, type, failure.code == "user_canceled" ? "cancelled" : "failed", [], failure)
            }
        }

        static func restore(id: String, types: Set<String>, sync: Bool) async {
            var payload: [String: Any] = ["id": id]
            if sync {
                do {
                    try await AppStore.sync()
                } catch {
                    // Still list what the device knows, and say why the sync failed.
                    Failure.of(error).put(into: &payload)
                }
            }

            let unfinished = await unfinishedIds()
            var purchases: [[String: Any]] = []
            var seen = Set<UInt64>()

            for await result in Transaction.currentEntitlements {
                guard case .verified(let transaction) = result,
                      transaction.revocationDate == nil,
                      types.contains(typeOf(transaction.productType)) else { continue }
                seen.insert(transaction.id)
                purchases.append(await Describe.purchase(transaction, jws: result.jwsRepresentation, acknowledged: !unfinished.contains(transaction.id)))
            }
            // Consumables are not entitlements: list the ones still waiting to be finished.
            for await result in Transaction.unfinished {
                guard case .verified(let transaction) = result,
                      !seen.contains(transaction.id),
                      transaction.revocationDate == nil,
                      types.contains(typeOf(transaction.productType)) else { continue }
                open[transaction.id] = transaction
                purchases.append(await Describe.purchase(transaction, jws: result.jwsRepresentation, acknowledged: false))
            }

            payload["purchases"] = purchases
            dispatch("PurchasesRestored", payload)
        }

        /// Finishes the transaction if it is still unfinished. One already
        /// finished (or unknown here) needs nothing more, so this always succeeds.
        static func finish(_ transactionId: UInt64) async {
            if let transaction = open.removeValue(forKey: transactionId) {
                await transaction.finish()
                return
            }
            for await result in Transaction.unfinished {
                let transaction = result.unsafePayloadValue
                if transaction.id == transactionId {
                    await transaction.finish()
                    return
                }
            }
        }

        private static func unfinishedIds() async -> Set<UInt64> {
            var ids = Set<UInt64>()
            for await result in Transaction.unfinished {
                ids.insert(result.unsafePayloadValue.id)
            }
            return ids
        }

        nonisolated static func typeOf(_ type: Product.ProductType) -> String {
            type == .autoRenewable ? Params.subs : Params.inapp
        }

        static func purchaseResult(_ id: String?, _ type: String?, _ status: String, _ purchases: [[String: Any]], _ failure: Failure?) {
            var payload: [String: Any] = ["status": status, "purchases": purchases]
            if let id = id { payload["id"] = id }
            if let type = type { payload["type"] = type }
            if let failure = failure, status != "purchased", status != "pending" {
                failure.put(into: &payload, messageKey: "message")
            }
            dispatch("PurchaseUpdated", payload)
        }

        /// Events go to PHP on the main queue. Top-level nulls are left out so
        /// the PHP event's own defaults apply.
        nonisolated static func dispatch(_ event: String, _ payload: [String: Any]) {
            let clean = payload.filter { !($0.value is NSNull) }
            DispatchQueue.main.async {
                LaravelBridge.shared.send?("Protendai\\Billing\\Events\\" + event, clean)
            }
        }
    }

    enum Describe {
        static func product(_ product: Product) async -> [String: Any] {
            let currency = product.priceFormatStyle.currencyCode
            var json: [String: Any] = [
                "id": product.id,
                "type": StoreBilling.typeOf(product.type),
                "kind": kind(product.type),
                "title": product.displayName,
                "name": product.displayName,
                "description": product.description,
                "plans": [[String: Any]](),
                "offers": [[String: Any]](),
                "oneTimeOffers": [[String: Any]](),
            ]

            guard let subscription = product.subscription else {
                json["price"] = product.displayPrice
                json["micros"] = micros(product.price)
                json["currency"] = currency
                return json
            }

            // One App Store product is one plan: its own id stands in for Play's base plan id.
            let period = iso(subscription.subscriptionPeriod)
            json["subscriptionGroupId"] = subscription.subscriptionGroupID
            json["plans"] = [[
                "basePlanId": product.id,
                "price": product.displayPrice,
                "micros": micros(product.price),
                "currency": currency,
                "period": period,
            ] as [String: Any]]

            let regular = phase(product.displayPrice, product.price, currency, period, cycles: 0, recurrence: "infinite")
            var offers: [[String: Any]] = [[
                "basePlanId": product.id,
                "offerId": NSNull(),
                "offerTags": [String](),
                "freeTrial": false,
                "introductory": false,
                "phases": [regular],
            ]]

            if let intro = subscription.introductoryOffer, await subscription.isEligibleForIntroOffer {
                let free = intro.paymentMode == .freeTrial
                let introPhase = phase(intro.displayPrice, intro.price, currency, iso(intro.period),
                                       cycles: intro.paymentMode == .payUpFront ? 1 : intro.periodCount, recurrence: "finite")
                // The App Store applies it on its own; listed first, like the offer Play would pick.
                offers.insert([
                    "basePlanId": product.id,
                    "offerId": "introductory",
                    "offerTags": [String](),
                    "freeTrial": free,
                    "introductory": !free,
                    "phases": [introPhase, regular],
                ], at: 0)
            }
            json["offers"] = offers

            return json
        }

        static func purchase(_ transaction: Transaction, jws: String, acknowledged: Bool) async -> [String: Any] {
            var json: [String: Any] = [
                "platform": "ios",
                "productId": transaction.productID,
                "productIds": [transaction.productID],
                "type": StoreBilling.typeOf(transaction.productType),
                "purchaseToken": String(transaction.id),
                "transactionId": String(transaction.id),
                "originalTransactionId": String(transaction.originalID),
                "orderId": NSNull(),
                "purchaseTime": millis(transaction.purchaseDate),
                "state": "purchased",
                "pending": false,
                "acknowledged": acknowledged,
                "autoRenewing": await willAutoRenew(transaction),
                "quantity": transaction.purchasedQuantity,
                "accountId": transaction.appAccountToken?.uuidString.lowercased() ?? "",
                "profileId": "",
                "packageName": Bundle.main.bundleIdentifier ?? "",
                "revoked": transaction.revocationDate != nil,
                "jws": jws,
            ]
            if let expires = transaction.expirationDate {
                json["expiryTime"] = millis(expires)
            }
            if #available(iOS 16.0, *) {
                json["environment"] = transaction.environment.rawValue
            }
            return json
        }

        /// Whether an auto-renewable subscription will renew; false for everything else.
        private static func willAutoRenew(_ transaction: Transaction) async -> Bool {
            guard transaction.productType == .autoRenewable,
                  let product = try? await Product.products(for: [transaction.productID]).first,
                  let statuses = try? await product.subscription?.status else { return false }
            for status in statuses {
                guard case .verified(let current) = status.transaction,
                      current.originalID == transaction.originalID,
                      case .verified(let renewal) = status.renewalInfo else { continue }
                return renewal.willAutoRenew
            }
            return false
        }

        private static func phase(_ price: String, _ amount: Decimal, _ currency: String, _ period: String, cycles: Int, recurrence: String) -> [String: Any] {
            ["price": price, "micros": micros(amount), "currency": currency, "period": period, "cycles": cycles, "recurrence": recurrence]
        }

        private static func kind(_ type: Product.ProductType) -> String {
            switch type {
            case .autoRenewable: return "auto_renewable"
            case .nonRenewable: return "non_renewing"
            case .consumable: return "consumable"
            case .nonConsumable: return "non_consumable"
            default: return "unknown"
            }
        }

        /// ISO 8601 period, as Play reports it: P1M, P1Y, P1W, P3D.
        static func iso(_ period: Product.SubscriptionPeriod) -> String {
            switch period.unit {
            case .day: return "P\(period.value)D"
            case .week: return "P\(period.value)W"
            case .month: return "P\(period.value)M"
            case .year: return "P\(period.value)Y"
            @unknown default: return "P\(period.value)D"
            }
        }

        static func micros(_ amount: Decimal) -> Int64 {
            NSDecimalNumber(decimal: amount * 1_000_000).int64Value
        }

        static func millis(_ date: Date) -> Int64 {
            Int64(date.timeIntervalSince1970 * 1000)
        }
    }
}
