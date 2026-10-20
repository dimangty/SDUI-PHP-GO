package com.shopkmp.auth.ui.login

import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.auth.EpsAuthDividerState
import com.shopkmp.uikit.component.auth.EpsAuthHeaderState
import com.shopkmp.uikit.component.auth.EpsInlineActionState
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState

data class LoginState(
    val email: String = "",
    val password: String = "",
    val passwordVisible: Boolean = false,
    val error: String? = null,
    val isLoginEnabled: Boolean = false,
    val headerState: EpsAuthHeaderState = EpsAuthHeaderState(
        title = "ShopKMP",
        subtitle = "Покупки в несколько касаний",
    ),
    val emailTextFieldState: EpsTextFieldState = EpsTextFieldState(
        value = "",
        label = "Email",
    ),
    val passwordTextFieldState: EpsPasswordTextFieldState = EpsPasswordTextFieldState(
        value = "",
        label = "Пароль",
    ),
    val loginButtonState: EpsButtonState = EpsButtonState(text = "Войти"),
    val forgotPasswordButtonState: EpsButtonState = EpsButtonState(
        text = "Забыли пароль?",
        type = EpsButtonType.Text,
    ),
    val pinLoginButtonState: EpsButtonState = EpsButtonState(
        text = "Войти по PIN-коду",
        type = EpsButtonType.Text,
    ),
    val biometricLoginButtonState: EpsButtonState? = null,
    val dividerState: EpsDividerState = EpsDividerState(),
    val authDividerState: EpsAuthDividerState = EpsAuthDividerState(
        label = "или",
        dividerState = dividerState,
    ),
    val signUpButtonState: EpsButtonState = EpsButtonState(
        text = "Зарегистрироваться",
        type = EpsButtonType.Text,
    ),
    val signUpInlineActionState: EpsInlineActionState = EpsInlineActionState(
        label = "Нет аккаунта?",
        buttonState = signUpButtonState,
    ),
)
