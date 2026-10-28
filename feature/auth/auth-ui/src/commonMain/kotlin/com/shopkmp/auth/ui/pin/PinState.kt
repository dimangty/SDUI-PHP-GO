package com.shopkmp.auth.ui.pin

import com.shopkmp.uikit.component.auth.EpsAuthHeaderState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.pin.EpsPinKeypadState

data class PinState(
    val pin: String = "",
    val currentPinForChange: String? = null,
    val pinError: String? = null,
    val isVerifyEnabled: Boolean = false,
    val headerState: EpsAuthHeaderState = EpsAuthHeaderState(
        title = "Придумайте PIN-код",
        subtitle = "Для быстрого входа без пароля",
    ),
    val digitButtonStates: Map<Int, EpsButtonState> = emptyMap(),
    val backspaceButtonState: EpsButtonState = EpsButtonState(text = "Удалить последнюю цифру"),
    val keypadState: EpsPinKeypadState = EpsPinKeypadState(
        digitButtonStates = digitButtonStates,
        backspaceButtonState = backspaceButtonState,
    ),
    val continueButtonState: EpsButtonState = EpsButtonState(text = "Продолжить", enabled = false),
    val skipButtonState: EpsButtonState = EpsButtonState(
        text = "Пропустить",
        type = EpsButtonType.Text,
    ),
    val passwordLoginButtonState: EpsButtonState = EpsButtonState(
        text = "Войти по паролю",
        type = EpsButtonType.Text,
    ),
    val secondaryActionButtonState: EpsButtonState = skipButtonState,
)
