package com.shopkmp.product.ui

import androidx.compose.ui.graphics.Color
import com.shopkmp.home.domain.Money
import com.shopkmp.home.domain.ShopProduct
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.shop.EpsProductDetailsContentState
import com.shopkmp.uikit.component.shop.EpsProductDetailsState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState

internal class ProductDetailsStateBuilder {
    fun build(
        product: ShopProduct,
        quantity: Int,
        onBack: () -> Unit,
        onDecrease: () -> Unit,
        onIncrease: () -> Unit,
        onAdd: () -> Unit,
    ) = EpsProductDetailsContentState(
        headerState = EpsScreenHeaderState(
            title = "Товар",
            backButtonState = EpsButtonState(
                text = "Назад",
                type = EpsButtonType.Secondary,
                onClick = onBack,
            ),
        ),
        detailsState = EpsProductDetailsState(
            name = product.name,
            ratingText = "${product.rating} *   ${product.reviews} отзывов",
            priceText = Money.parseOrNull(product.priceRub)?.formatRub() ?: "—",
            description = product.description,
            stockText = "Остаток: ${product.stock} шт.",
            imageUrl = product.imageUrl,
            imageState = EpsImagePlaceholderState(
                label = product.name.take(2).uppercase(),
                color = Color(0xFFE8F1FB),
            ),
            quantityStepperState = EpsQuantityStepperState(
                value = quantity,
                decreaseEnabled = quantity > 1,
                increaseEnabled = quantity < product.stock,
                onDecrease = onDecrease,
                onIncrease = onIncrease,
            ),
        ),
        addToCartButtonState = EpsButtonState(
            text = "Добавить в корзину",
            enabled = product.stock > 0,
            onClick = onAdd,
        ),
    )
}
