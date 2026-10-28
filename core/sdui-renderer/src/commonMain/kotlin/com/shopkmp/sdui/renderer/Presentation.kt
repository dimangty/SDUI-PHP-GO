package com.shopkmp.sdui.renderer

import androidx.compose.ui.graphics.Color
import com.shopkmp.sdui.model.Money
import com.shopkmp.sdui.model.OrderStatus
import com.shopkmp.sdui.model.PaymentMethod
import com.shopkmp.sdui.model.CartProps
import com.shopkmp.sdui.model.MediaPolicy
import com.shopkmp.sdui.actions.EventRegistry
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.uikit.component.shop.EpsCartItemState
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState

internal fun rubDisplay(amount: String): String {
    require(Money.valid(amount))
    val (whole, fraction) = amount.split('.')
    return whole.reversed().chunked(3).joinToString(" ").reversed() + ",$fraction ₽"
}

internal fun OrderStatus.displayLabel(): String = when (this) {
    OrderStatus.CREATED -> "Создан"
    OrderStatus.PAID -> "Оплачен"
    OrderStatus.ASSEMBLING -> "Собирается"
    OrderStatus.DELIVERING -> "Доставляется"
    OrderStatus.COMPLETED -> "Выполнен"
    OrderStatus.CANCELLED -> "Отменён"
}

internal fun OrderStatus.displayColor(): Color = when (this) {
    OrderStatus.CREATED -> ShopTokens.SecondaryText
    OrderStatus.PAID, OrderStatus.DELIVERING -> ShopTokens.Primary
    OrderStatus.ASSEMBLING -> Color(0xFFB45309)
    OrderStatus.COMPLETED -> Color(0xFF15803D)
    OrderStatus.CANCELLED -> ShopTokens.Danger
}

internal fun PaymentMethod.displayLabel(): String = when (this) {
    PaymentMethod.CARD -> "Карта"
    PaymentMethod.SBP -> "СБП"
    PaymentMethod.ON_DELIVERY -> "При получении"
}

internal fun cartItemPresentation(props: CartProps, origin: String, events: EventRegistry): EpsCartItemState = with(props) {
    EpsCartItemState(name, rubDisplay(price), if (!available || quantity > stock) "Недостаточно товара · Доступно: $stock" else null,
        EpsImagePlaceholderState(name), EpsCardState(onClick = events.activate(openAction)),
        EpsQuantityStepperState(quantity, events.enabled(quantityAction) && quantity > 0,
            events.enabled(quantityAction) && quantity < minOf(stock, 1_000_000),
            { events.quantity(quantityAction, quantity - 1) }, { events.quantity(quantityAction, quantity + 1) }),
        imageUrl = MediaPolicy.publicUrl(image, origin))
}
