package com.shopkmp.home.data

import com.shopkmp.home.domain.ShopCartItem
import com.shopkmp.home.domain.ShopCheckoutResult
import com.shopkmp.home.domain.ShopOrder
import com.shopkmp.home.domain.ShopOrderStatus
import com.shopkmp.home.domain.ShopPaymentMethod
import com.shopkmp.home.domain.ShopProduct
import com.shopkmp.home.domain.ShopRepository
import com.shopkmp.network.shop.AddCartItemRequest
import com.shopkmp.network.shop.AddressDto
import com.shopkmp.network.shop.CartDto
import com.shopkmp.network.shop.CategoryDto
import com.shopkmp.network.shop.CreateOrderRequest
import com.shopkmp.network.shop.OrderDto
import com.shopkmp.network.shop.ProductDto
import com.shopkmp.network.shop.ProductSummaryDto
import com.shopkmp.network.shop.ShopService
import com.shopkmp.home.data.cache.CatalogCache
import com.shopkmp.network.shop.PageResponse
import com.shopkmp.network.shop.UpdateCartItemRequest
import com.shopkmp.network.shop.UpsertAddressRequest
import com.shopkmp.remoteresult.RemoteResult
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

internal class BackendShopRepository(
    private val shopApi: ShopService,
    private val networkBaseUrl: String,
    private val catalogCache: CatalogCache? = null,
) : ShopRepository {
    private var categoriesById: Map<String, String> = emptyMap()
    private var categoryIdsByName: Map<String, String> = emptyMap()
    private val cartMutationMutex = Mutex()
    private val checkoutMutex = Mutex()
    private val cacheRefreshScope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val cacheRefreshMutex = Mutex()
    private val refreshingCacheKeys = mutableSetOf<String>()
    private val pendingCartKeys = mutableMapOf<String, String>()
    private var pendingOrderKey: Pair<String, String>? = null

    override suspend fun getCategories(): List<String>? = loadCategories()?.map { it.name }

    override suspend fun getProducts(query: String, category: String?, page: Int, size: Int, sort: String?): Pair<List<ShopProduct>, Int>? {
        val categoryId = category?.let { categoryIds()[it] }
        val cacheKey = "products:$query:${categoryId.orEmpty()}:$page:$size:${sort.orEmpty()}"
        val data = cachedCatalog<PageResponse<ProductSummaryDto>>(cacheKey) {
            if (query.isBlank()) {
                shopApi.getProducts(categoryId = categoryId, page = page, size = size, sort = sort)
            } else {
                shopApi.searchProducts(query = query, categoryId = categoryId, page = page, size = size)
            }
        } ?: return null
        return data.items.map { it.toDomain() } to data.totalPages
    }

    override suspend fun getPopularProducts(limit: Int): List<ShopProduct>? {
        val key = "popular:$limit"
        return cachedCatalog<List<ProductSummaryDto>>(key) { shopApi.getPopularProducts(limit) }
            ?.map { it.toDomain() }
    }

    override suspend fun getProduct(id: String): ShopProduct? {
        val key = "product:$id"
        return cachedCatalog<ProductDto>(key) { shopApi.getProduct(id) }?.toDomain()
    }

    override suspend fun getCartItems(): Pair<List<ShopCartItem>, String?>? =
        when (val response = shopApi.getCart()) {
            is RemoteResult.Success -> {
                val items = response.data.items.map { item ->
                    val product = ShopProduct(
                        id = item.productId,
                        name = item.productName,
                        category = "",
                        description = "",
                        priceRub = item.priceSnapshot,
                        stock = item.stock,
                        rating = 0.0,
                        reviews = 0,
                        imageUrl = item.imageUrl?.let { resolveUrl(it) },
                    )
                    ShopCartItem(
                        product = product,
                        quantity = item.quantity,
                        serverTotalRub = null,
                        isAvailable = item.isAvailable,
                    )
                }
                Pair(items, response.data.totalAmount)
            }

            is RemoteResult.Error -> null
        }

    // Return the server total; the client must not recalculate monetary values.
    suspend fun getCartTotal(): String? =
        when (val response = shopApi.getCart()) {
            is RemoteResult.Success -> response.data.totalAmount
            is RemoteResult.Error -> null
        }

    override suspend fun getOrders(page: Int, size: Int): Pair<List<ShopOrder>, Int>? {
        val addressesById = loadAddresses()?.associate { it.id to it.toText() } ?: emptyMap()
        return when (val response = shopApi.getOrders(page = page, size = size)) {
            is RemoteResult.Success -> response.data.items.map { it.toDomain(addressesById[it.deliveryAddressId]) } to response.data.totalPages
            is RemoteResult.Error -> null
        }
    }

    override suspend fun addToCart(productId: String, quantity: Int): Boolean = cartMutationMutex.withLock {
        val fingerprint = "$productId:$quantity"
        val key = pendingCartKeys.getOrPut(fingerprint, shopApi::newIdempotencyKey)
        when (val response = shopApi.addToCart(AddCartItemRequest(productId = productId, quantity = quantity), key)) {
            is RemoteResult.Success -> {
                pendingCartKeys.remove(fingerprint)
                true
            }
            is RemoteResult.Error -> {
                if (response.isDefinitive()) pendingCartKeys.remove(fingerprint)
                false
            }
        }
    }

    override suspend fun setCartQuantity(productId: String, quantity: Int): Boolean =
        when {
            quantity <= 0 ->
                when (shopApi.deleteCartItem(productId)) {
                    is RemoteResult.Success -> true
                    is RemoteResult.Error -> false
                }

            else ->
                when (shopApi.updateCartItem(productId, UpdateCartItemRequest(quantity = quantity))) {
                    is RemoteResult.Success -> true
                    is RemoteResult.Error -> false
                }
        }

    override suspend fun checkout(
        city: String,
        street: String,
        house: String,
        apartment: String,
        paymentMethod: ShopPaymentMethod,
    ): ShopCheckoutResult = checkoutMutex.withLock {
        if (city.isBlank() && street.isBlank() && house.isBlank() && apartment.isBlank()) {
            return@withLock ShopCheckoutResult.Error(null, null)
        }

        val addressRequest = UpsertAddressRequest(
            city = city.trim(),
            street = street.trim(),
            house = house.trim(),
            apartment = apartment.trim(),
        )
        val addressId = findOrCreateAddress(addressRequest)
            ?: return@withLock ShopCheckoutResult.Error(null, null)

        val paymentMethodValue = paymentMethod.toBackend()
        val orderFingerprint = "$addressId:$paymentMethodValue"
        val orderKey = pendingOrderKey
            ?.takeIf { it.first == orderFingerprint }
            ?.second
            ?: shopApi.newIdempotencyKey().also { pendingOrderKey = orderFingerprint to it }
        val orderResponse = shopApi.createOrder(
            request = CreateOrderRequest(
                deliveryAddressId = addressId,
                paymentMethod = paymentMethodValue,
            ),
            idempotencyKey = orderKey,
        )
        val order = when (orderResponse) {
            is RemoteResult.Success -> {
                pendingOrderKey = null
                orderResponse.data
            }
            is RemoteResult.Error.ServerError -> {
                if (orderResponse.isDefinitive()) pendingOrderKey = null
                return@withLock ShopCheckoutResult.Error(orderResponse.errorCode, orderResponse.details)
            }
            is RemoteResult.Error -> {
                if (orderResponse.isDefinitive()) pendingOrderKey = null
                return@withLock ShopCheckoutResult.Error(null, null)
            }
        }

        val deliveryAddress = listOf(city, street, house, apartment)
            .map { it.trim() }
            .filter { it.isNotBlank() }
            .joinToString(", ")

        val paymentConfirmation = order.paymentId?.let { paymentId ->
            shopApi.confirmPayment(paymentId)
        }
        val paymentUrl = (paymentConfirmation as? RemoteResult.Success)?.data?.paymentUrl?.let(::resolveUrl)
        // Confirmation can commit before its response is lost. Reconcile via a
        // trusted read, never issue a second authorization or repeat checkout.
        val reconciled = if (paymentConfirmation is RemoteResult.Error) {
            (shopApi.getOrder(order.id) as? RemoteResult.Success)?.data?.takeIf {
                it.id == order.id && it.userId == order.userId
            }
        } else null
        val paymentInitiationFailed = paymentConfirmation is RemoteResult.Error &&
            reconciled?.status !in setOf("PAID", "ASSEMBLING", "DELIVERING", "COMPLETED")

        return@withLock ShopCheckoutResult.Success(
            (reconciled ?: order).toDomain(deliveryAddress = deliveryAddress, paymentMethodOverride = paymentMethod),
            paymentUrl = paymentUrl,
            paymentInitiationFailed = paymentInitiationFailed,
        )
    }

    // Reuse an address with the same fields before creating a new one.
    private suspend fun findOrCreateAddress(request: UpsertAddressRequest): String? {
        val existing = loadAddresses() ?: return null
        val match = existing.firstOrNull { addr ->
            addr.city.trim().equals(request.city.trim(), ignoreCase = true) &&
                addr.street.trim().equals(request.street.trim(), ignoreCase = true) &&
                addr.house.trim().equals(request.house.trim(), ignoreCase = true) &&
                addr.apartment.trim().equals(request.apartment.trim(), ignoreCase = true)
        }
        if (match != null) return match.id
        return when (val addressResponse = shopApi.createAddress(request)) {
            is RemoteResult.Success -> addressResponse.data.id
            is RemoteResult.Error -> null
        }
    }

    private suspend fun ProductSummaryDto.toDomain(): ShopProduct =
        ShopProduct(
            id = id,
            name = name,
            category = categoryNames()[categoryId] ?: categoryId,
            description = description,
            priceRub = discountPrice ?: price,
            stock = stock,
            rating = rating ?: 0.0,
            reviews = reviewsCount,
            imageUrl = imageUrls.firstOrNull()?.let { resolveUrl(it) },
        )

    private suspend fun ProductDto.toDomain(): ShopProduct =
        ShopProduct(
            id = id,
            name = name,
            category = categoryNames()[categoryId] ?: categoryId,
            description = description,
            priceRub = discountPrice ?: price,
            stock = stock,
            rating = rating ?: 0.0,
            reviews = reviewsCount,
            imageUrl = imageUrls.firstOrNull()?.let { resolveUrl(it) },
        )

    private fun resolveUrl(url: String): String =
        if (url.startsWith("http")) url else "${networkBaseUrl.trimEnd('/')}$url"

    // Map every order status documented by the API contract.
    private fun OrderDto.toDomain(
        deliveryAddress: String? = null,
        paymentMethodOverride: ShopPaymentMethod? = null,
    ): ShopOrder {
        val effectiveAddress = this.deliveryAddress
            .takeIf { it.isNotBlank() }
            ?: deliveryAddress
            ?: deliveryAddressId

        val effectivePayment = this.paymentMethod.let { raw ->
            when (raw.uppercase()) {
                "CARD" -> ShopPaymentMethod.Card
                "SBP" -> ShopPaymentMethod.Sbp
                "ON_DELIVERY" -> ShopPaymentMethod.OnDelivery
                else -> null
            }
        } ?: paymentMethodOverride ?: ShopPaymentMethod.Card

        return ShopOrder(
            number = number,
            status = when (status) {
                "CREATED" -> ShopOrderStatus.Created
                "PAID" -> ShopOrderStatus.Paid
                "ASSEMBLING" -> ShopOrderStatus.Assembling
                "DELIVERING" -> ShopOrderStatus.Delivering
                "COMPLETED" -> ShopOrderStatus.Completed
                "CANCELLED" -> ShopOrderStatus.Cancelled
                else -> ShopOrderStatus.Created
            },
            totalRub = totalAmount,
            deliveryAddress = effectiveAddress,
            paymentMethod = effectivePayment,
        )
    }

    private suspend fun loadCategories(): List<CategoryDto>? =
        cachedCatalog<List<CategoryDto>>("categories") { shopApi.getCategories() }?.also {
            categoriesById = it.associate { category -> category.id to category.name }
            categoryIdsByName = it.associate { category -> category.name to category.id }
        }

    private suspend fun categoryNames(): Map<String, String> {
        if (categoriesById.isEmpty()) loadCategories()
        return categoriesById
    }

    private suspend fun categoryIds(): Map<String, String> {
        if (categoryIdsByName.isEmpty()) loadCategories()
        return categoryIdsByName
    }

    private suspend fun loadAddresses(): List<AddressDto>? =
        when (val response = shopApi.getAddresses()) {
            is RemoteResult.Success -> response.data
            is RemoteResult.Error -> null
        }

    private inline fun <reified T> cacheFresh(key: String): T? = catalogCache?.fresh<T>(key)
    private inline fun <reified T> cacheStale(key: String): T? = catalogCache?.stale<T>(key)
    private inline fun <reified T> cachePut(key: String, value: T) { catalogCache?.put(key, value) }

    /** Returns stale data immediately and refreshes it in the background. */
    private suspend inline fun <reified T> cachedCatalog(
        key: String,
        crossinline fetch: suspend () -> RemoteResult<T>,
    ): T? {
        cacheFresh<T>(key)?.let { return it }
        cacheStale<T>(key)?.let { stale ->
            val shouldRefresh = cacheRefreshMutex.withLock { refreshingCacheKeys.add(key) }
            if (shouldRefresh) cacheRefreshScope.launch {
                try {
                    val response = fetch()
                    if (response is RemoteResult.Success) cachePut(key, response.data)
                } finally {
                    cacheRefreshMutex.withLock { refreshingCacheKeys.remove(key) }
                }
            }
            return stale
        }
        return when (val response = fetch()) {
            is RemoteResult.Success -> response.data.also { cachePut(key, it) }
            is RemoteResult.Error -> null
        }
    }
    private fun AddressDto.toText(): String =
        listOf(city, street, house, apartment)
            .map { it.trim() }
            .filter { it.isNotBlank() }
            .joinToString(", ")
}

private fun ShopPaymentMethod.toBackend(): String =
    when (this) {
        ShopPaymentMethod.Card -> "CARD"
        ShopPaymentMethod.Sbp -> "SBP"
        ShopPaymentMethod.OnDelivery -> "ON_DELIVERY"
    }

private fun RemoteResult.Error.isDefinitive(): Boolean =
    this is RemoteResult.Error.ServerSignError || this is RemoteResult.Error.ServerError && code in 400..499

// Preserve the exact server string without a Double conversion.
internal fun String.toRubString(): String =
    if (contains('.')) this else if (isEmpty()) "0.00" else "$this.00"
