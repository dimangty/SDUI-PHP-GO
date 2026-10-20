package com.shopkmp.catalog.ui

import androidx.compose.ui.graphics.Color
import com.shopkmp.home.domain.Money
import com.shopkmp.home.domain.ShopProduct
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.chip.EpsChipState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.shop.EpsCatalogContentState
import com.shopkmp.uikit.component.shop.EpsProductState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

internal class CatalogStateBuilder {
    fun build(
        categories: List<String>,
        products: List<ShopProduct>,
        query: String,
        selectedCategory: String?,
        onQueryChanged: (String) -> Unit,
        onCategoryChanged: (String?) -> Unit,
        onProduct: (String) -> Unit,
    ) = EpsCatalogContentState(
        headerState = EpsScreenHeaderState(title = "Каталог"),
        searchState = EpsTextFieldState(
            value = query,
            label = "Поиск товара",
            onValueChange = onQueryChanged,
        ),
        categoryStates = (listOf<String?>(null) + categories).map { value ->
                EpsChipState(
                    text = value ?: "Все",
                    selected = selectedCategory == value,
                    onClick = { onCategoryChanged(value) },
                )
        },
        products = products.map { product ->
            EpsProductState(
                name = product.name,
                description = product.description,
                priceText = Money.parseOrNull(product.priceRub)?.formatRub() ?: "—",
                ratingText = "${product.rating} *",
                imageUrl = product.imageUrl,
                imageState = EpsImagePlaceholderState(
                    label = product.name.take(2).uppercase(),
                    color = Color(0xFFE8F1FB),
                ),
                cardState = EpsCardState(onClick = { onProduct(product.id) }),
            )
        },
    )
}
