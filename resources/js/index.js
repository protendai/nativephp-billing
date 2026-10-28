/**
 * protendai/nativephp-billing: in-app purchases for NativePHP Mobile (Inertia / Vue / React),
 * Google Play Billing on Android and StoreKit 2 on iOS.
 *
 * Every call resolves straight away with { id, status: 'started' }; the result arrives
 * later as a native event carrying the same id. Listen with onProductsLoaded(),
 * onPurchaseUpdated() etc. (or the core's On(Events.X, cb)).
 *
 * On iOS each subscription period is its own App Store product: pick product ids with platform().
 *
 * @example
 * import { products, subscribe, onPurchaseUpdated } from '@protendai/nativephp-billing';
 *
 * onPurchaseUpdated(({ status, purchases }) => {
 *     if (status === 'purchased') verifyOnServer(purchases[0].purchaseToken);
 * });
 * await subscribe({ productId: 'premium', basePlanId: 'monthly', accountId: userId });
 */

const baseUrl = '/_native/api/call';

/** Product type: auto-renewing subscriptions. */
export const SUBS = 'subs';

/** Product type: one-time products (consumable or non-consumable). */
export const INAPP = 'inapp';

/** PurchaseUpdated statuses. */
export const Status = Object.freeze({
    Purchased: 'purchased',
    Pending: 'pending',
    Cancelled: 'cancelled',
    AlreadyOwned: 'already_owned',
    Failed: 'failed',
    /** iOS only: a purchase was refunded or revoked while the app was open. */
    Revoked: 'revoked',
});

/** Event class names, as dispatched by the native side. */
export const Events = Object.freeze({
    ProductsLoaded: 'Protendai\\Billing\\Events\\ProductsLoaded',
    PurchaseUpdated: 'Protendai\\Billing\\Events\\PurchaseUpdated',
    PurchasesRestored: 'Protendai\\Billing\\Events\\PurchasesRestored',
    PurchaseAcknowledged: 'Protendai\\Billing\\Events\\PurchaseAcknowledged',
    PurchaseConsumed: 'Protendai\\Billing\\Events\\PurchaseConsumed',
});

/**
 * Call a bridge function through the NativePHP runtime.
 * @private
 * @param {string} method
 * @param {object} params
 * @returns {Promise<{id: string, status: string}>}
 */
async function bridgeCall(method, params = {}) {
    const clean = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
    );

    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ method, params: clean }),
    });

    const result = await response.json();

    if (result.status === 'error') {
        const error = new Error(result.message || 'Billing could not start');
        error.code = result.code;
        throw error;
    }

    const data = result.data;
    if (data && data.status === 'error') {
        const error = new Error(data.message || 'Billing could not start');
        error.code = data.code;
        throw error;
    }

    return data && data.data !== undefined ? data.data : data;
}

/**
 * Whether purchases can be made here (an Android or iOS build with this plugin).
 * @returns {Promise<boolean>}
 */
export async function isAvailable() {
    return (await platform()) !== null;
}

/**
 * The store this build sells through: 'android' (Google Play), 'ios' (App Store), or null.
 * @returns {Promise<'android'|'ios'|null>}
 */
export async function platform() {
    try {
        const response = await fetch(baseUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ method: 'Device.GetInfo', params: {} }),
        });
        const result = await response.json();
        // Device.GetInfo answers { info: "<json>" }, with platform "android" or "ios".
        const data = result.data?.data ?? result.data;
        const info = typeof data?.info === 'string' ? JSON.parse(data.info) : (data?.info ?? data);
        return ['android', 'ios'].includes(info?.platform) ? info.platform : null;
    } catch {
        return null;
    }
}

/**
 * Load products with prices, base plans and offers. Answer: ProductsLoaded.
 * @param {string[]} productIds
 * @param {'subs'|'inapp'} [type='subs']
 * @param {string} [id] Request id echoed on the event
 */
export async function products(productIds, type = SUBS, id) {
    return bridgeCall('ProtendaiBilling.Products', { productIds: [...new Set(productIds)], type, id });
}

/**
 * Open the purchase sheet with any option. Answer: PurchaseUpdated.
 * @param {object} options
 * @param {string} options.productId
 * @param {'subs'|'inapp'} [options.type='subs']
 * @param {string} [options.basePlanId] Required for subscriptions
 * @param {string} [options.offerId]
 * @param {string[]} [options.offerTags]
 * @param {string} [options.accountId] Your id for the buyer (no personal data)
 * @param {string} [options.profileId]
 * @param {string} [options.oldPurchaseToken] Subscription this one replaces
 * @param {'with_time_proration'|'charge_prorated_price'|'charge_full_price'|'without_proration'|'deferred'} [options.replacementMode]
 * @param {string} [options.id]
 */
export async function purchase(options) {
    return bridgeCall('ProtendaiBilling.Purchase', { type: SUBS, ...options });
}

/**
 * Subscribe to a base plan (optionally an offer: free trial, intro price). Answer: PurchaseUpdated.
 * @param {object} options Same as purchase(), without type
 */
export async function subscribe(options) {
    return purchase({ ...options, type: SUBS });
}

/**
 * Buy a one-time product. Answer: PurchaseUpdated.
 * @param {object} options productId, accountId, offerId, profileId, id
 */
export async function buy(options) {
    return purchase({ ...options, type: INAPP });
}

/**
 * List what the Google or Apple account owns now. Answer: PurchasesRestored.
 * @param {'subs'|'inapp'} [type] Omit for both
 * @param {string} [id]
 * @param {boolean} [sync] iOS: ask the App Store first (may ask the user to sign in; only from a Restore button)
 */
export async function restore(type, id, sync = false) {
    return bridgeCall('ProtendaiBilling.Restore', { type, id, sync: sync || undefined });
}

/**
 * Acknowledge a purchase on the device (apps without a server). Answer: PurchaseAcknowledged.
 * @param {string} purchaseToken
 * @param {string} [id]
 */
export async function acknowledge(purchaseToken, id) {
    return bridgeCall('ProtendaiBilling.Acknowledge', { purchaseToken, id });
}

/**
 * Consume a one-time purchase so it can be bought again. Answer: PurchaseConsumed.
 * @param {string} purchaseToken
 * @param {string} [id]
 */
export async function consume(purchaseToken, id) {
    return bridgeCall('ProtendaiBilling.Consume', { purchaseToken, id });
}

/**
 * Listen for one of this plugin's events. Returns a function that stops listening.
 * @param {string} eventName One of Events.*
 * @param {(payload: object) => void} callback
 * @returns {() => void}
 */
export function on(eventName, callback) {
    const handler = (e) => {
        const name = String(e.detail?.event ?? '').replace(/^\\+/, '');
        if (name === eventName) callback(e.detail.payload);
    };
    document.addEventListener('native-event', handler);
    return () => document.removeEventListener('native-event', handler);
}

/** @param {(payload: {id: string|null, type: string, products: object[], unfetched: string[], error?: string, code?: string}) => void} cb */
export const onProductsLoaded = (cb) => on(Events.ProductsLoaded, cb);
/** @param {(payload: {id: string|null, status: string, purchases: object[], message?: string, code?: string}) => void} cb */
export const onPurchaseUpdated = (cb) => on(Events.PurchaseUpdated, cb);
/** @param {(payload: {id: string|null, purchases: object[], error?: string, code?: string}) => void} cb */
export const onPurchasesRestored = (cb) => on(Events.PurchasesRestored, cb);
/** @param {(payload: {id: string|null, purchaseToken: string, ok: boolean, error?: string, code?: string}) => void} cb */
export const onPurchaseAcknowledged = (cb) => on(Events.PurchaseAcknowledged, cb);
/** @param {(payload: {id: string|null, purchaseToken: string, ok: boolean, error?: string, code?: string}) => void} cb */
export const onPurchaseConsumed = (cb) => on(Events.PurchaseConsumed, cb);

// The bridge functions under their own names (ProtendaiBilling.Products and so on).
export { products as Products, purchase as Purchase, restore as Restore, acknowledge as Acknowledge, consume as Consume };

export const Billing = {
    SUBS, INAPP, Status, Events,
    isAvailable, platform, products, purchase, subscribe, buy, restore, acknowledge, consume,
    on, onProductsLoaded, onPurchaseUpdated, onPurchasesRestored, onPurchaseAcknowledged, onPurchaseConsumed,
};

export default Billing;
