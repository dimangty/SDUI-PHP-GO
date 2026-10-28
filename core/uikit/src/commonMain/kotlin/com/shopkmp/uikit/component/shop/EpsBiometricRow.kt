package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.toggleable
import androidx.compose.material3.Switch
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState

/** Controlled native state: a tap requests an action; only the caller changes checked. */
@Composable
fun EpsBiometricRow(
    label: String,
    checked: Boolean,
    enabled: Boolean,
    onToggle: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth().heightIn(min = 48.dp)
            .toggleable(value = checked, enabled = enabled, role = Role.Switch, onValueChange = { onToggle() })
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        EpsText(EpsTextState(label), Modifier.weight(1f))
        Switch(checked = checked, enabled = enabled, onCheckedChange = null)
    }
}
