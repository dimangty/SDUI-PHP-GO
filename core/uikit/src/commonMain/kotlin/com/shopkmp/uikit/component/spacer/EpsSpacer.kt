package com.shopkmp.uikit.component.spacer

import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun EpsSpacer(
    state: EpsSpacerState,
    modifier: Modifier = Modifier,
) {
    val defaultHeight = when (state.type) {
        EpsSpacerType.Small -> 8.dp
        EpsSpacerType.Medium -> 16.dp
        EpsSpacerType.Large -> 32.dp
    }
    val resolvedModifier = when {
        state.height != null && state.width != null -> modifier.height(state.height).width(state.width)
        state.height != null -> modifier.height(state.height)
        state.width != null -> modifier.width(state.width)
        else -> modifier.height(defaultHeight)
    }

    Spacer(resolvedModifier)
}
