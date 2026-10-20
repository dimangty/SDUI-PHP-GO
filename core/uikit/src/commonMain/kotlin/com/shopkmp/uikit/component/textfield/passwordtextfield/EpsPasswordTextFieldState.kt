package com.shopkmp.uikit.component.textfield.passwordtextfield

import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.KeyboardType

data class EpsPasswordTextFieldState(
    val value: String = "",
    val label: String = "",
    val placeholder: String = "",
    val error: String? = null,
    val enabled: Boolean = true,
    val passwordVisible: Boolean = false,
    val keyboardOptions: KeyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
    val onValueChange: ((String) -> Unit)? = null,
    val onVisibilityToggle: (() -> Unit)? = null,
)
