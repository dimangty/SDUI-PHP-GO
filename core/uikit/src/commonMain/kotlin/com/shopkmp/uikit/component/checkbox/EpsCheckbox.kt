package com.shopkmp.uikit.component.checkbox

import androidx.compose.foundation.layout.Row
import androidx.compose.material3.Checkbox
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier

@Composable
fun EpsCheckbox(state: EpsCheckboxState, modifier: Modifier = Modifier) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Checkbox(
            modifier = modifier,
            checked = state.checked,
            onCheckedChange = { state.onCheckedChange?.invoke(it) }
        )
        Text(state.label)
    }
}
