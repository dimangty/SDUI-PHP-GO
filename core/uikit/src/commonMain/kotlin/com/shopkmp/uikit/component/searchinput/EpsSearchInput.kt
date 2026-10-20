package com.shopkmp.uikit.component.searchinput

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun EpsSearchInput(state: EpsSearchInputState) {
    OutlinedTextField(
        value = state.value,
        onValueChange = { state.onValueChange?.invoke(it) },
        placeholder = { Text(state.placeholder) },
        modifier = Modifier.fillMaxWidth(),
    )
}
