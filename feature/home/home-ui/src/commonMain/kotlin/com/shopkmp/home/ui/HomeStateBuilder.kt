package com.shopkmp.home.ui

import com.shopkmp.home.domain.Money
import com.shopkmp.home.domain.ShopProduct
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.chip.EpsChipState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.shop.EpsHomeContentState
import com.shopkmp.uikit.component.shop.EpsProductState
import com.shopkmp.uikit.component.shop.EpsSectionHeaderState
import com.shopkmp.uikit.component.shop.EpsShopHeaderState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

internal class HomeStateBuilder {
    fun build(
        categories: List<String>,
        products: List<ShopProduct>,
        cartItemsCount: Int,
        onSearch: (String) -> Unit,
        onCatalog: (String?) -> Unit,
        onProfile: () -> Unit,
        onProduct: (String) -> Unit,
    ) = EpsHomeContentState(
        headerState = EpsShopHeaderState(
            title = "ShopKMP",
            subtitle = "Покупки в несколько касаний",
            actionButtonState = secondaryButton("Профиль", onProfile),
        ),
        searchState = EpsTextFieldState(
            label = "Поиск товаров, брендов, категорий...",
            onValueChange = onSearch,
        ),
        categoriesHeaderState = EpsSectionHeaderState(title = "Категории"),
        categoryStates = categories.map { category ->
            EpsChipState(text = category, onClick = { onCatalog(category) })
        },
        popularHeaderState = EpsSectionHeaderState(
            title = "Популярное",
            subtitle = "В корзине: $cartItemsCount",
            actionButtonState = secondaryButton("Каталог") { onCatalog(null) },
        ),
        popularProducts = products.map { it.toUiState(onProduct) },
    )

    private fun ShopProduct.toUiState(onProduct: (String) -> Unit) = EpsProductState(
        name = name,
        description = description,
        priceText = Money.parseOrNull(priceRub)?.formatRub() ?: "—",
        ratingText = "$rating *",
        imageUrl = imageUrl,
        imageState = EpsImagePlaceholderState(
            label = name.initials(),
            color = imageColor,
        ),
        cardState = EpsCardState(onClick = { onProduct(id) }),
    )

    private fun secondaryButton(text: String, onClick: () -> Unit) = EpsButtonState(
        text = text,
        type = EpsButtonType.Secondary,
        onClick = onClick,
    )

    private fun String.initials() = split(" ")
        .filter { it.isNotBlank() }
        .take(2)
        .mapNotNull { it.firstOrNull()?.uppercaseChar()?.toString() }
        .joinToString("")
        .ifBlank { "SK" }

    private val ShopProduct.imageColor
        get() = androidx.compose.ui.graphics.Color(0xFFE8F1FB)
}
