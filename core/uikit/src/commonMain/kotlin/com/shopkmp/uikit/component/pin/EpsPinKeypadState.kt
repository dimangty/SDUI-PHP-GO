package com.shopkmp.uikit.component.pin

import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState

data class EpsPinKeypadState(
    val digitButtonStates: Map<Int, EpsButtonState> = (0..9).associateWith { EpsButtonState(text = it.toString()) },
    val backspaceButtonState: EpsButtonState = EpsButtonState(text = "⌫"),
    val showLockAction: Boolean = false,
)
