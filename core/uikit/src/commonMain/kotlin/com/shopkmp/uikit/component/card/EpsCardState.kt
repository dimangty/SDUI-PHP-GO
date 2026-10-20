package com.shopkmp.uikit.component.card

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

data class EpsCardState(
    val containerColor: Color = Color.White,
    val contentPadding: Dp = 12.dp,
    val onClick: (() -> Unit)? = null,
)
