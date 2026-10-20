package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import com.shopkmp.uikit.component.card.EpsCard
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState

@Composable
fun EpsSuccessMessage(
    text: String,
    modifier: Modifier = Modifier,
) {
    EpsCard(
        state = EpsCardState(containerColor = Color(0xFFE9F7EF)),
        modifier = modifier.fillMaxWidth(),
    ) {
        EpsText(
            EpsTextState(
                text = text,
                color = Color(0xFF0F7A3B),
                fontWeight = FontWeight.SemiBold,
            ),
        )
    }
}
