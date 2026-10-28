package com.shopkmp.uikit.component.topbar

import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Text
import androidx.compose.material3.IconButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextOverflow
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EpsTopBar(state: EpsTopBarState, modifier: Modifier = Modifier) {
    if (!state.isVisible) return
    TopAppBar(
        modifier = modifier,
        // BaseScreen owns the status-bar spacer. Do not apply it a second time here.
        windowInsets = WindowInsets(0, 0, 0, 0),
        colors = TopAppBarDefaults.topAppBarColors(
            containerColor = ShopTokens.Primary,
            scrolledContainerColor = ShopTokens.Primary,
            titleContentColor = ShopTokens.OnPrimary,
            navigationIconContentColor = ShopTokens.OnPrimary,
            actionIconContentColor = ShopTokens.OnPrimary,
        ),
        title = {
            Text(
                state.title,
                modifier = if (state.titleTestTag.isEmpty()) Modifier else Modifier.testTag(state.titleTestTag),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        },
        navigationIcon = {
            if (state.showBackButton && state.onBackClick != null) {
                IconButton(
                    onClick = state.onBackClick,
                    modifier = if (state.backTestTag.isEmpty()) Modifier else Modifier.testTag(state.backTestTag),
                ) {
                    EpsIcon(EpsIconName.Back, "Назад")
                }
            }
        },
        actions = {
            if (state.showNotificationsButton && state.onNotificationsClick != null) {
                IconButton(onClick = state.onNotificationsClick) {
                    EpsIcon(EpsIconName.Notifications, "Уведомления")
                }
            }
            state.actions.forEach { action ->
                IconButton(
                    onClick = action.onClick,
                    enabled = action.enabled,
                    modifier = if (action.testTag.isEmpty()) Modifier else Modifier.testTag(action.testTag),
                ) {
                    EpsIcon(action.icon, action.contentDescription)
                }
            }
        },
    )
}
