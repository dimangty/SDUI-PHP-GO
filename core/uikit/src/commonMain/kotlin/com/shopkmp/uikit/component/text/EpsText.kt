package com.shopkmp.uikit.component.text

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import com.shopkmp.ui.theme.ShopTokens

@Composable
fun EpsText(
    state: EpsTextState,
    modifier: Modifier = Modifier,
) {
    val style = when (state.style) {
        EpsTextStyle.Body -> MaterialTheme.typography.bodyMedium
        EpsTextStyle.Secondary -> MaterialTheme.typography.bodyMedium
        EpsTextStyle.Title -> MaterialTheme.typography.titleLarge
        EpsTextStyle.SectionTitle -> MaterialTheme.typography.titleLarge
        EpsTextStyle.ScreenTitle -> MaterialTheme.typography.headlineMedium
        EpsTextStyle.HeroTitle -> MaterialTheme.typography.headlineLarge
        EpsTextStyle.Price -> MaterialTheme.typography.headlineSmall
        EpsTextStyle.Error -> MaterialTheme.typography.bodyMedium
        EpsTextStyle.Avatar -> MaterialTheme.typography.headlineMedium
        EpsTextStyle.PinDot -> MaterialTheme.typography.headlineMedium
    }
    val color = state.color ?: when (state.style) {
        EpsTextStyle.Secondary -> ShopTokens.SecondaryText
        EpsTextStyle.Price -> ShopTokens.Primary
        EpsTextStyle.Error -> ShopTokens.Danger
        EpsTextStyle.Avatar -> Color.White
        else -> ShopTokens.Text
    }
    val fontWeight = state.fontWeight ?: when (state.style) {
        EpsTextStyle.HeroTitle,
        EpsTextStyle.ScreenTitle,
        EpsTextStyle.SectionTitle,
        EpsTextStyle.Title,
        EpsTextStyle.Price,
        -> FontWeight.Bold
        else -> null
    }

    Text(
        text = state.text,
        modifier = modifier,
        style = style,
        color = color,
        fontWeight = fontWeight,
    )
}
