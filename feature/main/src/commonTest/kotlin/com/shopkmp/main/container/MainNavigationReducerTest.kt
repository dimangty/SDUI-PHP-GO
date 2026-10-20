package com.shopkmp.main.container

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

class MainNavigationReducerTest {
    private val reducer = MainNavigationReducer()

    @Test
    fun productAndCartTransitionsOnlyChangeNavigationState() {
        val product = reducer.reduce(
            MainContainerState(),
            MainContainerUiEvent.OnProductSelected("product-1"),
        )
        assertEquals("product-1", product.selectedProductId)

        val cart = reducer.reduce(product, MainContainerUiEvent.OnProductAddedToCart)
        assertEquals(MainTab.Cart, cart.selectedTab)
        assertNull(cart.selectedProductId)
    }

    @Test
    fun catalogRequestCarriesInitialFilter() {
        val state = reducer.reduce(
            MainContainerState(),
            MainContainerUiEvent.OnCatalogRequested("phone", "Электроника"),
        )
        assertEquals(MainTab.Catalog, state.selectedTab)
        assertEquals("phone", state.catalogQuery)
        assertEquals("Электроника", state.catalogCategory)
    }
}
