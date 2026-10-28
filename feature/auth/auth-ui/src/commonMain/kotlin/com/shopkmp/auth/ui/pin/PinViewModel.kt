package com.shopkmp.auth.ui.pin

import androidx.lifecycle.viewModelScope
import com.shopkmp.auth.domain.AuthError
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.mvvm.BaseViewModel
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.uikit.component.auth.EpsAuthHeaderState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.pin.EpsPinKeypadState
import com.shopkmp.uikit.component.text.EpsTextStyle
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import shopkmp.core.uikit.generated.resources.Res
import shopkmp.core.uikit.generated.resources.invalid_pin
import kotlinx.coroutines.launch

class PinViewModel(
	private val authService: AuthService,
	private val mode: PinScreenMode,
	dependencies: com.shopkmp.mvvm.BaseViewModelDependencies = com.shopkmp.mvvm.resolveBaseViewModelDependencies(),
) : BaseViewModel<PinUiEvent, PinState>(PinState(), dependencies) {

    init {
        updateState { buildState(pin = pin, currentPinForChange = currentPinForChange, pinError = pinError) }
    }

    override fun initTopBarState(): suspend EpsTopBarState.() -> EpsTopBarState = {
        copy(title = "PIN-код", showBackButton = true)
    }

    override fun processUiEvent(event: PinUiEvent) {
        when (event) {
            is PinUiEvent.OnPinChanged -> {
                val pin = event.pin.filter { it.isDigit() }.take(MAX_PIN_LENGTH)
                updateState { buildState(pin = pin, currentPinForChange = currentPinForChange, pinError = null) }
            }
            is PinUiEvent.OnDigitClicked -> {
                val pin = (state.pin + event.digit.toString()).take(MAX_PIN_LENGTH)
                updateState { buildState(pin = pin, currentPinForChange = currentPinForChange, pinError = null) }
            }
            PinUiEvent.OnBackspaceClicked -> {
                updateState { buildState(pin = pin.dropLast(1), currentPinForChange = currentPinForChange, pinError = null) }
            }
            is PinUiEvent.OnVerifyClicked -> {
                submitPin(state.pin)
            }
            PinUiEvent.OnSkipClicked -> {
                replaceRoot(NavigationAction.NavigateToMain)
            }
            PinUiEvent.OnPasswordLoginClicked -> replaceRoot(NavigationAction.NavigateToLogin)
        }
    }

    private fun buildState(
        pin: String,
        currentPinForChange: String?,
        pinError: String?,
    ): PinState {
        val isVerifyEnabled = pin.length in MIN_PIN_LENGTH..MAX_PIN_LENGTH
        val headerTitle = if (mode == PinScreenMode.Change && currentPinForChange != null) {
            "Введите новый PIN"
        } else {
            mode.title
        }
        val headerSubtitle = if (mode == PinScreenMode.Change && currentPinForChange != null) {
            "Новый PIN будет привязан к текущему устройству"
        } else {
            mode.subtitle
        }
        val digitButtonStates = (0..9).associateWith { digit ->
            EpsButtonState(
                text = digit.toString(),
                type = EpsButtonType.Secondary,
                enabled = pin.length < MAX_PIN_LENGTH,
                onClick = { onUiEvent(PinUiEvent.OnDigitClicked(digit)) },
            )
        }
        val backspaceButtonState = EpsButtonState(
            text = "Удалить последнюю цифру",
            type = EpsButtonType.Secondary,
            enabled = pin.isNotEmpty(),
            onClick = { onUiEvent(PinUiEvent.OnBackspaceClicked) },
        )
        val skipButtonState = EpsButtonState(
            text = "Пропустить",
            type = EpsButtonType.Text,
            onClick = { onUiEvent(PinUiEvent.OnSkipClicked) },
        )
        val passwordLoginButtonState = EpsButtonState(
            text = "Войти по паролю",
            type = EpsButtonType.Text,
            onClick = { onUiEvent(PinUiEvent.OnPasswordLoginClicked) },
        )

        return PinState(
            pin = pin,
            currentPinForChange = currentPinForChange,
            pinError = pinError,
            isVerifyEnabled = isVerifyEnabled,
            headerState = EpsAuthHeaderState(
                title = headerTitle,
                subtitle = headerSubtitle,
                titleStyle = EpsTextStyle.Title,
            ),
            digitButtonStates = digitButtonStates,
            backspaceButtonState = backspaceButtonState,
            keypadState = EpsPinKeypadState(
                digitButtonStates = digitButtonStates,
                backspaceButtonState = backspaceButtonState,
                showLockAction = mode == PinScreenMode.Login,
            ),
            continueButtonState = EpsButtonState(
                text = if (mode == PinScreenMode.Change && currentPinForChange != null) "Сменить PIN" else "Продолжить",
                enabled = isVerifyEnabled,
                onClick = { onUiEvent(PinUiEvent.OnVerifyClicked) },
            ),
            skipButtonState = skipButtonState,
            passwordLoginButtonState = passwordLoginButtonState,
            secondaryActionButtonState = when (mode) {
                PinScreenMode.Create -> skipButtonState
                PinScreenMode.Login -> passwordLoginButtonState
                PinScreenMode.Change -> skipButtonState.copy(text = "Отмена")
            },
        )
    }

    private fun submitPin(pin: String) {
        viewModelScope.launch {
            if (pin.length in MIN_PIN_LENGTH..MAX_PIN_LENGTH) {
                if (mode == PinScreenMode.Change && state.currentPinForChange == null) {
                    updateState {
                        buildState(
                            pin = "",
                            currentPinForChange = pin,
                            pinError = null,
                        )
                    }
                    return@launch
                }
                showLoading()
                val result = when (mode) {
                    PinScreenMode.Create -> authService.createPin(pin)
                    PinScreenMode.Change -> authService.changePin(state.currentPinForChange.orEmpty(), pin)
                    PinScreenMode.Login -> authService.loginByPin(pin)
                }
                result.onSuccess {
                    replaceRoot(NavigationAction.NavigateToMain)
                }.onFailure { error ->
                    if (error is AuthError.PinNotSet) {
                        replaceRoot(NavigationAction.NavigateToLogin)
                        return@launch
                    }
                    val errorMessage = when (error) {
                        is AuthError.InvalidPinWithAttempts -> "Неверный PIN. Осталось попыток: ${error.attemptsLeft}"
                        is AuthError.PinLocked -> "Вход заблокирован до ${error.lockedUntil.substringAfter('T').take(8)}"
                        else -> error.message ?: getString(Res.string.invalid_pin)
                    }
                    updateState {
                        buildState(
                            pin = "",
                            currentPinForChange = currentPinForChange,
                            pinError = errorMessage,
                        )
                    }
                    showToast(errorMessage, isErrorToast = true)
                }
                hideLoading()
            }
        }
    }

    private companion object {
        const val MIN_PIN_LENGTH = 4
        const val MAX_PIN_LENGTH = 6
    }
}
