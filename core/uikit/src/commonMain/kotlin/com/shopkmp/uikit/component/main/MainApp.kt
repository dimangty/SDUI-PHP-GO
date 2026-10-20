package com.shopkmp.uikit.component.main

import androidx.compose.runtime.Composable
import com.shopkmp.ui.theme.AppTheme

@Composable
fun MainApp(content: @Composable () -> Unit) {
    AppTheme(content = content)
}
