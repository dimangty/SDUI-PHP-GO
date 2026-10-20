package com.shopkmp.auth.ui.login

import androidx.lifecycle.viewModelScope
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.mvvm.BaseViewModel
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.auth.EpsAuthDividerState
import com.shopkmp.uikit.component.auth.EpsInlineActionState
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import shopkmp.core.uikit.generated.resources.Res
import shopkmp.core.uikit.generated.resources.login_failed
import kotlinx.coroutines.launch
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.KeyboardType

class LoginViewModel(
	private val authService: AuthService,
	dependencies: com.shopkmp.mvvm.BaseViewModelDependencies = com.shopkmp.mvvm.resolveBaseViewModelDependencies(),
) : BaseViewModel<LoginUiEvent, LoginState>(LoginState(), dependencies) {

    init {
        updateState { buildState(email = email, password = password, passwordVisible = passwordVisible, error = error) }
    }

    override fun initTopBarState(): suspend EpsTopBarState.() -> EpsTopBarState = {
        copy(title = "", showBackButton = false)
    }

    override fun processUiEvent(event: LoginUiEvent) {
        when (event) {
            is LoginUiEvent.OnEmailChanged -> updateState {
                buildState(email = event.email, password = password, passwordVisible = passwordVisible, error = null)
            }
            is LoginUiEvent.OnPasswordChanged -> updateState {
                buildState(email = email, password = event.password, passwordVisible = passwordVisible, error = null)
            }
            LoginUiEvent.OnPasswordVisibilityToggleClicked -> updateState {
                buildState(email = email, password = password, passwordVisible = !passwordVisible, error = error)
            }
            is LoginUiEvent.OnLoginClicked -> {
                viewModelScope.launch {
                    showLoading()
                    val result = authService.login(state.email, state.password)
                    result.onSuccess {
                        replaceRoot(
                            if (authService.shouldCreatePinAfterPasswordLogin()) {
                                NavigationAction.NavigateToPin
                            } else {
                                NavigationAction.NavigateToMain
                            },
                        )
                    }.onFailure {
                        updateState {
                            buildState(
                                email = email,
                                password = password,
                                passwordVisible = passwordVisible,
                                error = it.message,
                            )
                        }
                        showToast(it.message ?: getString(Res.string.login_failed), isErrorToast = true)
                    }
                    hideLoading()
                }
            }
            is LoginUiEvent.OnSignUpClicked -> {
                navigate(NavigationAction.NavigateToSignUp)
            }
            LoginUiEvent.OnPinLoginClicked -> {
                navigate(NavigationAction.NavigateToPinLogin)
            }
            LoginUiEvent.OnBiometricLoginClicked -> {
                viewModelScope.launch {
                    showLoading()
                    authService.loginByBiometric().onSuccess {
                        replaceRoot(NavigationAction.NavigateToMain)
                    }.onFailure {
                        showToast(it.message ?: "Не удалось войти по биометрии", isErrorToast = true)
                    }
                    hideLoading()
                }
            }
        }
    }

    private fun buildState(
        email: String,
        password: String,
        passwordVisible: Boolean,
        error: String?,
    ): LoginState {
        val isLoginEnabled = email.trim().matches(EMAIL_PATTERN) && password.length in 8..128
        val dividerState = EpsDividerState()
        val signUpButtonState = EpsButtonState(
            text = "Зарегистрироваться",
            type = EpsButtonType.Text,
            onClick = { onUiEvent(LoginUiEvent.OnSignUpClicked) },
        )

        return LoginState(
            email = email,
            password = password,
            passwordVisible = passwordVisible,
            error = error,
            isLoginEnabled = isLoginEnabled,
            emailTextFieldState = EpsTextFieldState(
                value = email,
                label = "Email",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                onValueChange = { onUiEvent(LoginUiEvent.OnEmailChanged(it)) },
            ),
            passwordTextFieldState = EpsPasswordTextFieldState(
                value = password,
                label = "Пароль",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                passwordVisible = passwordVisible,
                onValueChange = { onUiEvent(LoginUiEvent.OnPasswordChanged(it)) },
                onVisibilityToggle = { onUiEvent(LoginUiEvent.OnPasswordVisibilityToggleClicked) },
            ),
            loginButtonState = EpsButtonState(
                text = "Войти",
                enabled = isLoginEnabled,
                onClick = { onUiEvent(LoginUiEvent.OnLoginClicked) },
            ),
            forgotPasswordButtonState = EpsButtonState(
                text = "Забыли пароль?",
                type = EpsButtonType.Text,
            ),
            pinLoginButtonState = EpsButtonState(
                text = "Войти по PIN-коду",
                type = EpsButtonType.Text,
                onClick = { onUiEvent(LoginUiEvent.OnPinLoginClicked) },
            ),
            biometricLoginButtonState = if (
                authService.hasConfiguredBiometric() && authService.isBiometricAvailable()
            ) {
                EpsButtonState(
                    text = "Войти по биометрии",
                    type = EpsButtonType.Text,
                    onClick = { onUiEvent(LoginUiEvent.OnBiometricLoginClicked) },
                )
            } else {
                null
            },
            dividerState = dividerState,
            authDividerState = EpsAuthDividerState(
                label = "или",
                dividerState = dividerState,
            ),
            signUpButtonState = signUpButtonState,
            signUpInlineActionState = EpsInlineActionState(
                label = "Нет аккаунта?",
                buttonState = signUpButtonState,
            ),
        )
    }

    private companion object {
        val EMAIL_PATTERN = Regex("^[A-Za-z0-9+_.-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$")
    }
}
