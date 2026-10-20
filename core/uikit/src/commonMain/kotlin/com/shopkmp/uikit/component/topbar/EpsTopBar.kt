package com.shopkmp.uikit.component.topbar

import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EpsTopBar(state: EpsTopBarState) {
    if (!state.isVisible) return
    TopAppBar(
        title = { Text(state.title) },
        navigationIcon = {
            if (state.showBackButton && state.onBackClick != null) {
                TextButton(onClick = state.onBackClick) {
                    Text("Back")
                }
            }
        },
        actions = {
            if (state.showNotificationsButton && state.onNotificationsClick != null) {
                TextButton(onClick = state.onNotificationsClick) {
                    Text("Bell")
                }
            }
        }
    )
}
