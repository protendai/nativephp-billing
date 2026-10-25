package com.protendai.plugins.billing

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingFlowParams
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.PendingPurchasesParams
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase
import com.android.billingclient.api.PurchasesUpdatedListener
import com.android.billingclient.api.QueryProductDetailsParams
import com.android.billingclient.api.QueryPurchasesParams
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject

/**
 * Google Play subscriptions for fTree.
 * Namespace: "FtreeBilling.*" — every function answers with an event.
 *
 * Purchases are not acknowledged here: the fTree server checks each one with
 * Google and acknowledges it.
 */
object BillingFunctions {

    private const val TAG = "FtreeBilling"
    private const val EVENTS = "Ftree\\Billing\\Events\\"

    class Products(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val ids = (parameters["productIds"] as? List<*>)?.mapNotNull { it as? String } ?: emptyList()
            PlayBilling.withClient(activity) { client, error ->
                if (client == null) {
                    PlayBilling.dispatch(activity, "ProductsLoaded", JSONObject().put("products", JSONArray()).put("error", error))
                    return@withClient
                }
                PlayBilling.loadProducts(client, ids) { details, problem ->
                    val products = JSONArray()
                    details.forEach { products.put(PlayBilling.describe(it)) }
                    val payload = JSONObject().put("products", products)
                    problem?.let { payload.put("error", it) }
                    PlayBilling.dispatch(activity, "ProductsLoaded", payload)
                }
            }

            return emptyMap()
        }
    }

    class Subscribe(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val productId = parameters["productId"] as? String ?: ""
            val basePlanId = parameters["basePlanId"] as? String ?: ""
            val accountId = parameters["accountId"] as? String ?: ""
            val oldToken = parameters["oldPurchaseToken"] as? String

            PlayBilling.withClient(activity) { client, error ->
                if (client == null) {
                    PlayBilling.purchaseResult(activity, "failed", emptyList(), error)
                    return@withClient
                }
                PlayBilling.loadProducts(client, listOf(productId)) { details, problem ->
                    val product = details.firstOrNull()
                    val offer = product?.subscriptionOfferDetails
                        ?.filter { it.basePlanId == basePlanId }
                        // The base plan itself (no introductory offer) comes without an offer id.
                        ?.sortedBy { if (it.offerId == null) 0 else 1 }
                        ?.firstOrNull()

                    if (product == null || offer == null) {
                        PlayBilling.purchaseResult(activity, "failed", emptyList(), problem ?: "This plan isn’t available in Google Play yet.")
                        return@loadProducts
                    }

                    val flow = BillingFlowParams.newBuilder()
                        .setProductDetailsParamsList(listOf(
                            BillingFlowParams.ProductDetailsParams.newBuilder()
                                .setProductDetails(product)
                                .setOfferToken(offer.offerToken)
                                .build()
                        ))
                    if (accountId.isNotEmpty()) {
                        flow.setObfuscatedAccountId(accountId)
                    }
                    if (!oldToken.isNullOrEmpty()) {
                        flow.setSubscriptionUpdateParams(
                            BillingFlowParams.SubscriptionUpdateParams.newBuilder()
                                .setOldPurchaseToken(oldToken)
                                .setSubscriptionReplacementMode(BillingFlowParams.SubscriptionUpdateParams.ReplacementMode.WITH_TIME_PRORATION)
                                .build()
                        )
                    }

                    Handler(Looper.getMainLooper()).post {
                        val result = client.launchBillingFlow(activity, flow.build())
                        if (result.responseCode != BillingClient.BillingResponseCode.OK) {
                            PlayBilling.purchaseResult(activity, PlayBilling.statusFor(result), emptyList(), result.debugMessage)
                        }
                    }
                }
            }

            return emptyMap()
        }
    }

    class Restore(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            PlayBilling.withClient(activity) { client, error ->
                if (client == null) {
                    PlayBilling.dispatch(activity, "PurchasesRestored", JSONObject().put("purchases", JSONArray()).put("error", error))
                    return@withClient
                }
                client.queryPurchasesAsync(
                    QueryPurchasesParams.newBuilder().setProductType(BillingClient.ProductType.SUBS).build()
                ) { result, purchases ->
                    val payload = JSONObject().put("purchases", PlayBilling.purchasesJson(purchases))
                    if (result.responseCode != BillingClient.BillingResponseCode.OK) {
                        payload.put("error", result.debugMessage)
                    }
                    PlayBilling.dispatch(activity, "PurchasesRestored", payload)
                }
            }

            return emptyMap()
        }
    }

    /** One BillingClient for the app, connected when first needed. */
    internal object PlayBilling {
        private var client: BillingClient? = null
        private var connecting = false
        private val waiting = mutableListOf<(BillingClient?, String?) -> Unit>()
        private var activityRef: FragmentActivity? = null

        private val purchasesListener = PurchasesUpdatedListener { result, purchases ->
            val activity = activityRef ?: return@PurchasesUpdatedListener
            val list = purchases ?: emptyList()
            val status = when {
                result.responseCode == BillingClient.BillingResponseCode.OK &&
                    list.any { it.purchaseState == Purchase.PurchaseState.PENDING } -> "pending"
                else -> statusFor(result)
            }
            purchaseResult(activity, status, list, result.debugMessage)
        }

        fun withClient(activity: FragmentActivity, then: (BillingClient?, String?) -> Unit) {
            activityRef = activity
            Handler(Looper.getMainLooper()).post {
                val ready = client
                if (ready != null && ready.isReady) {
                    then(ready, null)
                    return@post
                }
                waiting.add(then)
                if (connecting) return@post
                connecting = true

                val fresh = ready ?: BillingClient.newBuilder(activity.applicationContext)
                    .setListener(purchasesListener)
                    .enablePendingPurchases(PendingPurchasesParams.newBuilder().enableOneTimeProducts().build())
                    .build()
                client = fresh
                fresh.startConnection(object : BillingClientStateListener {
                    override fun onBillingSetupFinished(result: BillingResult) {
                        Handler(Looper.getMainLooper()).post {
                            connecting = false
                            val ok = result.responseCode == BillingClient.BillingResponseCode.OK
                            val calls = waiting.toList()
                            waiting.clear()
                            calls.forEach { it(if (ok) fresh else null, if (ok) null else messageFor(result)) }
                        }
                    }

                    override fun onBillingServiceDisconnected() {
                        Log.d(TAG, "Billing service disconnected; reconnecting on next call")
                        connecting = false
                    }
                })
            }
        }

        fun loadProducts(client: BillingClient, ids: List<String>, then: (List<ProductDetails>, String?) -> Unit) {
            if (ids.isEmpty()) {
                then(emptyList(), null)
                return
            }
            val params = QueryProductDetailsParams.newBuilder()
                .setProductList(ids.map {
                    QueryProductDetailsParams.Product.newBuilder()
                        .setProductId(it)
                        .setProductType(BillingClient.ProductType.SUBS)
                        .build()
                })
                .build()
            client.queryProductDetailsAsync(params) { result, details ->
                val list = details.productDetailsList
                Handler(Looper.getMainLooper()).post {
                    then(list, if (result.responseCode == BillingClient.BillingResponseCode.OK) null else messageFor(result))
                }
            }
        }

        fun describe(product: ProductDetails): JSONObject {
            val plans = JSONArray()
            product.subscriptionOfferDetails
                ?.filter { it.offerId == null }
                ?.forEach { offer ->
                    // The base plan's recurring price is its last pricing phase.
                    val phase = offer.pricingPhases.pricingPhaseList.lastOrNull() ?: return@forEach
                    plans.put(JSONObject()
                        .put("basePlanId", offer.basePlanId)
                        .put("price", phase.formattedPrice)
                        .put("period", phase.billingPeriod)
                        .put("micros", phase.priceAmountMicros)
                        .put("currency", phase.priceCurrencyCode))
                }

            return JSONObject()
                .put("id", product.productId)
                .put("title", product.name)
                .put("plans", plans)
        }

        fun purchasesJson(purchases: List<Purchase>): JSONArray {
            val list = JSONArray()
            purchases.forEach { purchase ->
                purchase.products.forEach { productId ->
                    list.put(JSONObject()
                        .put("productId", productId)
                        .put("purchaseToken", purchase.purchaseToken)
                        .put("acknowledged", purchase.isAcknowledged)
                        .put("pending", purchase.purchaseState == Purchase.PurchaseState.PENDING)
                        .put("accountId", purchase.accountIdentifiers?.obfuscatedAccountId ?: ""))
                }
            }
            return list
        }

        fun purchaseResult(activity: FragmentActivity, status: String, purchases: List<Purchase>, message: String?) {
            val payload = JSONObject()
                .put("status", status)
                .put("purchases", purchasesJson(purchases))
            if (!message.isNullOrEmpty() && status != "purchased") {
                payload.put("message", message)
            }
            dispatch(activity, "PurchaseUpdated", payload)
        }

        fun statusFor(result: BillingResult): String = when (result.responseCode) {
            BillingClient.BillingResponseCode.OK -> "purchased"
            BillingClient.BillingResponseCode.USER_CANCELED -> "cancelled"
            BillingClient.BillingResponseCode.ITEM_ALREADY_OWNED -> "already_owned"
            else -> "failed"
        }

        fun messageFor(result: BillingResult): String = when (result.responseCode) {
            BillingClient.BillingResponseCode.BILLING_UNAVAILABLE -> "Google Play billing isn’t available on this phone."
            BillingClient.BillingResponseCode.SERVICE_UNAVAILABLE,
            BillingClient.BillingResponseCode.SERVICE_DISCONNECTED,
            BillingClient.BillingResponseCode.NETWORK_ERROR -> "Couldn’t reach Google Play. Check your connection and try again."
            else -> result.debugMessage.ifEmpty { "Google Play couldn’t do that (code ${result.responseCode})." }
        }

        fun dispatch(activity: FragmentActivity, event: String, payload: JSONObject) {
            Handler(Looper.getMainLooper()).post {
                NativeActionCoordinator.dispatchEvent(activity, EVENTS + event, payload.toString())
            }
        }
    }
}
