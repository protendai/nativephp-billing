export type ProductType = 'subs' | 'inapp';
export type PurchaseStatus = 'purchased' | 'pending' | 'cancelled' | 'already_owned' | 'failed';
export type ReplacementMode = 'with_time_proration' | 'charge_prorated_price' | 'charge_full_price' | 'without_proration' | 'deferred';

export interface Started { id: string; status: 'started' }

export interface PricingPhase { price: string; micros: number; currency: string; period: string; cycles: number; recurrence: 'infinite' | 'finite' | 'none' }
export interface SubscriptionOffer { basePlanId: string; offerId: string | null; offerTags: string[]; offerToken: string; freeTrial: boolean; introductory: boolean; phases: PricingPhase[] }
export interface BasePlan { basePlanId: string; price: string; micros: number; currency: string; period: string; offerToken: string }
export interface OneTimeOffer { offerId: string | null; offerTags: string[]; offerToken: string | null; price: string; micros: number; currency: string }
export interface Product {
    id: string; type: ProductType; title: string; name: string; description: string;
    plans: BasePlan[]; offers: SubscriptionOffer[];
    price?: string; micros?: number; currency?: string; oneTimeOffers: OneTimeOffer[];
}
export interface Purchase {
    productId: string; productIds: string[]; type: ProductType | null; purchaseToken: string; orderId: string | null;
    purchaseTime: number; state: 'purchased' | 'pending' | 'unspecified'; pending: boolean; acknowledged: boolean;
    autoRenewing: boolean; quantity: number; accountId: string; profileId: string; packageName: string;
    originalJson: string; signature: string;
}

export interface ProductsLoadedPayload { id: string | null; type: ProductType; products: Product[]; unfetched: string[]; error?: string; code?: string }
export interface PurchaseUpdatedPayload { id: string | null; status: PurchaseStatus; purchases: Purchase[]; message?: string; code?: string }
export interface PurchasesRestoredPayload { id: string | null; purchases: Purchase[]; error?: string; code?: string }
export interface PurchaseTokenResultPayload { id: string | null; purchaseToken: string; ok: boolean; error?: string; code?: string }

export interface PurchaseOptions {
    productId: string; type?: ProductType; basePlanId?: string; offerId?: string; offerTags?: string[];
    accountId?: string; profileId?: string; oldPurchaseToken?: string; replacementMode?: ReplacementMode; id?: string;
}

export const SUBS: 'subs';
export const INAPP: 'inapp';
export const Status: { Purchased: 'purchased'; Pending: 'pending'; Cancelled: 'cancelled'; AlreadyOwned: 'already_owned'; Failed: 'failed' };
export const Events: {
    ProductsLoaded: string; PurchaseUpdated: string; PurchasesRestored: string; PurchaseAcknowledged: string; PurchaseConsumed: string;
};

export function isAvailable(): Promise<boolean>;
export function products(productIds: string[], type?: ProductType, id?: string): Promise<Started>;
export function purchase(options: PurchaseOptions): Promise<Started>;
export function subscribe(options: Omit<PurchaseOptions, 'type'> & { basePlanId: string }): Promise<Started>;
export function buy(options: Pick<PurchaseOptions, 'productId' | 'accountId' | 'offerId' | 'profileId' | 'id'>): Promise<Started>;
export function restore(type?: ProductType, id?: string): Promise<Started>;
export function acknowledge(purchaseToken: string, id?: string): Promise<Started>;
export function consume(purchaseToken: string, id?: string): Promise<Started>;

export function on(eventName: string, callback: (payload: any) => void): () => void;
export function onProductsLoaded(cb: (payload: ProductsLoadedPayload) => void): () => void;
export function onPurchaseUpdated(cb: (payload: PurchaseUpdatedPayload) => void): () => void;
export function onPurchasesRestored(cb: (payload: PurchasesRestoredPayload) => void): () => void;
export function onPurchaseAcknowledged(cb: (payload: PurchaseTokenResultPayload) => void): () => void;
export function onPurchaseConsumed(cb: (payload: PurchaseTokenResultPayload) => void): () => void;

declare const Billing: {
    SUBS: typeof SUBS; INAPP: typeof INAPP; Status: typeof Status; Events: typeof Events;
    isAvailable: typeof isAvailable; products: typeof products; purchase: typeof purchase; subscribe: typeof subscribe;
    buy: typeof buy; restore: typeof restore; acknowledge: typeof acknowledge; consume: typeof consume;
    on: typeof on; onProductsLoaded: typeof onProductsLoaded; onPurchaseUpdated: typeof onPurchaseUpdated;
    onPurchasesRestored: typeof onPurchasesRestored; onPurchaseAcknowledged: typeof onPurchaseAcknowledged; onPurchaseConsumed: typeof onPurchaseConsumed;
};
export { Billing };
export default Billing;
