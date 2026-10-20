package com.shopkmp.uikit.component.screen

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color

data class EpsScreenSurfaceState(
    val backgroundColor: Color = Color(0xFFF6F7F9),
)

@Composable
fun EpsScreenSurface(
    state: EpsScreenSurfaceState,
    modifier: Modifier = Modifier,
    content: @Composable BoxScope.() -> Unit,
) {
    Box(
        modifier = modifier.background(state.backgroundColor),
        content = content,
    )
}
