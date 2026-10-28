package com.shopkmp.uikit.component.button.defaultbutton

import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.heightIn
import androidx.compose.ui.unit.dp
import com.shopkmp.ui.theme.AppShapes
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsButton(
    state: EpsButtonState,
    modifier: Modifier = Modifier,
) {
    val accent = if (state.destructive) ShopTokens.Danger else ShopTokens.Primary
    val buttonModifier = modifier.heightIn(min = 48.dp)
    when (state.type) {
        EpsButtonType.Primary -> {
            Button(
                onClick = { state.onClick?.invoke() },
                enabled = state.enabled,
                modifier = buttonModifier,
                shape = AppShapes.primary,
                colors = ButtonDefaults.buttonColors(
                    containerColor = accent,
                    contentColor = ShopTokens.OnPrimary,
                    disabledContainerColor = ShopTokens.Border,
                    disabledContentColor = ShopTokens.SecondaryText,
                ),
            ) {
                Text(state.text)
            }
        }
        EpsButtonType.Secondary -> {
            OutlinedButton(
                onClick = { state.onClick?.invoke() },
                enabled = state.enabled,
                modifier = buttonModifier,
                shape = AppShapes.primary,
                border = BorderStroke(1.dp, if (state.enabled) accent else ShopTokens.Border),
                colors = ButtonDefaults.outlinedButtonColors(
                    contentColor = accent,
                    disabledContentColor = ShopTokens.SecondaryText,
                ),
            ) {
                Text(state.text)
            }
        }
        EpsButtonType.Text -> {
            TextButton(
                onClick = { state.onClick?.invoke() },
                enabled = state.enabled,
                modifier = modifier,
                shape = AppShapes.primary,
                colors = ButtonDefaults.textButtonColors(
                    contentColor = accent,
                    disabledContentColor = ShopTokens.SecondaryText,
                ),
            ) {
                Text(state.text)
            }
        }
    }
}
