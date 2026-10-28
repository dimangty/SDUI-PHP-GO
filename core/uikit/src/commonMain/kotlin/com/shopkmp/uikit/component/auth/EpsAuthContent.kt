package com.shopkmp.uikit.component.auth

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.base.extension.hostedFormImePadding
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.checkbox.EpsCheckbox
import com.shopkmp.uikit.component.checkbox.EpsCheckboxState
import com.shopkmp.uikit.component.divider.EpsDivider
import com.shopkmp.uikit.component.pin.EpsPinDots
import com.shopkmp.uikit.component.pin.EpsPinKeypad
import com.shopkmp.uikit.component.pin.EpsPinKeypadState
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextField
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState

data class EpsLoginContentState(
    val headerState: EpsAuthHeaderState,
    val emailTextFieldState: EpsTextFieldState,
    val passwordTextFieldState: EpsPasswordTextFieldState,
    val error: String?,
    val loginButtonState: EpsButtonState,
    val forgotPasswordButtonState: EpsButtonState,
    val pinLoginButtonState: EpsButtonState,
    val biometricLoginButtonState: EpsButtonState?,
    val authDividerState: EpsAuthDividerState,
    val signUpInlineActionState: EpsInlineActionState,
)

@Composable
fun EpsLoginContent(state: EpsLoginContentState) {
    BoxWithConstraints(Modifier.fillMaxSize().hostedFormImePadding()) {
        Column(
            modifier = Modifier.fillMaxWidth().verticalScroll(rememberScrollState())
                .heightIn(min = maxHeight).padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            EpsAuthHeader(state.headerState)
            EpsSpacer(EpsSpacerState.height(32.dp))
            EpsTextField(state.emailTextFieldState, Modifier.testTag("login.email"))
            EpsSpacer(EpsSpacerState.height(12.dp))
            EpsPasswordTextField(state.passwordTextFieldState, Modifier.testTag("login.password"))
            EpsStatusError(state.error)
            EpsSpacer(EpsSpacerState.height(20.dp))
            EpsButton(
                state = state.loginButtonState,
                modifier = Modifier.fillMaxWidth().testTag("login.submit"),
            )
            EpsSpacer(EpsSpacerState.height(8.dp))
            EpsButton(state.forgotPasswordButtonState)
            EpsSpacer(EpsSpacerState.height(18.dp))
            EpsDivider(state.authDividerState.dividerState)
            EpsSpacer(EpsSpacerState.height(18.dp))
            EpsInlineAction(state.signUpInlineActionState, buttonModifier = Modifier.testTag("login.signup"))
            EpsSpacer(EpsSpacerState.height(8.dp))
            EpsButton(state.pinLoginButtonState, Modifier.testTag("login.pin"))
            state.biometricLoginButtonState?.let { EpsButton(it) }
        }
    }
}

data class EpsSignUpContentState(
    val fullNameTextFieldState: EpsTextFieldState,
    val emailTextFieldState: EpsTextFieldState,
    val phoneTextFieldState: EpsTextFieldState,
    val passwordTextFieldState: EpsPasswordTextFieldState,
    val repeatedPasswordTextFieldState: EpsPasswordTextFieldState,
    val termsCheckboxState: EpsCheckboxState,
    val errorMessage: String?,
    val createAccountButtonState: EpsButtonState,
)

@Composable
fun EpsSignUpContent(state: EpsSignUpContentState) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .hostedFormImePadding()
            .testTag("signup.scroll")
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        EpsTextField(state.fullNameTextFieldState, Modifier.testTag("signup.name"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsTextField(state.emailTextFieldState, Modifier.testTag("signup.email"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsTextField(state.phoneTextFieldState, Modifier.testTag("signup.phone"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsPasswordTextField(state.passwordTextFieldState, Modifier.testTag("signup.password"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsPasswordTextField(state.repeatedPasswordTextFieldState, Modifier.testTag("signup.passwordRepeat"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsCheckbox(state.termsCheckboxState, Modifier.testTag("signup.terms"))
        EpsStatusError(state.errorMessage)
        EpsSpacer(EpsSpacerState.height(16.dp))
        EpsButton(
            state = state.createAccountButtonState,
            modifier = Modifier.fillMaxWidth().testTag("signup.submit"),
        )
    }
}

data class EpsPinContentState(
    val headerState: EpsAuthHeaderState,
    val pinLength: Int,
    val pinError: String?,
    val keypadState: EpsPinKeypadState,
    val continueButtonState: EpsButtonState,
    val secondaryActionButtonState: EpsButtonState,
)

@Composable
fun EpsPinContent(state: EpsPinContentState) {
    BoxWithConstraints(Modifier.fillMaxSize()) {
        Column(
            modifier = Modifier.fillMaxWidth().verticalScroll(rememberScrollState())
                .heightIn(min = maxHeight).padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            EpsAuthHeader(state.headerState, Modifier.testTag("pin.header"))
            EpsSpacer(EpsSpacerState.height(28.dp))
            EpsPinDots(count = state.pinLength)
            EpsStatusError(state.pinError)
            EpsSpacer(EpsSpacerState.height(20.dp))
            EpsPinKeypad(state.keypadState)
            EpsSpacer(EpsSpacerState.height(20.dp))
            if (state.continueButtonState.enabled) {
                EpsButton(
                    state = state.continueButtonState,
                    modifier = Modifier.fillMaxWidth().testTag("pin.submit"),
                )
            }
            EpsSpacer(EpsSpacerState.height(8.dp))
            EpsButton(state.secondaryActionButtonState, Modifier.testTag("pin.secondary"))
        }
    }
}
