package com.shopkmp.uikit.component.button.defaultbutton

import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun EpsButton(
    state: EpsButtonState,
    modifier: Modifier = Modifier,
) {
    when (state.type) {
        EpsButtonType.Primary -> {
            Button(
                onClick = { state.onClick?.invoke() },
                enabled = state.enabled,
                modifier = modifier,
            ) {
                Text(state.text)
            }
        }
        EpsButtonType.Secondary -> {
            OutlinedButton(
                onClick = { state.onClick?.invoke() },
                enabled = state.enabled,
                modifier = modifier,
            ) {
                Text(state.text)
            }
        }
        EpsButtonType.Text -> {
            TextButton(
                onClick = { state.onClick?.invoke() },
                enabled = state.enabled,
                modifier = modifier,
            ) {
                Text(state.text)
            }
        }
    }
}
