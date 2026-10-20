package com.shopkmp.uikit.component.textfield.defaulttextfield

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun EpsTextField(
    state: EpsTextFieldState,
    modifier: Modifier = Modifier,
) {
    OutlinedTextField(
        value = state.value,
        onValueChange = { state.onValueChange?.invoke(it) },
        label = { Text(state.label) },
        placeholder = { Text(state.placeholder) },
        isError = state.error != null,
        supportingText = if (state.error != null) {
            { Text(state.error) }
        } else null,
        enabled = state.enabled,
        singleLine = state.singleLine,
        keyboardOptions = state.keyboardOptions,
        visualTransformation = state.visualTransformation,
        modifier = modifier.fillMaxWidth(),
    )
}
