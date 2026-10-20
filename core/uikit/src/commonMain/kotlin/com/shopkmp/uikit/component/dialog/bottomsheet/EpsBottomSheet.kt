package com.shopkmp.uikit.component.dialog.bottomsheet

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EpsBottomSheet(
    state: EpsBottomSheetState,
    isIOS: Boolean = false,
    onDismissed: () -> Unit,
) {
    when (state) {
        is EpsBottomSheetState.ApiErrorBottomSheet -> {
            ModalBottomSheet(onDismissRequest = onDismissed) {
                Column(modifier = Modifier.padding(24.dp)) {
                    Text(state.title, style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(8.dp))
                    Text(state.subtitle, style = MaterialTheme.typography.bodyMedium)
                    Spacer(Modifier.height(16.dp))
                    Button(onClick = onDismissed) {
                        Text(state.bottomButtonTitle)
                    }
                }
            }
        }
        is EpsBottomSheetState.GenericBottomSheet -> {
            ModalBottomSheet(onDismissRequest = onDismissed) {
                Column(modifier = Modifier.padding(24.dp)) {
                    Text(state.title, style = MaterialTheme.typography.titleMedium)
                    state.items.forEach { item ->
                        Text(item, modifier = Modifier.padding(vertical = 4.dp))
                    }
                }
            }
        }
    }
}
