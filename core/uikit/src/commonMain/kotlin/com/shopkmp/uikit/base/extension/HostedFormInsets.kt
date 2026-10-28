package com.shopkmp.uikit.base.extension

import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

/**
 * Keyboard avoidance for forms whose host already reserves navigation-bar space.
 *
 * BaseScreen's bottom spacer is a sibling, so its inset consumption does not reach
 * the form. Report that reserved space before padding for the remaining IME.
 * On iOS the host's navigation safe area can already include the keyboard; on
 * Android this still adds the IME portion above the reserved navigation bar.
 * Use windowInsetsPadding here: Compose 1.10.3's Skiko imePadding still uses the
 * legacy modifier-local consumption path, unlike public consumeWindowInsets.
 */
@Composable
fun Modifier.hostedFormImePadding(): Modifier =
    consumeWindowInsets(WindowInsets.navigationBars).windowInsetsPadding(WindowInsets.ime)
