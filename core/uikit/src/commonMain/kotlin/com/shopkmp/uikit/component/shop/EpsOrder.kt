package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState

@Composable
fun EpsOrderRow(
    state: EpsOrderRowState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = state.cardState,
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(modifier = Modifier.fillMaxWidth()) {
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                EpsText(
                    state = EpsTextState("Заказ #${state.number}", fontWeight = FontWeight.SemiBold),
                    modifier = Modifier.weight(1f),
                )
                EpsText(EpsTextState(state.total))
            }
            EpsSpacer(EpsSpacerState.height(8.dp))
            EpsText(EpsTextState("● ${state.status}", color = state.statusColor))
            if (!state.compact) {
                EpsSecondaryText("Адрес: ${state.deliveryAddress}")
                EpsSecondaryText("Оплата: ${state.paymentMethod}")
            }
        }
    }
}
