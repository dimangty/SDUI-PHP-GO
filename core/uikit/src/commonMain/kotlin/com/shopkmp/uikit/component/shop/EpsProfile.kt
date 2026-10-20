package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.avatar.EpsAvatar
import com.shopkmp.uikit.component.avatar.EpsAvatarState
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

data class EpsProfileHeaderState(
    val initials: String,
    val name: String,
    val email: String,
    val avatarSize: Dp = 72.dp,
)

data class EpsProfileFieldState(
    val title: String,
    val value: String,
)

@Composable
fun EpsProfileHeader(
    state: EpsProfileHeaderState,
    modifier: Modifier = Modifier,
) {
    androidx.compose.foundation.layout.Column(
        modifier = modifier,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        EpsAvatar(EpsAvatarState(state.initials), size = state.avatarSize)
        EpsText(EpsTextState(state.name, style = EpsTextStyle.Title))
        EpsSecondaryText(state.email)
    }
}

@Composable
fun EpsProfileField(
    title: String,
    value: String,
    modifier: Modifier = Modifier,
) {
    Row(modifier = modifier.fillMaxWidth()) {
        EpsSecondaryText(title)
        Spacer(modifier = Modifier.weight(1f))
        EpsText(EpsTextState(value, fontWeight = FontWeight.SemiBold))
    }
}

@Composable
fun EpsProfileField(
    state: EpsProfileFieldState,
    modifier: Modifier = Modifier,
) {
    EpsProfileField(
        title = state.title,
        value = state.value,
        modifier = modifier,
    )
}

@Composable
fun EpsProfileAction(
    title: String,
    cardState: EpsCardState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = cardState,
        modifier = modifier.fillMaxWidth(),
    ) {
        Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            EpsText(
                state = EpsTextState(title),
                modifier = Modifier.weight(1f),
            )
            EpsText(EpsTextState(">"))
        }
    }
}
