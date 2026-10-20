package com.shopkmp.uikit.component.card

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun EpsCard(
    state: EpsCardState = EpsCardState(),
    modifier: Modifier = Modifier,
    content: @Composable ColumnScope.() -> Unit,
) {
    val cardModifier = if (state.onClick != null) {
        modifier.clickable(onClick = state.onClick)
    } else {
        modifier
    }

    Card(
        modifier = cardModifier,
        colors = CardDefaults.cardColors(containerColor = state.containerColor),
        shape = RoundedCornerShape(8.dp),
    ) {
        Column(modifier = Modifier.padding(state.contentPadding), content = content)
    }
}
