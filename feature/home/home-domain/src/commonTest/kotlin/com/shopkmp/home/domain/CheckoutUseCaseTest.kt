package com.shopkmp.home.domain

import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs

class CheckoutUseCaseTest {
    @Test
    fun forwardsCheckoutAndPreservesOutOfStockDetails() = runTest {
        val repository = CheckoutRepositoryFake(
            ShopCheckoutResult.Error("OUT_OF_STOCK", mapOf("items" to "product-1")),
        )
        val result = CheckoutUseCase(repository).execute(
            city = "Новосибирск",
            street = "Ленина",
            house = "1",
            apartment = "10",
            paymentMethod = ShopPaymentMethod.Sbp,
        )

        val error = assertIs<ShopCheckoutResult.Error>(result)
        assertEquals("OUT_OF_STOCK", error.errorCode)
        assertEquals("product-1", error.details?.get("items"))
        assertEquals(CheckoutCall("Новосибирск", "Ленина", "1", "10", ShopPaymentMethod.Sbp), repository.calls.single())
    }

    @Test
    fun repeatedExecutionReturnsRepositoryIdempotentOrder() = runTest {
        val order = ShopOrder(1042, ShopOrderStatus.Created, "300.00", "Address", ShopPaymentMethod.Card)
        val repository = CheckoutRepositoryFake(ShopCheckoutResult.Success(order, "/demo/payments/p1"))
        val useCase = CheckoutUseCase(repository)

        val first = useCase.execute("City", "Street", "1", "", ShopPaymentMethod.Card)
        val second = useCase.execute("City", "Street", "1", "", ShopPaymentMethod.Card)

        assertEquals(first, second)
        assertEquals(2, repository.calls.size)
    }
}

private data class CheckoutCall(
    val city: String,
    val street: String,
    val house: String,
    val apartment: String,
    val paymentMethod: ShopPaymentMethod,
)

private class CheckoutRepositoryFake(private val result: ShopCheckoutResult) : ShopRepository {
    val calls = mutableListOf<CheckoutCall>()
    override suspend fun checkout(city: String, street: String, house: String, apartment: String, paymentMethod: ShopPaymentMethod): ShopCheckoutResult {
        calls += CheckoutCall(city, street, house, apartment, paymentMethod)
        return result
    }
    override suspend fun getCategories(): List<String>? = emptyList()
    override suspend fun getProducts(query: String, category: String?, page: Int, size: Int, sort: String?) = Pair(emptyList<ShopProduct>(), 0)
    override suspend fun getPopularProducts(limit: Int): List<ShopProduct>? = emptyList()
    override suspend fun getProduct(id: String): ShopProduct? = null
    override suspend fun getCartItems(): Pair<List<ShopCartItem>, String?>? = Pair(emptyList(), null)
    override suspend fun getOrders(page: Int, size: Int): Pair<List<ShopOrder>, Int>? = Pair(emptyList(), 0)
    override suspend fun addToCart(productId: String, quantity: Int) = false
    override suspend fun setCartQuantity(productId: String, quantity: Int) = false
}
