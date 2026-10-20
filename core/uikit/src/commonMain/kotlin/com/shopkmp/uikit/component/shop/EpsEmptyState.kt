package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

@Composable
fun EpsEmptyState(
    title: String,
    subtitle: String,
    cardState: EpsCardState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = cardState,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            EpsText(EpsTextState(title, style = EpsTextStyle.Title))
            EpsSecondaryText(subtitle)
        }
    }
}
