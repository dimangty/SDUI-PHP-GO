package com.shopkmp.uikit.component.textfield.defaulttextfield

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.error
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.shopkmp.ui.theme.AppShapes
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsTextField(
    state: EpsTextFieldState,
    modifier: Modifier = Modifier,
    trailingIcon: (@Composable () -> Unit)? = null,
) {
    val interactionSource = remember { MutableInteractionSource() }
    val focused by interactionSource.collectIsFocusedAsState()
    val border = when {
        state.error != null -> ShopTokens.Danger
        focused -> ShopTokens.Primary
        else -> ShopTokens.Border
    }
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        if (state.label.isNotEmpty()) {
            Text(
                state.label,
                style = MaterialTheme.typography.bodySmall,
                color = ShopTokens.SecondaryText,
                // The editable node owns the accessible label and caller's test tag.
                modifier = Modifier.clearAndSetSemantics {},
            )
        }
        BasicTextField(
            value = state.value,
            onValueChange = { state.onValueChange?.invoke(it) },
            enabled = state.enabled,
            readOnly = state.readOnly,
            singleLine = state.singleLine,
            keyboardOptions = state.keyboardOptions,
            keyboardActions = state.keyboardActions,
            visualTransformation = state.visualTransformation,
            interactionSource = interactionSource,
            textStyle = MaterialTheme.typography.bodyLarge.copy(
                color = if (state.enabled) ShopTokens.Text else ShopTokens.SecondaryText,
            ),
            cursorBrush = SolidColor(ShopTokens.Primary),
            modifier = modifier.fillMaxWidth()
                .heightIn(min = 48.dp)
                .background(ShopTokens.Field, AppShapes.field)
                .border(1.dp, border, AppShapes.field)
                .semantics {
                    if (state.label.isNotEmpty()) contentDescription = state.label
                    state.error?.let { error(it) }
                },
            decorationBox = { input ->
                Row(
                    modifier = Modifier.padding(start = 12.dp, end = if (trailingIcon == null) 12.dp else 0.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(modifier = Modifier.weight(1f).padding(vertical = 12.dp)) {
                        if (state.value.isEmpty()) {
                            Text(state.placeholder, color = ShopTokens.SecondaryText,
                                style = MaterialTheme.typography.bodyLarge)
                        }
                        input()
                    }
                    if (trailingIcon != null) {
                        Box(
                            modifier = Modifier.defaultMinSize(minWidth = 48.dp, minHeight = 48.dp),
                            contentAlignment = Alignment.Center,
                        ) {
                            trailingIcon()
                        }
                    }
                }
            },
        )
        state.error?.let { Text(it, color = ShopTokens.Danger, style = MaterialTheme.typography.bodySmall) }
    }
}
