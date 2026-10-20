package com.shopkmp.main.sdui

import com.shopkmp.home.domain.*
import com.shopkmp.sdui.actions.CheckoutInput
import com.shopkmp.sdui.actions.PaymentUrlPolicy
import com.shopkmp.sdui.model.PaymentMethod
import kotlinx.coroutines.test.runTest
import kotlin.test.*

class NativeCheckoutAdapterTest {
    @Test fun invalidAddressNeverEntersNativeCheckout() = runTest {
        val repository = CheckoutRepository()
        val adapter = NativeCheckoutAdapter(CheckoutUseCase(repository), PaymentUrlPolicy("https://shop.example")) {}
        assertFailsWith<IllegalArgumentException> { adapter.execute(CheckoutInput("", "Street", "1", "", PaymentMethod.CARD)) }
        assertEquals(0, repository.calls)
    }

    @Test fun allPaymentMethodsUseTheFiveExactNativeArguments() = runTest {
        val repository = CheckoutRepository()
        val adapter = NativeCheckoutAdapter(CheckoutUseCase(repository), PaymentUrlPolicy("https://shop.example")) {}
        listOf(PaymentMethod.CARD to ShopPaymentMethod.Card, PaymentMethod.SBP to ShopPaymentMethod.Sbp,
            PaymentMethod.ON_DELIVERY to ShopPaymentMethod.OnDelivery).forEach { (wire, native) ->
            adapter.execute(CheckoutInput("City", "Street", "House", "Apartment", wire))
            assertEquals(listOf("City", "Street", "House", "Apartment", native.name), repository.arguments)
        }
    }

    @Test fun partialCheckoutDoesNotRepeatAndOnlyLocallyAllowedPaymentOriginsLaunch() = runTest {
        val repository = CheckoutRepository()
        val launched = mutableListOf<String>()
        val adapter = NativeCheckoutAdapter(CheckoutUseCase(repository), PaymentUrlPolicy("https://shop.example", setOf("https://pay.example")), launched::add)
        val input = CheckoutInput("City", "Street", "1", "", PaymentMethod.CARD)
        repository.result = success("https://evil.example/pay", failed = true)
        assertIs<NativeResult.CheckoutComplete>(adapter.execute(input))
        assertTrue(launched.isEmpty())
        assertEquals(1, repository.calls)
        repository.result = success("https://pay.example/pay")
        adapter.execute(input)
        assertEquals(listOf("https://pay.example/pay"), launched)
        assertEquals(2, repository.calls)
    }
}
private fun success(url: String? = null, failed: Boolean = false) = ShopCheckoutResult.Success(
    ShopOrder(42, ShopOrderStatus.Created, "100.02", "Address", ShopPaymentMethod.Card), url, failed)
private class CheckoutRepository : ShopRepository {
    var arguments = emptyList<String>()
    var calls = 0
    var result: ShopCheckoutResult = success()
    override suspend fun checkout(city: String, street: String, house: String, apartment: String, paymentMethod: ShopPaymentMethod): ShopCheckoutResult {
        calls++; arguments = listOf(city, street, house, apartment, paymentMethod.name); return result
    }
    override suspend fun getCategories(): List<String>? = error("Unexpected catalog access")
    override suspend fun getProducts(query: String, category: String?, page: Int, size: Int, sort: String?): Pair<List<ShopProduct>, Int>? = error("Unexpected catalog access")
    override suspend fun getPopularProducts(limit: Int): List<ShopProduct>? = error("Unexpected catalog access")
    override suspend fun getProduct(id: String): ShopProduct? = error("Unexpected catalog access")
    override suspend fun getCartItems(): Pair<List<ShopCartItem>, String?>? = error("Unexpected cart access")
    override suspend fun getOrders(page: Int, size: Int): Pair<List<ShopOrder>, Int>? = error("Unexpected order access")
    override suspend fun addToCart(productId: String, quantity: Int) = error("Unexpected mutation")
    override suspend fun setCartQuantity(productId: String, quantity: Int) = error("Unexpected mutation")
}
