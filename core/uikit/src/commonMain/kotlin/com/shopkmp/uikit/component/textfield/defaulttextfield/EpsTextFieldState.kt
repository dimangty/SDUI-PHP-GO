package com.shopkmp.uikit.component.textfield.defaulttextfield

import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.KeyboardActions
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
    val readOnly: Boolean = false,
    val keyboardActions: KeyboardActions = KeyboardActions.Default,
    val onValueChange: ((String) -> Unit)? = null,
) {
    constructor(
        value: String,
        placeholder: String,
        label: String,
        error: String?,
        enabled: Boolean,
        singleLine: Boolean,
        keyboardOptions: KeyboardOptions,
        visualTransformation: VisualTransformation,
        onValueChange: ((String) -> Unit)?,
    ) : this(value, placeholder, label, error, enabled, singleLine, keyboardOptions, visualTransformation,
        false, KeyboardActions.Default, onValueChange)
}
