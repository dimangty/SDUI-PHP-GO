package com.shopkmp.uikit.component.textfield.passwordtextfield

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation

@Composable
fun EpsPasswordTextField(
    state: EpsPasswordTextFieldState,
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
        } else {
            null
        },
        enabled = state.enabled,
        singleLine = true,
        keyboardOptions = state.keyboardOptions,
        visualTransformation = if (state.passwordVisible) {
            VisualTransformation.None
        } else {
            PasswordVisualTransformation()
        },
        trailingIcon = {
            TextButton(onClick = { state.onVisibilityToggle?.invoke() }) {
                Text(if (state.passwordVisible) "Скрыть" else "Показать")
            }
        },
        modifier = modifier.fillMaxWidth(),
    )
}
