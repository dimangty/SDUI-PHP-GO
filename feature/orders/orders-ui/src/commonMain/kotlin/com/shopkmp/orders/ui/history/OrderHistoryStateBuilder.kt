package com.shopkmp.orders.ui.history

import androidx.compose.ui.graphics.Color
import com.shopkmp.home.domain.Money
import com.shopkmp.home.domain.ShopOrder
import com.shopkmp.home.domain.ShopOrderStatus
import com.shopkmp.home.domain.ShopPaymentMethod
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.shop.EpsOrderHistoryContentState
import com.shopkmp.uikit.component.shop.EpsOrderRowState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState

internal class OrderHistoryStateBuilder {
    fun build(orders: List<ShopOrder>, onBack: () -> Unit) = EpsOrderHistoryContentState(
        headerState = EpsScreenHeaderState(
            title = "Мои заказы",
            backButtonState = EpsButtonState(
                text = "Назад",
                type = EpsButtonType.Secondary,
                onClick = onBack,
            ),
        ),
        orders = orders.map { order ->
            EpsOrderRowState(
                number = order.number,
                status = order.status.title,
                total = Money.parseOrNull(order.totalRub)?.formatRub() ?: "—",
                deliveryAddress = order.deliveryAddress,
                paymentMethod = order.paymentMethod.title,
                statusColor = order.status.color,
                cardState = EpsCardState(),
            )
        },
    )

    private val ShopOrderStatus.title: String
        get() = when (this) {
            ShopOrderStatus.Created -> "Создан"
            ShopOrderStatus.Paid -> "Оплачен"
            ShopOrderStatus.Assembling -> "Собирается"
            ShopOrderStatus.Delivering -> "Доставляется"
            ShopOrderStatus.Completed -> "Выполнен"
            ShopOrderStatus.Cancelled -> "Отменен"
        }

    private val ShopOrderStatus.color: Color
        get() = when (this) {
            ShopOrderStatus.Paid, ShopOrderStatus.Completed -> Color(0xFF0F7A3B)
            ShopOrderStatus.Cancelled -> Color(0xFFC0392B)
            else -> Color(0xFF2F6FED)
        }

    private val ShopPaymentMethod.title: String
        get() = when (this) {
            ShopPaymentMethod.Card -> "Карта"
            ShopPaymentMethod.Sbp -> "СБП"
            ShopPaymentMethod.OnDelivery -> "При получении"
        }
}
