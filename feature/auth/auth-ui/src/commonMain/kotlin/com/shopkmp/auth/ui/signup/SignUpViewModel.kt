package com.shopkmp.auth.ui.signup

import androidx.lifecycle.viewModelScope
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.auth.domain.SignUpData
import com.shopkmp.mvvm.BaseViewModel
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.checkbox.EpsCheckboxState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import shopkmp.core.uikit.generated.resources.Res
import shopkmp.core.uikit.generated.resources.sign_up_failed
import kotlinx.coroutines.launch
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.KeyboardType

class SignUpViewModel(
	private val authService: AuthService,
	dependencies: com.shopkmp.mvvm.BaseViewModelDependencies = com.shopkmp.mvvm.resolveBaseViewModelDependencies(),
) : BaseViewModel<SignUpUiEvent, SignUpState>(SignUpState(), dependencies) {

    init {
        updateState { buildState(this) }
    }

    override fun initTopBarState(): suspend EpsTopBarState.() -> EpsTopBarState = {
        copy(title = "Регистрация", showBackButton = true)
    }

    override fun processUiEvent(event: SignUpUiEvent) {
        when (event) {
            is SignUpUiEvent.OnFullNameChanged -> updateState { buildState(copy(fullName = event.value, errorMessage = null)) }
            is SignUpUiEvent.OnEmailChanged -> updateState { buildState(copy(email = event.value, errorMessage = null)) }
            is SignUpUiEvent.OnPhoneChanged -> updateState { buildState(copy(phone = event.value, errorMessage = null)) }
            is SignUpUiEvent.OnPasswordChanged -> updateState { buildState(copy(password = event.value, errorMessage = null)) }
            is SignUpUiEvent.OnRepeatedPasswordChanged -> updateState { buildState(copy(repeatedPassword = event.value, errorMessage = null)) }
            SignUpUiEvent.OnPasswordVisibilityToggleClicked -> updateState {
                buildState(copy(passwordVisible = !passwordVisible))
            }
            SignUpUiEvent.OnRepeatedPasswordVisibilityToggleClicked -> updateState {
                buildState(copy(repeatedPasswordVisible = !repeatedPasswordVisible))
            }
            is SignUpUiEvent.OnTermsChanged -> updateState { buildState(copy(termsAccepted = event.value, errorMessage = null)) }
            is SignUpUiEvent.OnSignUpClicked -> {
                viewModelScope.launch {
                    showLoading()
                    val data = SignUpData(
                        fullName = state.fullName,
                        email = state.email,
                        phone = state.phone,
                        password = state.password,
                    )
                    val result = authService.signUp(data)
                    result.onSuccess {
                        replaceRoot(NavigationAction.NavigateToPin)
                    }.onFailure {
                        updateState { buildState(copy(errorMessage = it.message)) }
                        showToast(it.message ?: getString(Res.string.sign_up_failed), isErrorToast = true)
                    }
                    hideLoading()
                }
            }
        }
    }

    private fun buildState(source: SignUpState): SignUpState {
        val passwordError = if (source.password != source.repeatedPassword) {
            "Пароли не совпадают"
        } else {
            null
        }

        return source.copy(
            fullNameTextFieldState = EpsTextFieldState(
                value = source.fullName,
                label = "Имя и фамилия",
                onValueChange = { onUiEvent(SignUpUiEvent.OnFullNameChanged(it)) },
            ),
            emailTextFieldState = EpsTextFieldState(
                value = source.email,
                label = "Email",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                onValueChange = { onUiEvent(SignUpUiEvent.OnEmailChanged(it)) },
            ),
            phoneTextFieldState = EpsTextFieldState(
                value = source.phone,
                label = "Телефон",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                onValueChange = { onUiEvent(SignUpUiEvent.OnPhoneChanged(it)) },
            ),
            passwordTextFieldState = EpsPasswordTextFieldState(
                value = source.password,
                label = "Пароль",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                passwordVisible = source.passwordVisible,
                onValueChange = { onUiEvent(SignUpUiEvent.OnPasswordChanged(it)) },
                onVisibilityToggle = { onUiEvent(SignUpUiEvent.OnPasswordVisibilityToggleClicked) },
            ),
            repeatedPasswordTextFieldState = EpsPasswordTextFieldState(
                value = source.repeatedPassword,
                label = "Повторите пароль",
                error = passwordError,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                passwordVisible = source.repeatedPasswordVisible,
                onValueChange = { onUiEvent(SignUpUiEvent.OnRepeatedPasswordChanged(it)) },
                onVisibilityToggle = { onUiEvent(SignUpUiEvent.OnRepeatedPasswordVisibilityToggleClicked) },
            ),
            termsCheckboxState = EpsCheckboxState(
                label = "Принимаю условия сервиса",
                checked = source.termsAccepted,
                onCheckedChange = { onUiEvent(SignUpUiEvent.OnTermsChanged(it)) },
            ),
            createAccountButtonState = EpsButtonState(
                text = "Создать аккаунт",
                enabled = source.isValid,
                onClick = { onUiEvent(SignUpUiEvent.OnSignUpClicked) },
            ),
        )
    }
}
