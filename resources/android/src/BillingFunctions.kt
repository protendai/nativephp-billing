package com.protendai.billing

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.android.billingclient.api.AcknowledgePurchaseParams
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingFlowParams
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.ConsumeParams
import com.android.billingclient.api.PendingPurchasesParams
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase as PlayPurchase
import com.android.billingclient.api.PurchasesUpdatedListener
import com.android.billingclient.api.QueryProductDetailsParams
import com.android.billingclient.api.QueryPurchasesParams
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.lang.ref.WeakReference
import java.util.UUID

/**
 * Google Play Billing (Play Billing Library 8) for NativePHP Mobile.
 * Bridge namespace: "ProtendaiBilling.*".
 *
 * Every function returns straight away with {id, status: "started"} (or a
 * bridge error for bad arguments) and answers later with one event carrying
 * the same id: ProductsLoaded, PurchaseUpdated, PurchasesRestored,
 * PurchaseAcknowledged or PurchaseConsumed.
 *
 * Purchases are never acknowledged or consumed automatically: verify them on
 * a server (which acknowledges them), or call Acknowledge / Consume.
 */
object BillingFunctions {

    internal const val TAG = "ProtendaiBilling"
    internal const val EVENTS = "Protendai\\Billing\\Events\\"

    /**
     * Load products and their offers.
     * Parameters: productIds (list of strings), type ("subs" | "inapp", default "subs"), id.
     * Event: ProductsLoaded {id, type, products, unfetched, error, code}.
     */
    class Products(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = Params.id(parameters)
            val ids = Params.stringList(parameters["productIds"])
            val type = Params.productType(parameters["type"], BillingClient.ProductType.SUBS)
                ?: return BridgeResponse.error("INVALID_ARGUMENT", "type must be \"subs\" or \"inapp\"")
            if (ids.isEmpty()) {
                return BridgeResponse.error("INVALID_ARGUMENT", "productIds must list at least one product id")
            }

            PlayBilling.attach(activity)
            PlayBilling.withClient { client, failure ->
                if (client == null) {
                    PlayBilling.dispatch("ProductsLoaded", JSONObject().put("id", id).put("type", type)
                        .put("products", JSONArray()).put("unfetched", JSONArray(ids)).failure(failure))
                    return@withClient
                }
                PlayBilling.loadProducts(client, ids, type) { details, unfetched, problem ->
                    val products = JSONArray()
                    details.forEach { products.put(Describe.product(it)) }
                    PlayBilling.dispatch("ProductsLoaded", JSONObject().put("id", id).put("type", type)
                        .put("products", products).put("unfetched", JSONArray(unfetched)).failure(problem))
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /**
     * Open Google Play's purchase sheet.
     * Parameters: productId, type ("subs" | "inapp", default "subs"), basePlanId (subs),
     * offerId, offerTags (list), accountId, profileId, oldPurchaseToken (subs),
     * replacementMode (subs), id.
     * Event: PurchaseUpdated {id, status, purchases, message, code}.
     */
    class Purchase(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = Params.id(parameters)
            val productId = Params.string(parameters["productId"])
                ?: return BridgeResponse.error("INVALID_ARGUMENT", "productId is required")
            val type = Params.productType(parameters["type"], BillingClient.ProductType.SUBS)
                ?: return BridgeResponse.error("INVALID_ARGUMENT", "type must be \"subs\" or \"inapp\"")
            val basePlanId = Params.string(parameters["basePlanId"])
            if (type == BillingClient.ProductType.SUBS && basePlanId == null) {
                return BridgeResponse.error("INVALID_ARGUMENT", "basePlanId is required for a subscription")
            }
            val offerId = Params.string(parameters["offerId"])
            val offerTags = Params.stringList(parameters["offerTags"])
            val accountId = Params.string(parameters["accountId"])
            val profileId = Params.string(parameters["profileId"])
            val oldToken = Params.string(parameters["oldPurchaseToken"])
            val replacementMode = Params.replacementMode(parameters["replacementMode"])
                ?: return BridgeResponse.error("INVALID_ARGUMENT", "unknown replacementMode")

            PlayBilling.attach(activity)
            PlayBilling.withClient { client, failure ->
                if (client == null) {
                    PlayBilling.purchaseResult(id, type, "failed", emptyList(), failure)
                    return@withClient
                }
                PlayBilling.loadProducts(client, listOf(productId), type) { details, _, problem ->
                    val product = details.firstOrNull()
                    if (product == null) {
                        PlayBilling.purchaseResult(id, type, "failed", emptyList(),
                            problem ?: Failure("item_unavailable", "This product isn’t available in Google Play."))
                        return@loadProducts
                    }

                    val productParams = BillingFlowParams.ProductDetailsParams.newBuilder().setProductDetails(product)
                    if (type == BillingClient.ProductType.SUBS) {
                        val offer = Offers.pickSubscription(product, basePlanId!!, offerId, offerTags)
                        if (offer == null) {
                            PlayBilling.purchaseResult(id, type, "failed", emptyList(),
                                Failure("offer_unavailable", "That plan or offer isn’t available for this account."))
                            return@loadProducts
                        }
                        productParams.setOfferToken(offer.offerToken)
                    } else if (offerId != null || offerTags.isNotEmpty()) {
                        val token = Offers.pickOneTime(product, offerId, offerTags)
                        if (token == null) {
                            PlayBilling.purchaseResult(id, type, "failed", emptyList(),
                                Failure("offer_unavailable", "That offer isn’t available for this account."))
                            return@loadProducts
                        }
                        productParams.setOfferToken(token)
                    }

                    val flow = BillingFlowParams.newBuilder().setProductDetailsParamsList(listOf(productParams.build()))
                    accountId?.let { flow.setObfuscatedAccountId(it) }
                    profileId?.let { flow.setObfuscatedProfileId(it) }
                    if (type == BillingClient.ProductType.SUBS && oldToken != null) {
                        flow.setSubscriptionUpdateParams(
                            BillingFlowParams.SubscriptionUpdateParams.newBuilder()
                                .setOldPurchaseToken(oldToken)
                                .setSubscriptionReplacementMode(replacementMode)
                                .build()
                        )
                    }

                    PlayBilling.launch(client, flow.build(), id, type)
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /**
     * Purchases this Google account owns now: active subscriptions and
     * unconsumed one-time products (including pending ones).
     * Parameters: type ("subs" | "inapp", or omitted for both), id.
     * Event: PurchasesRestored {id, purchases, error, code}.
     */
    class Restore(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = Params.id(parameters)
            val types = if (Params.string(parameters["type"]) == null) {
                listOf(BillingClient.ProductType.SUBS, BillingClient.ProductType.INAPP)
            } else {
                listOf(Params.productType(parameters["type"], BillingClient.ProductType.SUBS)
                    ?: return BridgeResponse.error("INVALID_ARGUMENT", "type must be \"subs\" or \"inapp\""))
            }

            PlayBilling.attach(activity)
            PlayBilling.withClient { client, failure ->
                if (client == null) {
                    PlayBilling.dispatch("PurchasesRestored", JSONObject().put("id", id).put("purchases", JSONArray()).failure(failure))
                    return@withClient
                }
                PlayBilling.queryPurchases(client, types) { purchases, problem ->
                    PlayBilling.dispatch("PurchasesRestored", JSONObject().put("id", id).put("purchases", purchases).failure(problem))
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /**
     * Acknowledge a purchase on the device (apps without a server). Play refunds
     * purchases that aren't acknowledged (or consumed) within three days.
     * Parameters: purchaseToken, id. Event: PurchaseAcknowledged {id, purchaseToken, ok, error, code}.
     */
    class Acknowledge(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = Params.id(parameters)
            val token = Params.string(parameters["purchaseToken"])
                ?: return BridgeResponse.error("INVALID_ARGUMENT", "purchaseToken is required")

            PlayBilling.attach(activity)
            PlayBilling.withClient { client, failure ->
                val reply = { problem: Failure? ->
                    PlayBilling.dispatch("PurchaseAcknowledged", JSONObject().put("id", id).put("purchaseToken", token)
                        .put("ok", problem == null).failure(problem))
                }
                if (client == null) {
                    reply(failure)
                    return@withClient
                }
                client.acknowledgePurchase(AcknowledgePurchaseParams.newBuilder().setPurchaseToken(token).build()) { result ->
                    reply(Failure.of(result))
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /**
     * Consume a one-time purchase so it can be bought again (consumables).
     * Consuming also acknowledges it.
     * Parameters: purchaseToken, id. Event: PurchaseConsumed {id, purchaseToken, ok, error, code}.
     */
    class Consume(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = Params.id(parameters)
            val token = Params.string(parameters["purchaseToken"])
                ?: return BridgeResponse.error("INVALID_ARGUMENT", "purchaseToken is required")

            PlayBilling.attach(activity)
            PlayBilling.withClient { client, failure ->
                val reply = { problem: Failure? ->
                    PlayBilling.dispatch("PurchaseConsumed", JSONObject().put("id", id).put("purchaseToken", token)
                        .put("ok", problem == null).failure(problem))
                }
                if (client == null) {
                    reply(failure)
                    return@withClient
                }
                client.consumeAsync(ConsumeParams.newBuilder().setPurchaseToken(token).build()) { result, _ ->
                    reply(Failure.of(result))
                }
            }

            return BridgeResponse.success(mapOf("id" to id, "status" to "started"))
        }
    }

    /** Why something didn't work: a stable code for apps, and a readable message. */
    internal data class Failure(val code: String, val message: String) {
        companion object {
            /** Null when the result is OK. */
            fun of(result: BillingResult): Failure? =
                if (result.responseCode == BillingClient.BillingResponseCode.OK) null
                else Failure(codeFor(result.responseCode), messageFor(result))

            fun codeFor(responseCode: Int): String = when (responseCode) {
                BillingClient.BillingResponseCode.OK -> "ok"
                BillingClient.BillingResponseCode.USER_CANCELED -> "user_canceled"
                BillingClient.BillingResponseCode.SERVICE_UNAVAILABLE -> "service_unavailable"
                BillingClient.BillingResponseCode.BILLING_UNAVAILABLE -> "billing_unavailable"
                BillingClient.BillingResponseCode.ITEM_UNAVAILABLE -> "item_unavailable"
                BillingClient.BillingResponseCode.DEVELOPER_ERROR -> "developer_error"
                BillingClient.BillingResponseCode.ITEM_ALREADY_OWNED -> "item_already_owned"
                BillingClient.BillingResponseCode.ITEM_NOT_OWNED -> "item_not_owned"
                BillingClient.BillingResponseCode.SERVICE_DISCONNECTED -> "service_disconnected"
                BillingClient.BillingResponseCode.FEATURE_NOT_SUPPORTED -> "feature_not_supported"
                BillingClient.BillingResponseCode.NETWORK_ERROR -> "network_error"
                else -> "error"
            }

            private fun messageFor(result: BillingResult): String = when (result.responseCode) {
                BillingClient.BillingResponseCode.BILLING_UNAVAILABLE -> "Google Play billing isn’t available on this device or account."
                BillingClient.BillingResponseCode.SERVICE_UNAVAILABLE,
                BillingClient.BillingResponseCode.SERVICE_DISCONNECTED,
                BillingClient.BillingResponseCode.NETWORK_ERROR -> "Couldn’t reach Google Play. Check the connection and try again."
                BillingClient.BillingResponseCode.ITEM_ALREADY_OWNED -> "This Google account already owns that item."
                BillingClient.BillingResponseCode.ITEM_NOT_OWNED -> "This Google account doesn’t own that item."
                BillingClient.BillingResponseCode.ITEM_UNAVAILABLE -> "That item isn’t available in Google Play."
                else -> result.debugMessage.ifEmpty { "Google Play couldn’t do that (code ${result.responseCode})." }
            }
        }
    }

    /** Puts error/code on a payload when there was a failure. */
    internal fun JSONObject.failure(failure: Failure?): JSONObject {
        if (failure != null) {
            put("error", failure.message)
            put("code", failure.code)
        }
        return this
    }

    internal object Params {
        fun id(parameters: Map<String, Any>): String = string(parameters["id"]) ?: UUID.randomUUID().toString()

        /** Strings only; JSONObject.NULL and blanks count as missing. */
        fun string(value: Any?): String? = (value as? String)?.takeIf { it.isNotBlank() }

        /** The bridge hands nested JSON arrays over as JSONArray, not List. */
        fun stringList(value: Any?): List<String> {
            val items: List<Any?> = when (value) {
                is JSONArray -> (0 until value.length()).map { value.opt(it) }
                is List<*> -> value
                is String -> listOf(value)
                else -> emptyList()
            }
            return items.mapNotNull { string(it) }.distinct()
        }

        fun productType(value: Any?, default: String): String? = when (string(value)?.lowercase()) {
            null -> default
            "subs", "subscription" -> BillingClient.ProductType.SUBS
            "inapp", "one_time" -> BillingClient.ProductType.INAPP
            else -> null
        }

        /** Default WITH_TIME_PRORATION: the new plan starts now, unused time is credited. */
        fun replacementMode(value: Any?): Int? = when (string(value)?.lowercase()) {
            null, "with_time_proration" -> BillingFlowParams.SubscriptionUpdateParams.ReplacementMode.WITH_TIME_PRORATION
            "charge_prorated_price" -> BillingFlowParams.SubscriptionUpdateParams.ReplacementMode.CHARGE_PRORATED_PRICE
            "charge_full_price" -> BillingFlowParams.SubscriptionUpdateParams.ReplacementMode.CHARGE_FULL_PRICE
            "without_proration" -> BillingFlowParams.SubscriptionUpdateParams.ReplacementMode.WITHOUT_PRORATION
            "deferred" -> BillingFlowParams.SubscriptionUpdateParams.ReplacementMode.DEFERRED
            else -> null
        }
    }

    internal object Offers {
        /**
         * The offer to buy for a subscription base plan: the named offer, else the
         * cheapest offer carrying all the tags, else the base plan itself.
         * Play only lists offers this account is eligible for.
         */
        fun pickSubscription(
            product: ProductDetails,
            basePlanId: String,
            offerId: String?,
            offerTags: List<String>,
        ): ProductDetails.SubscriptionOfferDetails? {
            val offers = product.subscriptionOfferDetails?.filter { it.basePlanId == basePlanId } ?: return null
            if (offerId != null) {
                return offers.firstOrNull { it.offerId == offerId }
            }
            if (offerTags.isNotEmpty()) {
                offers.filter { it.offerTags.containsAll(offerTags) }
                    .minByOrNull { it.pricingPhases.pricingPhaseList.firstOrNull()?.priceAmountMicros ?: Long.MAX_VALUE }
                    ?.let { return it }
            }
            return offers.firstOrNull { it.offerId == null }
        }

        /** The offer token of a one-time product's offer (Play Billing 8 purchase options and offers). */
        fun pickOneTime(product: ProductDetails, offerId: String?, offerTags: List<String>): String? {
            val offers = product.oneTimePurchaseOfferDetailsList ?: return null
            val match = if (offerId != null) {
                offers.firstOrNull { it.offerId == offerId }
            } else {
                offers.filter { (it.offerTags ?: emptyList<String>()).containsAll(offerTags) }.minByOrNull { it.priceAmountMicros }
            }
            return match?.offerToken
        }
    }

    internal object Describe {
        fun product(product: ProductDetails): JSONObject {
            val json = JSONObject()
                .put("id", product.productId)
                .put("type", product.productType)
                .put("title", product.title)
                .put("name", product.name)
                .put("description", product.description)

            // Subscriptions: every base plan and offer this account may buy.
            val offers = JSONArray()
            val plans = JSONArray()
            product.subscriptionOfferDetails?.forEach { offer ->
                val phases = JSONArray()
                offer.pricingPhases.pricingPhaseList.forEach { phase ->
                    phases.put(JSONObject()
                        .put("price", phase.formattedPrice)
                        .put("micros", phase.priceAmountMicros)
                        .put("currency", phase.priceCurrencyCode)
                        .put("period", phase.billingPeriod)
                        .put("cycles", phase.billingCycleCount)
                        .put("recurrence", when (phase.recurrenceMode) {
                            ProductDetails.RecurrenceMode.INFINITE_RECURRING -> "infinite"
                            ProductDetails.RecurrenceMode.FINITE_RECURRING -> "finite"
                            else -> "none"
                        }))
                }
                val list = offer.pricingPhases.pricingPhaseList
                // The recurring price is the last phase; earlier ones are trials or intro prices.
                val recurring = list.lastOrNull()
                offers.put(JSONObject()
                    .put("basePlanId", offer.basePlanId)
                    .put("offerId", offer.offerId ?: JSONObject.NULL)
                    .put("offerTags", JSONArray(offer.offerTags))
                    .put("offerToken", offer.offerToken)
                    .put("freeTrial", list.dropLast(1).any { it.priceAmountMicros == 0L })
                    .put("introductory", list.dropLast(1).any { it.priceAmountMicros > 0L })
                    .put("phases", phases))
                if (offer.offerId == null && recurring != null) {
                    plans.put(JSONObject()
                        .put("basePlanId", offer.basePlanId)
                        .put("price", recurring.formattedPrice)
                        .put("micros", recurring.priceAmountMicros)
                        .put("currency", recurring.priceCurrencyCode)
                        .put("period", recurring.billingPeriod)
                        .put("offerToken", offer.offerToken))
                }
            }
            json.put("plans", plans).put("offers", offers)

            // One-time products: the default price, plus any purchase options and offers.
            product.oneTimePurchaseOfferDetails?.let { base ->
                json.put("price", base.formattedPrice)
                    .put("micros", base.priceAmountMicros)
                    .put("currency", base.priceCurrencyCode)
            }
            val oneTime = JSONArray()
            product.oneTimePurchaseOfferDetailsList?.forEach { offer ->
                oneTime.put(JSONObject()
                    .put("offerId", offer.offerId ?: JSONObject.NULL)
                    .put("offerTags", JSONArray(offer.offerTags ?: emptyList<String>()))
                    .put("offerToken", offer.offerToken ?: JSONObject.NULL)
                    .put("price", offer.formattedPrice)
                    .put("micros", offer.priceAmountMicros)
                    .put("currency", offer.priceCurrencyCode))
            }
            json.put("oneTimeOffers", oneTime)

            return json
        }

        fun purchase(purchase: PlayPurchase, type: String?): JSONObject = JSONObject()
            .put("productId", purchase.products.firstOrNull() ?: "")
            .put("productIds", JSONArray(purchase.products))
            .put("type", type ?: JSONObject.NULL)
            .put("purchaseToken", purchase.purchaseToken)
            .put("orderId", purchase.orderId ?: JSONObject.NULL)
            .put("purchaseTime", purchase.purchaseTime)
            .put("state", when (purchase.purchaseState) {
                PlayPurchase.PurchaseState.PURCHASED -> "purchased"
                PlayPurchase.PurchaseState.PENDING -> "pending"
                else -> "unspecified"
            })
            .put("pending", purchase.purchaseState == PlayPurchase.PurchaseState.PENDING)
            .put("acknowledged", purchase.isAcknowledged)
            .put("autoRenewing", purchase.isAutoRenewing)
            .put("quantity", purchase.quantity)
            .put("accountId", purchase.accountIdentifiers?.obfuscatedAccountId ?: "")
            .put("profileId", purchase.accountIdentifiers?.obfuscatedProfileId ?: "")
            .put("packageName", purchase.packageName)
            .put("originalJson", purchase.originalJson)
            .put("signature", purchase.signature)
    }

    /**
     * One BillingClient for the app, connected when first needed. All state is
     * touched on the main thread only.
     */
    internal object PlayBilling {
        private const val CONNECT_TIMEOUT_MS = 20_000L
        private const val MAX_UNDELIVERED = 20

        private val main = Handler(Looper.getMainLooper())
        private var client: BillingClient? = null
        private var connected = false
        private var connecting = false
        private var attempt = 0
        private val waiting = mutableListOf<(BillingClient?, Failure?) -> Unit>()

        /** Refreshed on every call, held weakly so a destroyed activity can be collected. */
        private var activityRef: WeakReference<FragmentActivity>? = null
        private var appContext: Context? = null

        /** Events that found no live activity; sent with the next call. */
        private val undelivered = mutableListOf<Pair<String, JSONObject>>()

        /** The purchase sheet that is open: its request id and product type. */
        private var flowId: String? = null
        private var flowType: String? = null

        private val purchasesListener = PurchasesUpdatedListener { result, purchases ->
            main.post {
                val list = purchases ?: emptyList()
                val ok = result.responseCode == BillingClient.BillingResponseCode.OK
                val status = when {
                    ok && list.isNotEmpty() && list.all { it.purchaseState == PlayPurchase.PurchaseState.PENDING } -> "pending"
                    else -> statusFor(result)
                }
                val id = flowId
                val type = flowType
                flowId = null
                flowType = null
                purchaseResult(id, type, status, list, if (ok) null else Failure.of(result))
            }
        }

        fun attach(activity: FragmentActivity) {
            main.post {
                activityRef = WeakReference(activity)
                appContext = activity.applicationContext
                if (undelivered.isNotEmpty()) {
                    val events = undelivered.toList()
                    undelivered.clear()
                    events.forEach { (event, payload) -> dispatch(event, payload) }
                }
            }
        }

        private fun liveActivity(): FragmentActivity? =
            activityRef?.get()?.takeIf { !it.isFinishing && !it.isDestroyed }

        /**
         * Runs [then] on the main thread with a ready client, or with null and the
         * reason. Waiting callers are always answered: on setup, on a disconnect
         * during setup, or after CONNECT_TIMEOUT_MS.
         */
        fun withClient(then: (BillingClient?, Failure?) -> Unit) {
            main.post {
                val existing = client
                // Once connected, the library reconnects by itself when a call needs it.
                if (existing != null && connected && existing.connectionState != BillingClient.ConnectionState.CLOSED) {
                    then(existing, null)
                    return@post
                }
                waiting.add(then)
                if (connecting) return@post

                val context = appContext ?: liveActivity()?.applicationContext
                if (context == null) {
                    failWaiting(Failure("no_activity", "The app isn’t in the foreground."))
                    return@post
                }

                connecting = true
                val current = ++attempt
                val fresh = BillingClient.newBuilder(context)
                    .setListener(purchasesListener)
                    .enablePendingPurchases(PendingPurchasesParams.newBuilder().enableOneTimeProducts().build())
                    .enableAutoServiceReconnection()
                    .build()
                client = fresh

                main.postDelayed({
                    if (connecting && attempt == current) {
                        Log.w(TAG, "Billing setup timed out")
                        reset(fresh)
                        failWaiting(Failure("service_timeout", "Google Play didn’t answer. Try again in a moment."))
                    }
                }, CONNECT_TIMEOUT_MS)

                fresh.startConnection(object : BillingClientStateListener {
                    override fun onBillingSetupFinished(result: BillingResult) {
                        main.post {
                            if (attempt != current || client !== fresh) return@post
                            connecting = false
                            val failure = Failure.of(result)
                            if (failure == null) {
                                connected = true
                                val calls = waiting.toList()
                                waiting.clear()
                                calls.forEach { it(fresh, null) }
                            } else {
                                Log.w(TAG, "Billing setup failed: ${failure.code}")
                                reset(fresh)
                                failWaiting(failure)
                            }
                        }
                    }

                    override fun onBillingServiceDisconnected() {
                        main.post {
                            if (attempt != current || client !== fresh) return@post
                            Log.d(TAG, "Billing service disconnected")
                            if (connecting) {
                                // Setup never finished: answer whoever is waiting and start over next time.
                                reset(fresh)
                                failWaiting(Failure("service_disconnected", "Couldn’t reach Google Play. Check the connection and try again."))
                            }
                            // Once connected, auto service reconnection restores the link on the next call.
                        }
                    }
                })
            }
        }

        private fun reset(stale: BillingClient) {
            connecting = false
            connected = false
            if (client === stale) client = null
            attempt++
            runCatching { stale.endConnection() }
        }

        private fun failWaiting(failure: Failure) {
            val calls = waiting.toList()
            waiting.clear()
            calls.forEach { it(null, failure) }
        }

        fun loadProducts(client: BillingClient, ids: List<String>, type: String, then: (List<ProductDetails>, List<String>, Failure?) -> Unit) {
            val params = QueryProductDetailsParams.newBuilder()
                .setProductList(ids.map {
                    QueryProductDetailsParams.Product.newBuilder().setProductId(it).setProductType(type).build()
                })
                .build()
            client.queryProductDetailsAsync(params) { result, details ->
                val list = details.productDetailsList
                val unfetched = details.unfetchedProductList.map { it.productId }
                main.post { then(list, unfetched, Failure.of(result)) }
            }
        }

        fun queryPurchases(client: BillingClient, types: List<String>, then: (JSONArray, Failure?) -> Unit) {
            val all = JSONArray()
            var failure: Failure? = null
            var remaining = types.size
            types.forEach { type ->
                client.queryPurchasesAsync(QueryPurchasesParams.newBuilder().setProductType(type).build()) { result, purchases ->
                    main.post {
                        purchases.forEach { all.put(Describe.purchase(it, type)) }
                        failure = failure ?: Failure.of(result)
                        if (--remaining == 0) then(all, failure)
                    }
                }
            }
        }

        fun launch(client: BillingClient, params: BillingFlowParams, id: String, type: String) {
            val activity = liveActivity()
            if (activity == null) {
                purchaseResult(id, type, "failed", emptyList(), Failure("no_activity", "The app isn’t in the foreground."))
                return
            }
            if (flowId != null) {
                // A sheet that never answered: close out its request before starting another.
                purchaseResult(flowId, flowType, "failed", emptyList(), Failure("superseded", "Another purchase was started."))
            }
            flowId = id
            flowType = type
            val result = client.launchBillingFlow(activity, params)
            if (result.responseCode != BillingClient.BillingResponseCode.OK) {
                flowId = null
                flowType = null
                purchaseResult(id, type, statusFor(result), emptyList(), Failure.of(result))
            }
        }

        fun purchaseResult(id: String?, type: String?, status: String, purchases: List<PlayPurchase>, failure: Failure?) {
            val list = JSONArray()
            purchases.forEach { list.put(Describe.purchase(it, type)) }
            val payload = JSONObject()
                .put("id", id ?: JSONObject.NULL)
                .put("status", status)
                .put("purchases", list)
            if (failure != null && status != "purchased" && status != "pending") {
                payload.put("message", failure.message).put("code", failure.code)
            }
            dispatch("PurchaseUpdated", payload)
        }

        fun statusFor(result: BillingResult): String = when (result.responseCode) {
            BillingClient.BillingResponseCode.OK -> "purchased"
            BillingClient.BillingResponseCode.USER_CANCELED -> "cancelled"
            BillingClient.BillingResponseCode.ITEM_ALREADY_OWNED -> "already_owned"
            else -> "failed"
        }

        fun dispatch(event: String, payload: JSONObject) {
            main.post {
                val activity = liveActivity()
                if (activity == null) {
                    if (undelivered.size >= MAX_UNDELIVERED) undelivered.removeAt(0)
                    undelivered.add(event to payload)
                    return@post
                }
                NativeActionCoordinator.dispatchEvent(activity, EVENTS + event, payload.toString())
            }
        }
    }
}
