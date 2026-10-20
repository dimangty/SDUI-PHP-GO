package com.shopkmp.auth.ui.signup

import com.shopkmp.mvvm.common.UiEvent
import com.shopkmp.mvvm.common.SingleClickUiEvent

sealed interface SignUpUiEvent : UiEvent {
    data class OnFullNameChanged(val value: String) : SignUpUiEvent
    data class OnEmailChanged(val value: String) : SignUpUiEvent
    data class OnPhoneChanged(val value: String) : SignUpUiEvent
    data class OnPasswordChanged(val value: String) : SignUpUiEvent
    data class OnRepeatedPasswordChanged(val value: String) : SignUpUiEvent
    data object OnPasswordVisibilityToggleClicked : SignUpUiEvent
    data object OnRepeatedPasswordVisibilityToggleClicked : SignUpUiEvent
    data class OnTermsChanged(val value: Boolean) : SignUpUiEvent
    data object OnSignUpClicked : SignUpUiEvent, SingleClickUiEvent
}
