package com.shopkmp.auth.ui.login

import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import com.shopkmp.mvvm.BaseScreen
import com.shopkmp.uikit.component.auth.EpsLoginContent
import com.shopkmp.uikit.component.auth.EpsLoginContentState
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun LoginScreen() {
    val viewModel = koinViewModel<LoginViewModel>()
    val screenState by viewModel.stateFlow.collectAsState()
    val state = screenState.state

    BaseScreen(
        lceState = screenState.lceState,
        topBarState = screenState.topBarState,
        defaultEffectFlow = viewModel.defaultEffectFlow,
        onDefaultUiEvent = viewModel::onDefaultUiEvent,
        addTopBar = false,
    ) {
        LoginContent(state)
    }
}

@Composable
internal fun LoginContent(state: LoginState) {
    EpsLoginContent(state.toContentState())
}

private fun LoginState.toContentState(): EpsLoginContentState =
    EpsLoginContentState(
        headerState = headerState,
        emailTextFieldState = emailTextFieldState,
        passwordTextFieldState = passwordTextFieldState,
        error = error,
        loginButtonState = loginButtonState,
        forgotPasswordButtonState = forgotPasswordButtonState,
        pinLoginButtonState = pinLoginButtonState,
        biometricLoginButtonState = biometricLoginButtonState,
        authDividerState = authDividerState,
        signUpInlineActionState = signUpInlineActionState,
    )
