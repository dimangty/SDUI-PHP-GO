package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.quantity.EpsQuantityStepper
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState

data class EpsCartItemState(
    val productName: String,
    val priceText: String,
    val availabilityText: String? = null,
    val imageState: EpsImagePlaceholderState,
    val cardState: EpsCardState,
    val quantityStepperState: EpsQuantityStepperState,
)

@Composable
fun EpsCartItem(
    state: EpsCartItemState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = state.cardState,
        modifier = modifier,
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            EpsProductImage(state = state.imageState, modifier = Modifier.size(64.dp))
            EpsSpacer(EpsSpacerState.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                EpsText(EpsTextState(state.productName, fontWeight = FontWeight.SemiBold))
                EpsSecondaryText(state.priceText)
                state.availabilityText?.let { EpsSecondaryText(it) }
            }
            EpsQuantityStepper(state.quantityStepperState)
        }
    }
}
