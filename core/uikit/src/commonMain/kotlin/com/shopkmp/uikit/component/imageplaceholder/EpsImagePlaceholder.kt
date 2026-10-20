package com.shopkmp.uikit.component.imageplaceholder

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.unit.dp

@Composable
fun EpsImagePlaceholder(
    state: EpsImagePlaceholderState,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.clip(RoundedCornerShape(8.dp)),
        color = state.color,
    ) {
        Box(contentAlignment = Alignment.Center) {
            Text(state.label, style = MaterialTheme.typography.headlineMedium)
        }
    }
}
