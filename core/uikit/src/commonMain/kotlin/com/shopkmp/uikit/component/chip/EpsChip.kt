package com.shopkmp.uikit.component.chip

import androidx.compose.material3.AssistChip
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun EpsChip(state: EpsChipState, modifier: Modifier = Modifier) {
    if (state.selected) {
        FilterChip(
            modifier = modifier,
            selected = true,
            onClick = { state.onClick?.invoke() },
            label = { Text(state.text) },
        )
    } else {
        AssistChip(
            modifier = modifier,
            onClick = { state.onClick?.invoke() },
            label = { Text(state.text) },
        )
    }
}
