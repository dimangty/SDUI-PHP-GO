package com.shopkmp.auth.ui.signup

import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import com.shopkmp.mvvm.BaseScreen
import com.shopkmp.uikit.component.auth.EpsSignUpContent
import com.shopkmp.uikit.component.auth.EpsSignUpContentState
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun SignUpScreen() {
    val viewModel = koinViewModel<SignUpViewModel>()
    val screenState by viewModel.stateFlow.collectAsState()
    val state = screenState.state

    BaseScreen(
        lceState = screenState.lceState,
        topBarState = screenState.topBarState,
        defaultEffectFlow = viewModel.defaultEffectFlow,
        onDefaultUiEvent = viewModel::onDefaultUiEvent,
    ) {
        EpsSignUpContent(state.toContentState())
    }
}

private fun SignUpState.toContentState(): EpsSignUpContentState =
    EpsSignUpContentState(
        fullNameTextFieldState = fullNameTextFieldState,
        emailTextFieldState = emailTextFieldState,
        phoneTextFieldState = phoneTextFieldState,
        passwordTextFieldState = passwordTextFieldState,
        repeatedPasswordTextFieldState = repeatedPasswordTextFieldState,
        termsCheckboxState = termsCheckboxState,
        errorMessage = errorMessage,
        createAccountButtonState = createAccountButtonState,
    )
