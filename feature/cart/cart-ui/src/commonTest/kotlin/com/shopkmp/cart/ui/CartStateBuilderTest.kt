package com.shopkmp.cart.ui

import com.shopkmp.home.domain.ShopPaymentMethod
import kotlin.test.Test
import kotlin.test.assertFalse

class CartStateBuilderTest {
    @Test
    fun checkoutIsDisabledForEmptyCart() {
        val state = CartStateBuilder().build(
            items = emptyList(),
            total = "0.00",
            city = "",
            street = "",
            house = "",
            apartment = "",
            paymentMethod = ShopPaymentMethod.Card,
            statusMessage = null,
            onCity = {},
            onStreet = {},
            onHouse = {},
            onApartment = {},
            onPaymentMethod = {},
            onQuantity = { _, _ -> },
            onCheckout = {},
        )
        assertFalse(state.checkoutButtonState.enabled)
    }
}
