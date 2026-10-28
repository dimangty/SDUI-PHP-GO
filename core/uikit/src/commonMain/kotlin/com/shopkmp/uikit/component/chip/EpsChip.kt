package com.shopkmp.uikit.component.chip

import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.BorderStroke
import androidx.compose.ui.unit.dp
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsChip(state: EpsChipState, modifier: Modifier = Modifier) {
    if (state.selected) {
        FilterChip(
            modifier = modifier,
            selected = true,
            onClick = { state.onClick?.invoke() },
            label = { Text(state.text) },
            shape = CircleShape,
            border = BorderStroke(1.dp, ShopTokens.Primary),
            colors = FilterChipDefaults.filterChipColors(
                selectedContainerColor = ShopTokens.PrimaryContainer,
                selectedLabelColor = ShopTokens.Primary,
            ),
        )
    } else {
        AssistChip(
            modifier = modifier,
            onClick = { state.onClick?.invoke() },
            label = { Text(state.text) },
            shape = CircleShape,
            border = null,
            colors = AssistChipDefaults.assistChipColors(
                containerColor = ShopTokens.PrimaryContainer,
                labelColor = ShopTokens.Primary,
            ),
        )
    }
}
