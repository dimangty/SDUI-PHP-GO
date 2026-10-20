package com.shopkmp.auth.ui.signup

import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.checkbox.EpsCheckboxState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState

data class SignUpState(
    val fullName: String = "",
    val email: String = "",
    val phone: String = "",
    val password: String = "",
    val repeatedPassword: String = "",
    val passwordVisible: Boolean = false,
    val repeatedPasswordVisible: Boolean = false,
    val termsAccepted: Boolean = false,
    val errorMessage: String? = null,
    val fullNameTextFieldState: EpsTextFieldState = EpsTextFieldState(
        value = "",
        label = "Имя и фамилия",
    ),
    val emailTextFieldState: EpsTextFieldState = EpsTextFieldState(
        value = "",
        label = "Email",
    ),
    val phoneTextFieldState: EpsTextFieldState = EpsTextFieldState(
        value = "",
        label = "Телефон",
    ),
    val passwordTextFieldState: EpsPasswordTextFieldState = EpsPasswordTextFieldState(
        value = "",
        label = "Пароль",
    ),
    val repeatedPasswordTextFieldState: EpsPasswordTextFieldState = EpsPasswordTextFieldState(
        value = "",
        label = "Повторите пароль",
    ),
    val termsCheckboxState: EpsCheckboxState = EpsCheckboxState(
        label = "Принимаю условия сервиса",
        checked = false,
    ),
    val createAccountButtonState: EpsButtonState = EpsButtonState(text = "Создать аккаунт"),
) {
    val isValid: Boolean
        get() = fullName.trim().length in 2..255 &&
            EMAIL_PATTERN.matches(email.trim()) &&
            E164_PATTERN.matches(phone.trim()) &&
            password.length in 8..128 &&
            password.any(Char::isLowerCase) &&
            password.any(Char::isUpperCase) &&
            password.any(Char::isDigit) &&
            password == repeatedPassword &&
            termsAccepted

    private companion object {
        val EMAIL_PATTERN = Regex("^[A-Za-z0-9+_.-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$")
        val E164_PATTERN = Regex("^\\+[1-9][0-9]{7,14}$")
    }
}
