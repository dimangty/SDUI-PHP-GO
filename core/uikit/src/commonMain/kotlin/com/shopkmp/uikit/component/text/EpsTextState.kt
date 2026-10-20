package com.shopkmp.uikit.component.text

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight

data class EpsTextState(
    val text: String = "",
    val style: EpsTextStyle = EpsTextStyle.Body,
    val color: Color? = null,
    val fontWeight: FontWeight? = null,
)

enum class EpsTextStyle {
    Body,
    Secondary,
    Title,
    SectionTitle,
    ScreenTitle,
    HeroTitle,
    Price,
    Error,
    Avatar,
    PinDot,
}
