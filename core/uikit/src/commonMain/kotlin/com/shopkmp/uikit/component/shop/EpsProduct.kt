package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.image.EpsImage
import com.shopkmp.uikit.component.image.EpsImageState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholder
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.quantity.EpsQuantityStepper
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

@Composable
fun EpsProductImage(
    state: EpsImagePlaceholderState,
    imageUrl: String? = null,
    modifier: Modifier = Modifier,
) {
    if (imageUrl.isNullOrBlank()) {
        EpsImagePlaceholder(
            state = state,
            modifier = modifier,
        )
    } else {
        EpsImage(
            state = EpsImageState(url = imageUrl, contentDescription = state.label, placeholder = state),
            modifier = modifier,
        )
    }
}

@Composable
fun EpsProductCard(
    state: EpsProductState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = state.cardState,
        modifier = modifier,
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            EpsProductImage(state = state.imageState, imageUrl = state.imageUrl, modifier = Modifier.fillMaxWidth().height(96.dp))
            EpsText(EpsTextState(state.name, fontWeight = FontWeight.SemiBold))
            EpsText(EpsTextState(state.priceText, style = EpsTextStyle.Price))
        }
    }
}

@Composable
fun EpsProductListItem(
    state: EpsProductState,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = state.cardState,
        modifier = modifier,
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            EpsProductImage(state = state.imageState, imageUrl = state.imageUrl, modifier = Modifier.size(82.dp))
            Spacer(modifier = Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                EpsText(EpsTextState(state.name, fontWeight = FontWeight.SemiBold))
                EpsSecondaryText(state.description)
                EpsText(EpsTextState("${state.ratingText}  ${state.priceText}", color = Color(0xFF0F7A3B)))
            }
        }
    }
}

data class EpsProductDetailsState(
    val name: String,
    val ratingText: String,
    val priceText: String,
    val description: String,
    val stockText: String,
    val imageUrl: String? = null,
    val imageState: EpsImagePlaceholderState,
    val quantityStepperState: EpsQuantityStepperState,
)

@Composable
fun EpsProductDetails(
    state: EpsProductDetailsState,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier,
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        EpsProductImage(state = state.imageState, imageUrl = state.imageUrl, modifier = Modifier.fillMaxWidth().height(220.dp))
        EpsScreenTitle(state.name)
        EpsSecondaryText(state.ratingText)
        EpsText(EpsTextState(state.priceText, style = EpsTextStyle.Price))
        EpsText(EpsTextState(state.description))
        EpsSecondaryText(state.stockText)
        Row(verticalAlignment = Alignment.CenterVertically) {
            EpsText(
                state = EpsTextState("Количество:"),
                modifier = Modifier.weight(1f),
            )
            EpsQuantityStepper(state.quantityStepperState)
        }
    }
}
