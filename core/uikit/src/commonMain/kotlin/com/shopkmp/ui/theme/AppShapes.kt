package com.shopkmp.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp

object AppShapes {
    val primaryTop = RoundedCornerShape(
        topStart = 8.dp,
        topEnd = 8.dp,
        bottomStart = 0.dp,
        bottomEnd = 0.dp
    )
    val primaryBottom = RoundedCornerShape(
        topStart = 0.dp,
        topEnd = 0.dp,
        bottomStart = 8.dp,
        bottomEnd = 8.dp
    )
    val primary = RoundedCornerShape(12.dp)
    val field = RoundedCornerShape(10.dp)
    val card = RoundedCornerShape(14.dp)
    val rounded = RoundedCornerShape(22.dp)
    val material = Shapes(
        extraSmall = RoundedCornerShape(4.dp),
        small = field,
        medium = primary,
        large = card,
        extraLarge = RoundedCornerShape(20.dp),
    )
}
