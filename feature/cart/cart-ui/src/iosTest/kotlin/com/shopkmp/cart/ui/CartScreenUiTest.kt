package com.shopkmp.cart.ui

import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.runComposeUiTest
import com.shopkmp.home.domain.ShopCartItem
import com.shopkmp.home.domain.ShopPaymentMethod
import com.shopkmp.home.domain.ShopProduct
import kotlin.test.Test
import kotlin.test.assertTrue

@OptIn(ExperimentalTestApi::class)
class CartScreenUiTest {
    @Test
    fun populatedCartShowsTotalAndInvokesCheckout() = runComposeUiTest {
        var checkedOut = false
        val state = CartStateBuilder().build(
            items = listOf(
                ShopCartItem(
                    product = ShopProduct(
                        id = "p1",
                        name = "Телефон",
                        category = "Электроника",
                        description = "",
                        priceRub = "100.00",
                        stock = 2,
                        rating = 5.0,
                        reviews = 1,
                    ),
                    quantity = 1,
                ),
            ),
            total = "100.00",
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
            onCheckout = { checkedOut = true },
        )
        setContent { CartView(state) }

        onNodeWithText("Телефон").assertIsDisplayed()
        onNodeWithText("Итого: 100,00 ₽").assertIsDisplayed()
        onNodeWithText("Подтвердить заказ").performClick()

        assertTrue(checkedOut)
    }
}
