package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState

data class EpsDeviceRowState(
    val name: String,
    val subtitle: String,
    val currentLabel: String?,
    val cardState: EpsCardState,
    val revokeButtonState: EpsButtonState,
)

@Composable
fun EpsDeviceRow(
    state: EpsDeviceRowState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = state.cardState,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(modifier = Modifier.fillMaxWidth()) {
                EpsText(
                    state = EpsTextState(state.name, fontWeight = FontWeight.SemiBold),
                    modifier = Modifier.weight(1f),
                )
                state.currentLabel?.let { label ->
                    EpsSecondaryText(label)
                }
            }
            EpsSecondaryText(state.subtitle)
            EpsButton(state.revokeButtonState, modifier = Modifier.fillMaxWidth())
        }
    }
}
