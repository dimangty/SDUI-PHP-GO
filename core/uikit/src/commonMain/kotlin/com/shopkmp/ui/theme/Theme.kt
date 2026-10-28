package com.shopkmp.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val LightColorScheme = lightColorScheme(
    primary = ShopTokens.Primary,
    onPrimary = ShopTokens.OnPrimary,
    primaryContainer = ShopTokens.PrimaryContainer,
    onPrimaryContainer = ShopTokens.Primary,
    inversePrimary = ShopTokens.PrimaryContainer,
    secondary = ShopTokens.Primary,
    onSecondary = ShopTokens.OnPrimary,
    secondaryContainer = ShopTokens.PrimaryContainer,
    onSecondaryContainer = ShopTokens.Primary,
    tertiary = ShopTokens.SecondaryText,
    onTertiary = ShopTokens.OnPrimary,
    tertiaryContainer = ShopTokens.Field,
    onTertiaryContainer = ShopTokens.Text,
    background = ShopTokens.Background,
    onBackground = ShopTokens.Text,
    surface = ShopTokens.Background,
    onSurface = ShopTokens.Text,
    surfaceVariant = ShopTokens.Field,
    onSurfaceVariant = ShopTokens.SecondaryText,
    surfaceTint = Color.Transparent,
    surfaceBright = ShopTokens.Background,
    surfaceDim = ShopTokens.Field,
    surfaceContainerLowest = ShopTokens.Background,
    surfaceContainerLow = ShopTokens.Background,
    surfaceContainer = ShopTokens.Field,
    surfaceContainerHigh = ShopTokens.Field,
    surfaceContainerHighest = ShopTokens.Field,
    inverseSurface = ShopTokens.Text,
    inverseOnSurface = ShopTokens.Background,
    outline = ShopTokens.Border,
    outlineVariant = ShopTokens.Border,
    error = ShopTokens.Danger,
    onError = ShopTokens.OnPrimary,
    errorContainer = Color(0xFFFEE2E2),
    onErrorContainer = ShopTokens.Danger,
    scrim = ShopTokens.Text,
)

@Composable
fun AppTheme(
    content: @Composable () -> Unit
) {
    MaterialTheme(
        colorScheme = LightColorScheme,
        shapes = AppShapes.material,
        content = content
    )
}
