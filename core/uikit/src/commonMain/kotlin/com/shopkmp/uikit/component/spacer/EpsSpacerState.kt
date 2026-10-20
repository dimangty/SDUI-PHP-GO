package com.shopkmp.uikit.component.spacer

import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

data class EpsSpacerState(
    val type: EpsSpacerType = EpsSpacerType.Small,
    val height: Dp? = null,
    val width: Dp? = null,
) {
    companion object {
        fun height(value: Dp) = EpsSpacerState(height = value)
        fun width(value: Dp) = EpsSpacerState(width = value)
        fun small() = EpsSpacerState(EpsSpacerType.Small)
        fun medium() = EpsSpacerState(EpsSpacerType.Medium)
        fun large() = EpsSpacerState(EpsSpacerType.Large)
    }
}
