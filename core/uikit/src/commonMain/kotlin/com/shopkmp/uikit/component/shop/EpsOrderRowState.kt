package com.shopkmp.uikit.component.shop

import androidx.compose.ui.graphics.Color
import com.shopkmp.uikit.component.card.EpsCardState

data class EpsOrderRowState(
    val number: Int,
    val status: String,
    val total: String,
    val deliveryAddress: String,
    val paymentMethod: String,
    val statusColor: Color,
    val cardState: EpsCardState,
)
