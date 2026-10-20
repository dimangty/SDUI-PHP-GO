package com.shopkmp.uikit.component.error

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState

/** Consistent inline error presentation used by feature screens. */
@Composable
fun EpsErrorMessage(text: String, modifier: Modifier = Modifier) {
    EpsCard(
        state = EpsCardState(containerColor = Color(0xFFFFE9E9)),
        modifier = modifier.fillMaxWidth(),
    ) {
        EpsText(EpsTextState(text = text, color = Color(0xFF9B1C1C), fontWeight = FontWeight.SemiBold))
    }
}
