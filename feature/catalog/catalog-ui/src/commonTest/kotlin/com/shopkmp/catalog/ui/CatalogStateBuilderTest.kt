package com.shopkmp.catalog.ui

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

class CatalogStateBuilderTest {
    @Test
    fun selectedCategoryAndQueryAreMapped() {
        val state = CatalogStateBuilder().build(
            categories = listOf("Дом"),
            products = emptyList(),
            query = "лампа",
            selectedCategory = "Дом",
            onQueryChanged = {},
            onCategoryChanged = {},
            onProduct = {},
        )
        assertEquals("лампа", state.searchState.value)
        assertTrue(state.categoryStates.single { it.text == "Дом" }.selected)
    }
}
