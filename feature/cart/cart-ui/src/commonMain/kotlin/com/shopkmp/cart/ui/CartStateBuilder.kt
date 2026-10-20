package com.shopkmp.cart.ui

import androidx.compose.ui.graphics.Color
import com.shopkmp.home.domain.Money
import com.shopkmp.home.domain.ShopCartItem
import com.shopkmp.home.domain.ShopPaymentMethod
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.shop.EpsCartContentState
import com.shopkmp.uikit.component.shop.EpsCartEmptyState
import com.shopkmp.uikit.component.shop.EpsCartItemState
import com.shopkmp.uikit.component.shop.EpsCartTotalState
import com.shopkmp.uikit.component.shop.EpsPaymentSelectorState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

internal class CartStateBuilder {
    @Suppress("LongParameterList")
    fun build(
        items: List<ShopCartItem>,
        total: String?,
        city: String,
        street: String,
        house: String,
        apartment: String,
        paymentMethod: ShopPaymentMethod,
        statusMessage: String?,
        onCity: (String) -> Unit,
        onStreet: (String) -> Unit,
        onHouse: (String) -> Unit,
        onApartment: (String) -> Unit,
        onPaymentMethod: (ShopPaymentMethod) -> Unit,
        onQuantity: (String, Int) -> Unit,
        onCheckout: () -> Unit,
    ) = EpsCartContentState(
        headerState = EpsScreenHeaderState(title = "Корзина"),
        items = items.map { item ->
            EpsCartItemState(
                productName = item.product.name,
                priceText = Money.parseOrNull(item.product.priceRub)?.formatRub() ?: "—",
                availabilityText = if (item.isAvailable) null else "Недоступен для заказа",
                imageState = EpsImagePlaceholderState(
                    label = item.product.name.take(2).uppercase(),
                    color = Color(0xFFE8F1FB),
                ),
                cardState = EpsCardState(),
                quantityStepperState = EpsQuantityStepperState(
                    value = item.quantity,
                    increaseEnabled = item.isAvailable,
                    onDecrease = { onQuantity(item.product.id, item.quantity - 1) },
                    onIncrease = { onQuantity(item.product.id, item.quantity + 1) },
                ),
            )
        },
        emptyState = EpsCartEmptyState(
            title = "Корзина пуста",
            subtitle = "Добавьте товары из каталога, чтобы оформить заказ.",
            cardState = EpsCardState(),
        ),
        dividerState = EpsDividerState(),
        totalState = EpsCartTotalState(Money.parseOrNull(total ?: "0")?.formatRub() ?: "—"),
        cityTextFieldState = textField(city, "Город", onCity),
        streetTextFieldState = textField(street, "Улица", onStreet),
        houseTextFieldState = textField(house, "Дом", onHouse),
        apartmentTextFieldState = textField(apartment, "Квартира", onApartment),
        paymentSelectorState = EpsPaymentSelectorState(
            title = "Оплата",
            buttonStates = ShopPaymentMethod.entries.map { method ->
                EpsButtonState(
                    text = method.title,
                    type = if (paymentMethod == method) EpsButtonType.Primary else EpsButtonType.Secondary,
                    onClick = { onPaymentMethod(method) },
                )
            },
        ),
        checkoutButtonState = EpsButtonState(
            text = "Подтвердить заказ",
            enabled = items.isNotEmpty() && items.all { it.isAvailable },
            onClick = onCheckout,
        ),
        checkoutStatusMessage = statusMessage ?: if (items.any { !it.isAvailable }) {
            "Удалите недоступные товары из корзины"
        } else {
            null
        },
    )

    private fun textField(value: String, label: String, onChange: (String) -> Unit) =
        EpsTextFieldState(value = value, label = label, onValueChange = onChange)

    private val ShopPaymentMethod.title: String
        get() = when (this) {
            ShopPaymentMethod.Card -> "Карта"
            ShopPaymentMethod.Sbp -> "СБП"
            ShopPaymentMethod.OnDelivery -> "При получении"
        }
}
