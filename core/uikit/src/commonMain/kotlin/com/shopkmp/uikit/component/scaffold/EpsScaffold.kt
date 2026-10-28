package com.shopkmp.uikit.component.scaffold

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsScaffold(
    modifier: Modifier = Modifier,
    bottomBar: @Composable () -> Unit = {},
    content: @Composable (PaddingValues) -> Unit,
) {
    Scaffold(
        modifier = modifier,
        containerColor = ShopTokens.Background,
        contentColor = ShopTokens.Text,
        bottomBar = bottomBar,
        content = content,
    )
}
