package com.shopkmp.uikit.component.dialog.alert

import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable

@Composable
fun EpsAlertDialog(
    state: AlertDialogState,
    isIOS: Boolean = false,
    onDismissed: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = { onDismissed(); state.onDismiss?.invoke() },
        title = { Text(state.title) },
        text = { Text(state.message) },
        confirmButton = {
            TextButton(onClick = {
                onDismissed()
                state.onConfirm?.invoke()
            }) {
                Text(state.confirmText)
            }
        },
        dismissButton = if (state.dismissText != null) {
            {
                TextButton(onClick = {
                    onDismissed()
                    state.onDismiss?.invoke()
                }) {
                    Text(state.dismissText)
                }
            }
        } else null,
    )
}
