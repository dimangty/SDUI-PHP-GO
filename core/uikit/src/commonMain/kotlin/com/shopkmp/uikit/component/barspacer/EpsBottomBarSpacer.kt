package com.shopkmp.uikit.component.barspacer

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun EpsBottomBarSpacer() {
    androidx.compose.foundation.layout.Box(
        modifier = Modifier.fillMaxWidth().navigationBarsPadding()
    )
}
