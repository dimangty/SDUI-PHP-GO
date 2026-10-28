package com.shopkmp.uikit.component.textfield.passwordtextfield

import androidx.compose.material3.IconButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

@Composable
fun EpsPasswordTextField(
    state: EpsPasswordTextFieldState,
    modifier: Modifier = Modifier,
) {
    EpsTextField(
        state = EpsTextFieldState(
            value = state.value,
            label = state.label,
            placeholder = state.placeholder,
            error = state.error,
            enabled = state.enabled,
            keyboardOptions = state.keyboardOptions,
            visualTransformation = if (state.passwordVisible) VisualTransformation.None else PasswordVisualTransformation(),
            onValueChange = state.onValueChange,
        ),
        modifier = modifier,
        trailingIcon = {
            IconButton(
                onClick = { state.onVisibilityToggle?.invoke() },
                enabled = state.enabled && state.onVisibilityToggle != null,
            ) {
                EpsIcon(
                    if (state.passwordVisible) EpsIconName.VisibilityOff else EpsIconName.Visibility,
                    if (state.passwordVisible) "Скрыть пароль" else "Показать пароль",
                    tint = com.shopkmp.ui.theme.ShopTokens.SecondaryText,
                )
            }
        },
    )
}
