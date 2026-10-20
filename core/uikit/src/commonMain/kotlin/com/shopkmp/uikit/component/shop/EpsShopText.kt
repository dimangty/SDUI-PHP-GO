package com.shopkmp.uikit.component.shop

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

@Composable
fun EpsScreenTitle(
    text: String,
    modifier: Modifier = Modifier,
) {
    EpsText(
        state = EpsTextState(text, style = EpsTextStyle.ScreenTitle),
        modifier = modifier,
    )
}

@Composable
fun EpsSectionTitle(
    text: String,
    modifier: Modifier = Modifier,
) {
    EpsText(
        state = EpsTextState(text, style = EpsTextStyle.SectionTitle),
        modifier = modifier,
    )
}

@Composable
fun EpsSecondaryText(
    text: String,
    modifier: Modifier = Modifier,
) {
    EpsText(
        state = EpsTextState(text, style = EpsTextStyle.Secondary),
        modifier = modifier,
    )
}
