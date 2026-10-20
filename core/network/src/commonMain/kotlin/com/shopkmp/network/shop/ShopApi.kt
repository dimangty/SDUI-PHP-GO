package com.shopkmp.network.shop

import com.shopkmp.network.api.ApiRoute
import com.shopkmp.network.api.request.DeleteRequest
import com.shopkmp.network.api.request.GetRequest
import com.shopkmp.network.api.request.PatchRequest
import com.shopkmp.network.api.request.PostRequest
import com.shopkmp.network.randomUuid
import com.shopkmp.remoteresult.RemoteResult

/** Testable network contract consumed by the shop repository. */
interface ShopService {
    suspend fun getCategories(): RemoteResult<List<CategoryDto>>
    suspend fun getProducts(categoryId: String? = null, page: Int = 0, size: Int = 20, sort: String? = null): RemoteResult<PageResponse<ProductSummaryDto>>
    suspend fun searchProducts(query: String, categoryId: String? = null, priceMin: String? = null, priceMax: String? = null, page: Int = 0, size: Int = 20): RemoteResult<PageResponse<ProductSummaryDto>>
    suspend fun getPopularProducts(limit: Int): RemoteResult<List<ProductSummaryDto>>
    suspend fun getProduct(id: String): RemoteResult<ProductDto>
    suspend fun getCart(): RemoteResult<CartDto>
    suspend fun getOrders(page: Int = 0, size: Int = 20, status: String? = null): RemoteResult<PageResponse<OrderDto>>
    suspend fun getOrder(id: String): RemoteResult<OrderDto>
    suspend fun getAddresses(): RemoteResult<List<AddressDto>>
    fun newIdempotencyKey(): String
    suspend fun addToCart(request: AddCartItemRequest, idempotencyKey: String = newIdempotencyKey()): RemoteResult<CartDto>
    suspend fun deleteCartItem(productId: String): RemoteResult<Unit>
    suspend fun updateCartItem(productId: String, request: UpdateCartItemRequest): RemoteResult<CartDto>
    suspend fun createAddress(request: UpsertAddressRequest): RemoteResult<AddressDto>
    suspend fun createOrder(request: CreateOrderRequest, idempotencyKey: String = newIdempotencyKey()): RemoteResult<OrderDto>
    suspend fun confirmPayment(paymentId: String): RemoteResult<PaymentConfirmResponse>
}

class ShopApi internal constructor(
    private val getRequest: GetRequest,
    private val postRequest: PostRequest,
    private val patchRequest: PatchRequest,
    private val deleteRequest: DeleteRequest,
) : ShopService {
    override suspend fun getCategories(): RemoteResult<List<CategoryDto>> =
        getRequest.getConditional(path = ApiRoute.CATEGORIES)

    override suspend fun getProducts(
        categoryId: String?,
        page: Int,
        size: Int,
        sort: String?,
    ): RemoteResult<PageResponse<ProductSummaryDto>> =
        getRequest.getConditional(
            path = ApiRoute.PRODUCTS,
            queryParams = buildMap {
                categoryId?.let { put("categoryId", it) }
                put("page", page.toString())
                put("size", size.toString())
                sort?.let { put("sort", it) }
            },
        )

    override suspend fun searchProducts(
        query: String,
        categoryId: String?,
        priceMin: String?,
        priceMax: String?,
        page: Int,
        size: Int,
    ): RemoteResult<PageResponse<ProductSummaryDto>> =
        getRequest.getConditional(
            path = "${ApiRoute.PRODUCTS}/search",
            queryParams = buildMap {
                put("q", query)
                categoryId?.let { put("categoryId", it) }
                priceMin?.let { put("priceMin", it) }
                priceMax?.let { put("priceMax", it) }
                put("page", page.toString())
                put("size", size.toString())
            },
        )

    override suspend fun getPopularProducts(limit: Int): RemoteResult<List<ProductSummaryDto>> =
        getRequest.getConditional(
            path = "${ApiRoute.PRODUCTS}/popular",
            queryParams = mapOf("limit" to limit.toString()),
        )

    override suspend fun getProduct(id: String): RemoteResult<ProductDto> =
        getRequest.getConditional(path = "${ApiRoute.PRODUCTS}/$id")

    override suspend fun getCart(): RemoteResult<CartDto> =
        getRequest.get(path = ApiRoute.CART, requiresAuth = true)

    override suspend fun getOrders(
        page: Int,
        size: Int,
        status: String?,
    ): RemoteResult<PageResponse<OrderDto>> =
        getRequest.get(
            path = ApiRoute.ORDERS,
            requiresAuth = true,
            queryParams = buildMap {
                put("page", page.toString())
                put("size", size.toString())
                status?.let { put("status", it) }
            },
        )

    override suspend fun getAddresses(): RemoteResult<List<AddressDto>> =
        getRequest.get(path = ApiRoute.ADDRESSES, requiresAuth = true)

    override suspend fun getOrder(id: String): RemoteResult<OrderDto> =
        getRequest.get(path = "${ApiRoute.ORDERS}/$id", requiresAuth = true)

    override fun newIdempotencyKey(): String = randomUuid()

    override suspend fun addToCart(request: AddCartItemRequest, idempotencyKey: String): RemoteResult<CartDto> =
        postRequest.post(
            path = "${ApiRoute.CART}/items",
            body = request,
            headers = mapOf("Idempotency-Key" to idempotencyKey),
            requiresAuth = true,
        )

    override suspend fun deleteCartItem(productId: String): RemoteResult<Unit> =
        deleteRequest.delete(path = "${ApiRoute.CART}/items/$productId", requiresAuth = true)

    override suspend fun updateCartItem(productId: String, request: UpdateCartItemRequest): RemoteResult<CartDto> =
        patchRequest.patch(
            path = "${ApiRoute.CART}/items/$productId",
            body = request,
            requiresAuth = true,
        )

    override suspend fun createAddress(request: UpsertAddressRequest): RemoteResult<AddressDto> =
        postRequest.post(path = ApiRoute.ADDRESSES, body = request, requiresAuth = true)

    override suspend fun createOrder(request: CreateOrderRequest, idempotencyKey: String): RemoteResult<OrderDto> =
        postRequest.post(
            path = ApiRoute.ORDERS,
            body = request,
            headers = mapOf("Idempotency-Key" to idempotencyKey),
            requiresAuth = true,
        )

    override suspend fun confirmPayment(paymentId: String): RemoteResult<PaymentConfirmResponse> =
        postRequest.post(path = "${ApiRoute.PAYMENTS}/$paymentId/confirm", requiresAuth = true)
}
