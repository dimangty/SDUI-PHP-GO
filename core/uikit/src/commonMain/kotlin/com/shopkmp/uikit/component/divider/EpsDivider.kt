package com.shopkmp.uikit.component.divider

import androidx.compose.material3.HorizontalDivider
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsDivider(
    state: EpsDividerState,
    modifier: Modifier = Modifier,
) {
    if (state.visible) {
        HorizontalDivider(modifier = modifier, color = ShopTokens.Border)
    }
}
