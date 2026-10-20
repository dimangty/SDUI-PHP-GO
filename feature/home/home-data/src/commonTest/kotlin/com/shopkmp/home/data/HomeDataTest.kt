package com.shopkmp.home.data

import com.shopkmp.remoteresult.RemoteResult
import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertNull
import kotlin.test.assertTrue
import com.shopkmp.network.shop.*
import com.shopkmp.home.domain.ShopCheckoutResult
import com.shopkmp.home.domain.ShopOrderStatus
import com.shopkmp.home.domain.ShopPaymentMethod

class HomeDataTest {

    @Test
    fun rubStringParsingHandlesDecimal() {
        val result = "1234.56".toRubString()
        assertEquals("1234.56", result)
    }

    @Test
    fun rubStringParsingHandlesInteger() {
        val result = "100".toRubString()
        assertEquals("100.00", result)
    }

    @Test
    fun rubStringParsingHandlesEmpty() {
        val result = "".toRubString()
        assertEquals("0.00", result)
    }

    @Test
    fun rubStringParsingNormalizesInteger() {
        val result = "42".toRubString()
        assertEquals("42.00", result)
    }

    @Test
    fun indeterminatePaymentIsSingleAttemptAndKeepsCreatedOrderAsPartialSuccess() = runTest {
        val api = FakeShopService().apply {
            orderResult = RemoteResult.Success(sampleOrder().copy(paymentId = "pay1"))
            paymentResult = RemoteResult.Error.TimeoutError
        }
        val result = BackendShopRepository(api, "https://shop.example")
            .checkout("Омск", "Мира", "1", "", ShopPaymentMethod.Card)
        val success = assertIs<ShopCheckoutResult.Success>(result)
        assertTrue(success.paymentInitiationFailed)
        assertEquals(101, success.order.number)
        assertNull(success.paymentUrl)
        assertEquals(listOf("pay1"), api.confirmedPayments)
        assertEquals(1, api.createOrderCalls)
    }

    @Test
    fun explicitOrderRetryRetainsOriginalIdempotencyKey() = runTest {
        val api = FakeShopService()
        val repository = BackendShopRepository(api, "https://shop.example")
        repository.checkout("Омск", "Мира", "1", "", ShopPaymentMethod.Card)
        api.orderResult = RemoteResult.Success(sampleOrder())
        assertIs<ShopCheckoutResult.Success>(repository.checkout("Омск", "Мира", "1", "", ShopPaymentMethod.Card))
        assertEquals(2, api.orderKeys.size)
        assertEquals(api.orderKeys.first(), api.orderKeys.last())
    }

    @Test
    fun lostPaymentResponseReconcilesPaidOrderWithoutRepeatingConfirmation() = runTest {
        val api = FakeShopService().apply {
            orderResult = RemoteResult.Success(sampleOrder().copy(paymentId = "pay1"))
            reconciledOrder = RemoteResult.Success(sampleOrder(status = "PAID").copy(paymentId = "pay1"))
            paymentResult = RemoteResult.Error.TimeoutError
        }
        val result = assertIs<ShopCheckoutResult.Success>(BackendShopRepository(api, "https://shop.example")
            .checkout("Омск", "Мира", "1", "", ShopPaymentMethod.Card))
        assertTrue(!result.paymentInitiationFailed)
        assertEquals(ShopOrderStatus.Paid, result.order.status)
        assertEquals(listOf("o1"), api.orderReads)
        assertEquals(listOf("pay1"), api.confirmedPayments)
    }

    @Test
    fun cartRetryReusesTheSameIdempotencyKey() = runTest {
        val api = FakeShopService().apply {
            addCartResults.add(RemoteResult.Error.TimeoutError)
            addCartResults.add(RemoteResult.Success(sampleCart()))
        }
        val repository = BackendShopRepository(api, "https://shop.example")

        assertTrue(!repository.addToCart("p1", 1))
        assertTrue(repository.addToCart("p1", 1))

        assertEquals(2, api.cartKeys.size)
        assertEquals(api.cartKeys.first(), api.cartKeys.last())
    }

    @Test
    fun checkoutCreatesOrderAndConfirmsPayment() = runTest {
        val api = FakeShopService().apply {
            addressesResult = RemoteResult.Success(
                listOf(AddressDto("a1", "u1", "Новосибирск", "Ленина", "1", "10", null)),
            )
            orderResult = RemoteResult.Success(
                OrderDto(
                    id = "o1", number = 101, userId = "u1", status = "CREATED", items = emptyList(),
                    totalAmount = "100.00", deliveryAddressId = "a1", paymentId = "pay1", createdAt = "now",
                    comment = null, paymentMethod = "SBP", deliveryAddress = "Новосибирск, Ленина, 1",
                ),
            )
            paymentResult = RemoteResult.Success(PaymentConfirmResponse("pay1", "AUTHORIZED", "https://pay.example"))
        }
        val repository = BackendShopRepository(api, "https://shop.example")

        val result = repository.checkout("Новосибирск", "Ленина", "1", "10", ShopPaymentMethod.Sbp)

        val success = assertIs<ShopCheckoutResult.Success>(result)
        assertEquals(101, success.order.number)
        assertEquals("https://pay.example", success.paymentUrl)
        assertEquals(1, api.createOrderCalls)
        assertEquals(listOf("pay1"), api.confirmedPayments)
    }

    @Test
    fun catalogAndCartResponsesMapEveryWireField() = runTest {
        val product = sampleProductSummary()
        val api = FakeShopService().apply {
            categoriesResult = RemoteResult.Success(
                listOf(CategoryDto("c1", "Электроника", null, "/icons/c1.svg", 1)),
            )
            productsResult = RemoteResult.Success(PageResponse(listOf(product), 0, 20, 2, 21))
            searchResult = RemoteResult.Success(PageResponse(listOf(product), 0, 20, 1, 1))
            popularResult = RemoteResult.Success(listOf(product))
            productResult = RemoteResult.Success(sampleProduct())
            cartResult = RemoteResult.Success(
                CartDto(
                    "cart", "u1",
                    listOf(CartItemDto("p1", "Телефон", 2, "90.00", "/images/p1.png", true, 3)),
                    "180.00", "now",
                ),
            )
        }
        val repository = BackendShopRepository(api, "https://shop.example/")

        assertEquals(listOf("Электроника"), repository.getCategories())
        val products = repository.getProducts("", "Электроника", 0, 20, "price")!!
        assertEquals(2, products.second)
        assertEquals("90.00", products.first.single().priceRub)
        assertEquals("https://shop.example/images/p1.png", products.first.single().imageUrl)
        assertEquals(1, repository.getProducts("телефон", null, 0, 20, null)!!.first.size)
        assertEquals("Электроника", repository.getPopularProducts(3)!!.single().category)
        assertEquals("https://cdn.example/p1.png", repository.getProduct("p1")!!.imageUrl)
        assertEquals("180.00", repository.getCartTotal())
        val cartItem = repository.getCartItems()!!.first.single()
        assertEquals(2, cartItem.quantity)
        assertTrue(cartItem.isAvailable)
    }

    @Test
    fun repositoryReturnsNullForRemoteReadFailures() = runTest {
        val api = FakeShopService().apply {
            categoriesResult = RemoteResult.Error.NetworkError
            productsResult = RemoteResult.Error.TimeoutError
            searchResult = RemoteResult.Error.NetworkError
            popularResult = RemoteResult.Error.TimeoutError
            productResult = RemoteResult.Error.ServerError(404, "missing")
            cartResult = RemoteResult.Error.NetworkError
            ordersResult = RemoteResult.Error.NetworkError
            addressesResult = RemoteResult.Error.NetworkError
        }
        val repository = BackendShopRepository(api, "https://shop.example")

        assertNull(repository.getCategories())
        assertNull(repository.getProducts("", null, 0, 20, null))
        assertNull(repository.getProducts("query", null, 0, 20, null))
        assertNull(repository.getPopularProducts(2))
        assertNull(repository.getProduct("missing"))
        assertNull(repository.getCartItems())
        assertNull(repository.getCartTotal())
        assertNull(repository.getOrders(0, 20))
    }

    @Test
    fun ordersMapAllStatusesPaymentsAndAddressFallbacks() = runTest {
        val statuses = listOf("CREATED", "PAID", "ASSEMBLING", "DELIVERING", "COMPLETED", "CANCELLED", "UNKNOWN")
        val api = FakeShopService().apply {
            addressesResult = RemoteResult.Success(
                listOf(AddressDto("a1", "u1", "Томск", "Мира", "1", "", null)),
            )
            ordersResult = RemoteResult.Success(
                PageResponse(
                    statuses.mapIndexed { index, status ->
                        sampleOrder(
                            id = "o$index",
                            number = 100 + index,
                            status = status,
                            paymentMethod = listOf("CARD", "SBP", "ON_DELIVERY", "UNKNOWN")[index % 4],
                            deliveryAddress = if (index == 0) "Готовый адрес" else "",
                            deliveryAddressId = if (index < 6) "a1" else "missing",
                        )
                    },
                    0, 20, 1, statuses.size,
                ),
            )
        }
        val orders = BackendShopRepository(api, "https://shop.example").getOrders(0, 20)!!.first

        assertEquals(ShopOrderStatus.Created, orders[0].status)
        assertEquals(ShopOrderStatus.Paid, orders[1].status)
        assertEquals(ShopOrderStatus.Assembling, orders[2].status)
        assertEquals(ShopOrderStatus.Delivering, orders[3].status)
        assertEquals(ShopOrderStatus.Completed, orders[4].status)
        assertEquals(ShopOrderStatus.Cancelled, orders[5].status)
        assertEquals(ShopOrderStatus.Created, orders[6].status)
        assertEquals("Готовый адрес", orders[0].deliveryAddress)
        assertEquals("missing", orders[6].deliveryAddress)
    }

    @Test
    fun cartQuantityUsesDeleteAndPatchBranches() = runTest {
        val api = FakeShopService()
        val repository = BackendShopRepository(api, "https://shop.example")

        assertTrue(repository.setCartQuantity("p1", 0))
        assertTrue(repository.setCartQuantity("p1", 2))
        api.deleteResult = RemoteResult.Error.NetworkError
        api.updateResult = RemoteResult.Error.TimeoutError
        assertTrue(!repository.setCartQuantity("p1", -1))
        assertTrue(!repository.setCartQuantity("p1", 3))
    }

    @Test
    fun checkoutValidatesAddressAndClassifiesOrderFailures() = runTest {
        val api = FakeShopService()
        val repository = BackendShopRepository(api, "https://shop.example")

        assertIs<ShopCheckoutResult.Error>(
            repository.checkout("", "", "", "", ShopPaymentMethod.Card),
        )

        api.addressesResult = RemoteResult.Success(emptyList())
        api.createdAddressResult = RemoteResult.Error.NetworkError
        assertIs<ShopCheckoutResult.Error>(
            repository.checkout("Омск", "Мира", "1", "", ShopPaymentMethod.Card),
        )

        api.createdAddressResult = RemoteResult.Success(
            AddressDto("new", "u1", "Омск", "Мира", "1", "", null),
        )
        api.orderResult = RemoteResult.Error.ServerError(
            409,
            "stock changed",
            "OUT_OF_STOCK",
            mapOf("productId" to "p1"),
        )
        val definitive = assertIs<ShopCheckoutResult.Error>(
            repository.checkout("Омск", "Мира", "1", "", ShopPaymentMethod.OnDelivery),
        )
        assertEquals("OUT_OF_STOCK", definitive.errorCode)

        api.orderResult = RemoteResult.Error.TimeoutError
        assertIs<ShopCheckoutResult.Error>(
            repository.checkout("Омск", "Мира", "1", "", ShopPaymentMethod.Sbp),
        )
    }
}

private class FakeShopService : ShopService {
    var categoriesResult: RemoteResult<List<CategoryDto>> = RemoteResult.Success(emptyList())
    var productsResult: RemoteResult<PageResponse<ProductSummaryDto>> =
        RemoteResult.Success(PageResponse(emptyList(), 0, 20, 0, 0))
    var searchResult: RemoteResult<PageResponse<ProductSummaryDto>> = productsResult
    var popularResult: RemoteResult<List<ProductSummaryDto>> = RemoteResult.Success(emptyList())
    var productResult: RemoteResult<ProductDto> = RemoteResult.Error.ServerError(404, "missing")
    var cartResult: RemoteResult<CartDto> = RemoteResult.Success(sampleCart())
    var ordersResult: RemoteResult<PageResponse<OrderDto>> =
        RemoteResult.Success(PageResponse(emptyList(), 0, 20, 0, 0))
    var addressesResult: RemoteResult<List<AddressDto>> = RemoteResult.Success(emptyList())
    var createdAddressResult: RemoteResult<AddressDto>? = null
    var orderResult: RemoteResult<OrderDto> = RemoteResult.Error.NetworkError
    var reconciledOrder: RemoteResult<OrderDto> = RemoteResult.Error.NetworkError
    val orderReads = mutableListOf<String>()
    var paymentResult: RemoteResult<PaymentConfirmResponse> = RemoteResult.Error.NetworkError
    var deleteResult: RemoteResult<Unit> = RemoteResult.Success(Unit)
    var updateResult: RemoteResult<CartDto> = RemoteResult.Success(sampleCart())
    val addCartResults = ArrayDeque<RemoteResult<CartDto>>()
    val cartKeys = mutableListOf<String>()
    val confirmedPayments = mutableListOf<String>()
    val orderKeys = mutableListOf<String>()
    var createOrderCalls = 0
    private var keySequence = 0

    override suspend fun getCategories() = categoriesResult
    override suspend fun getProducts(categoryId: String?, page: Int, size: Int, sort: String?) =
        productsResult
    override suspend fun searchProducts(query: String, categoryId: String?, priceMin: String?, priceMax: String?, page: Int, size: Int) =
        searchResult
    override suspend fun getPopularProducts(limit: Int) = popularResult
    override suspend fun getProduct(id: String): RemoteResult<ProductDto> = productResult
    override suspend fun getCart() = cartResult
    override suspend fun getOrders(page: Int, size: Int, status: String?) = ordersResult
    override suspend fun getOrder(id: String): RemoteResult<OrderDto> { orderReads += id; return reconciledOrder }
    override suspend fun getAddresses() = addressesResult
    override fun newIdempotencyKey(): String = "key-${++keySequence}"
    override suspend fun addToCart(request: AddCartItemRequest, idempotencyKey: String): RemoteResult<CartDto> {
        cartKeys += idempotencyKey
        return addCartResults.removeFirst()
    }
    override suspend fun deleteCartItem(productId: String): RemoteResult<Unit> = deleteResult
    override suspend fun updateCartItem(productId: String, request: UpdateCartItemRequest) = updateResult
    override suspend fun createAddress(request: UpsertAddressRequest) =
        createdAddressResult ?: RemoteResult.Success(
            AddressDto("new", "u1", request.city, request.street, request.house, request.apartment, request.comment),
        )
    override suspend fun createOrder(request: CreateOrderRequest, idempotencyKey: String): RemoteResult<OrderDto> {
        orderKeys += idempotencyKey
        createOrderCalls++
        return orderResult
    }
    override suspend fun confirmPayment(paymentId: String): RemoteResult<PaymentConfirmResponse> {
        confirmedPayments += paymentId
        return paymentResult
    }
}

private fun sampleCart() = CartDto("c1", "u1", emptyList(), "0.00", "now")

private fun sampleProductSummary() = ProductSummaryDto(
    "p1", "c1", "Телефон", "Описание", "100.00", "90.00", 3, 4.5,
    listOf("/images/p1.png"), true, 7,
)

private fun sampleProduct() = ProductDto(
    "p1", "c1", "Телефон", "Описание", "100.00", null, 3, null,
    listOf("https://cdn.example/p1.png"), true, 0,
)

private fun sampleOrder(
    id: String = "o1",
    number: Int = 101,
    status: String = "CREATED",
    paymentMethod: String = "CARD",
    deliveryAddress: String = "",
    deliveryAddressId: String = "a1",
) = OrderDto(
    id, number, "u1", status, emptyList(), "100.00", deliveryAddressId, null, "now",
    null, paymentMethod, deliveryAddress,
)
