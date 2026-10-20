package com.shopkmp.uikit.component.barspacer

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun EpsStatusBarSpacer() {
    androidx.compose.foundation.layout.Box(
        modifier = Modifier.fillMaxWidth().statusBarsPadding()
    )
}
