import Foundation

/// iOS is not supported yet (StoreKit 2 is on the roadmap). Every function
/// answers with an UNSUPPORTED bridge error straight away, so PHP gets a
/// BillingException and JavaScript a rejected promise instead of an event
/// that never comes. Billing::available() is false on iOS: check it first.
/// Namespace: "ProtendaiBilling.*"
enum ProtendaiBillingFunctions {

    static let message = "In-app purchases through this plugin are only available on Android (Google Play) for now."

    class Unsupported: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.error(code: "UNSUPPORTED", message: ProtendaiBillingFunctions.message)
        }
    }

    class Products: Unsupported {}
    class Purchase: Unsupported {}
    class Restore: Unsupported {}
    class Acknowledge: Unsupported {}
    class Consume: Unsupported {}
}
