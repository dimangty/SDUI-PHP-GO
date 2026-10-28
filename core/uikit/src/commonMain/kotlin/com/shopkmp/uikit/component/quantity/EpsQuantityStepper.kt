package com.shopkmp.uikit.component.quantity

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsQuantityStepper(state: EpsQuantityStepperState) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        Surface(
            onClick = state.onDecrease,
            enabled = state.decreaseEnabled,
            modifier = Modifier.size(48.dp).padding(6.dp).semantics { contentDescription = "Уменьшить количество" },
            shape = CircleShape,
            color = ShopTokens.Field,
            contentColor = if (state.decreaseEnabled) ShopTokens.Text else ShopTokens.SecondaryText,
            border = BorderStroke(1.dp, ShopTokens.Border),
        ) {
            Box(contentAlignment = Alignment.Center) { Text("−") }
        }
        Text(
            text = state.value.toString(),
            color = ShopTokens.Text,
            modifier = Modifier.padding(horizontal = 8.dp),
        )
        Surface(
            onClick = state.onIncrease,
            enabled = state.increaseEnabled,
            modifier = Modifier.size(48.dp).padding(6.dp).semantics { contentDescription = "Увеличить количество" },
            shape = CircleShape,
            color = ShopTokens.Field,
            contentColor = if (state.increaseEnabled) ShopTokens.Text else ShopTokens.SecondaryText,
            border = BorderStroke(1.dp, ShopTokens.Border),
        ) {
            Box(contentAlignment = Alignment.Center) { Text("+") }
        }
    }
}
