package com.shopkmp.uikit.component.dialog.toast

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

@Composable
fun EpsToast(
    state: ToastState?,
    onCloseClicked: () -> Unit,
) {
    if (state == null) return
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .padding(16.dp)
            .background(
                if (state.type == ToastType.Error) Color(0xFFB00020)
                else Color(0xFF4CAF50)
            )
            .padding(12.dp)
    ) {
        Row {
            Text(state.message, modifier = Modifier.weight(1f), color = Color.White)
            TextButton(onClick = onCloseClicked) {
                Text("X", color = Color.White)
            }
        }
    }
}
