package com.shopkmp.product.ui

import com.shopkmp.home.domain.ShopProduct
import kotlin.test.Test
import kotlin.test.assertFalse

class ProductDetailsStateBuilderTest {
    @Test
    fun soldOutProductCannotBeAdded() {
        val state = ProductDetailsStateBuilder().build(
            product = ShopProduct("p1", "Товар", "Дом", "Описание", "10.00", 0, 4.0, 2),
            quantity = 1,
            onBack = {},
            onDecrease = {},
            onIncrease = {},
            onAdd = {},
        )
        assertFalse(state.addToCartButtonState.enabled)
    }
}
