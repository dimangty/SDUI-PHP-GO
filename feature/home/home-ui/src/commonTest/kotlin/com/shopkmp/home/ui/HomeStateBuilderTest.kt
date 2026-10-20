package com.shopkmp.home.ui

import com.shopkmp.home.domain.ShopProduct
import kotlin.test.Test
import kotlin.test.assertEquals

class HomeStateBuilderTest {
    @Test
    fun productCallbackStaysInsideFeatureBoundary() {
        var selected = ""
        val state = HomeStateBuilder().build(
            categories = listOf("Категория"),
            products = listOf(
                ShopProduct("p1", "Товар", "Категория", "Описание", "10.00", 1, 5.0, 1),
            ),
            cartItemsCount = 2,
            onSearch = {},
            onCatalog = {},
            onProfile = {},
            onProduct = { selected = it },
        )
        state.popularProducts.single().cardState.onClick?.invoke()
        assertEquals("p1", selected)
        assertEquals("В корзине: 2", state.popularHeaderState.subtitle)
    }
}
