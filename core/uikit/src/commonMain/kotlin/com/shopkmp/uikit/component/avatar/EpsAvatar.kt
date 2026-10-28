package com.shopkmp.uikit.component.avatar

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

@Composable
fun EpsAvatar(
    state: EpsAvatarState,
    modifier: Modifier = Modifier,
    size: Dp = 96.dp,
) {
    Box(
        modifier = modifier
            .size(size)
            .clip(CircleShape)
            .background(state.backgroundColor)
            .border(1.dp, ShopTokens.Primary, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        if (state.showPersonPlaceholder) {
            EpsIcon(EpsIconName.Profile, null, Modifier.size(size * 0.55f), ShopTokens.Primary)
        } else {
            EpsText(
                EpsTextState(
                    text = state.initials,
                    style = EpsTextStyle.Avatar,
                ),
            )
        }
    }
}
