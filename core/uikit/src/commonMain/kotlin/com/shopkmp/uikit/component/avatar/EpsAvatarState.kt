package com.shopkmp.uikit.component.avatar

import androidx.compose.ui.graphics.Color
import com.shopkmp.ui.theme.ShopTokens

data class EpsAvatarState(
    val initials: String = "",
    val backgroundColor: Color = ShopTokens.PrimaryContainer,
    val showPersonPlaceholder: Boolean = true,
)
