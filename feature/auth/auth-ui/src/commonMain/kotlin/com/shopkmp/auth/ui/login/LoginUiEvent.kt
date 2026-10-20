package com.shopkmp.auth.ui.login

import com.shopkmp.mvvm.common.UiEvent
import com.shopkmp.mvvm.common.SingleClickUiEvent

sealed interface LoginUiEvent : UiEvent {
    data class OnEmailChanged(val email: String) : LoginUiEvent
    data class OnPasswordChanged(val password: String) : LoginUiEvent
    data object OnPasswordVisibilityToggleClicked : LoginUiEvent
    data object OnLoginClicked : LoginUiEvent, SingleClickUiEvent
    data object OnPinLoginClicked : LoginUiEvent, SingleClickUiEvent
    data object OnBiometricLoginClicked : LoginUiEvent, SingleClickUiEvent
    data object OnSignUpClicked : LoginUiEvent, SingleClickUiEvent
}
