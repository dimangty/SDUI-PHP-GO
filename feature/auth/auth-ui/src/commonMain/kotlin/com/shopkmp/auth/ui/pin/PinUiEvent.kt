package com.shopkmp.auth.ui.pin

import com.shopkmp.mvvm.common.UiEvent
import com.shopkmp.mvvm.common.SingleClickUiEvent

sealed interface PinUiEvent : UiEvent {
    data class OnPinChanged(val pin: String) : PinUiEvent
    data class OnDigitClicked(val digit: Int) : PinUiEvent
    data object OnBackspaceClicked : PinUiEvent
    data object OnVerifyClicked : PinUiEvent, SingleClickUiEvent
    data object OnSkipClicked : PinUiEvent, SingleClickUiEvent
    data object OnPasswordLoginClicked : PinUiEvent, SingleClickUiEvent
}
