package com.shopkmp.uikit.component.textfield.defaulttextfield

import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.VisualTransformation

data class EpsTextFieldState(
    val value: String = "",
    val placeholder: String = "",
    val label: String = "",
    val error: String? = null,
    val enabled: Boolean = true,
    val singleLine: Boolean = true,
    val keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    val visualTransformation: VisualTransformation = VisualTransformation.None,
    val onValueChange: ((String) -> Unit)? = null,
)
