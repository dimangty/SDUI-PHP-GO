package com.shopkmp.uikit.component.auth

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.divider.EpsDivider
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle
import com.shopkmp.ui.theme.ShopTokens

data class EpsAuthHeaderState(
    val title: String,
    val subtitle: String,
    val titleStyle: EpsTextStyle = EpsTextStyle.HeroTitle,
)

@Composable
fun EpsAuthHeader(
    state: EpsAuthHeaderState,
    modifier: Modifier = Modifier,
) {
    androidx.compose.foundation.layout.Column(
        modifier = modifier,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        EpsText(EpsTextState(state.title, style = state.titleStyle,
            color = if (state.titleStyle == EpsTextStyle.HeroTitle) ShopTokens.Primary else ShopTokens.Text))
        EpsSpacer(EpsSpacerState.height(6.dp))
        EpsText(EpsTextState(state.subtitle, style = EpsTextStyle.Secondary))
    }
}

@Composable
fun EpsStatusError(
    text: String?,
    modifier: Modifier = Modifier,
) {
    text ?: return

    androidx.compose.foundation.layout.Column(modifier = modifier.fillMaxWidth()) {
        EpsSpacer(EpsSpacerState.height(8.dp))
        EpsText(EpsTextState(text, style = EpsTextStyle.Error))
    }
}

data class EpsAuthDividerState(
    val label: String,
    val dividerState: EpsDividerState,
)

@Composable
fun EpsAuthDivider(
    state: EpsAuthDividerState,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        EpsDivider(state = state.dividerState, modifier = Modifier.weight(1f))
        EpsText(
            state = EpsTextState(state.label, style = EpsTextStyle.Secondary),
            modifier = Modifier.padding(horizontal = 12.dp),
        )
        EpsDivider(state = state.dividerState, modifier = Modifier.weight(1f))
    }
}

data class EpsInlineActionState(
    val label: String,
    val buttonState: EpsButtonState,
)

@Composable
fun EpsInlineAction(
    state: EpsInlineActionState,
    modifier: Modifier = Modifier,
    buttonModifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier,
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        EpsText(EpsTextState(state.label, style = EpsTextStyle.Secondary))
        EpsButton(state.buttonState, buttonModifier)
    }
}
