package com.shopkmp.uikit.component.quantity

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun EpsQuantityStepper(state: EpsQuantityStepperState) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        OutlinedButton(
            onClick = state.onDecrease,
            enabled = state.decreaseEnabled,
        ) {
            Text("-")
        }
        Text(
            text = state.value.toString(),
            modifier = Modifier.padding(horizontal = 10.dp),
        )
        OutlinedButton(
            onClick = state.onIncrease,
            enabled = state.increaseEnabled,
        ) {
            Text("+")
        }
    }
}
