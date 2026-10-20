package com.shopkmp.uikit.component.fullscreenerror

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

@Composable
fun EpsFullscreenError(state: EpsFullscreenErrorState) {
    Box(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        contentAlignment = Alignment.Center
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(state.title, fontSize = 20.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(12.dp))
            Text(state.subtitle, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
            Spacer(Modifier.height(24.dp))

            when (state.errorType) {
                FullScreenErrorType.OneButtonError -> {
                    Button(onClick = { state.buttonAction?.invoke() }) {
                        Text(state.buttonTitle)
                    }
                }
                FullScreenErrorType.TwoButtonsError -> {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        OutlinedButton(onClick = { state.firstButtonAction?.invoke() }) {
                            Text(state.firstButtonTitle)
                        }
                        Button(onClick = { state.secondButtonAction?.invoke() }) {
                            Text(state.secondButtonTitle)
                        }
                    }
                }
                FullScreenErrorType.NoInternetError -> {
                    Button(
                        onClick = { state.middleButtonAction?.invoke() },
                        enabled = state.reconnectButtonEnabled
                    ) {
                        Text(state.reconnectButtonTitle)
                    }
                    Spacer(Modifier.height(8.dp))
                    TextButton(onClick = { state.linkTextAction?.invoke() }) {
                        Text(state.bottomText)
                    }
                }
            }
        }
    }
}
