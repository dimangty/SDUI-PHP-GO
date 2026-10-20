package com.shopkmp.uikit.component.textitem

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

@Composable
fun EpsTextItem(state: EpsTextItemState) {
    Text(text = state.text, color = Color(state.color))
}
