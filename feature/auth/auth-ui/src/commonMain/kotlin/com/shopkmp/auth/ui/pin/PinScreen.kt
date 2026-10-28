package com.shopkmp.auth.ui.pin

import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import com.shopkmp.mvvm.BaseScreen
import com.shopkmp.uikit.component.auth.EpsPinContent
import com.shopkmp.uikit.component.auth.EpsPinContentState
import org.koin.compose.viewmodel.koinViewModel
import org.koin.core.parameter.parametersOf

@Composable
fun PinScreen(mode: PinScreenMode) {
    val viewModel = koinViewModel<PinViewModel> { parametersOf(mode) }
    val screenState by viewModel.stateFlow.collectAsState()
    val state = screenState.state

    BaseScreen(
        lceState = screenState.lceState,
        topBarState = screenState.topBarState,
        defaultEffectFlow = viewModel.defaultEffectFlow,
        onDefaultUiEvent = viewModel::onDefaultUiEvent,
        addTopBar = false,
    ) {
        PinContent(state)
    }
}

@Composable
internal fun PinContent(state: PinState) {
    EpsPinContent(state = state.toContentState())
}

private fun PinState.toContentState(): EpsPinContentState =
    EpsPinContentState(
        headerState = headerState,
        pinLength = pin.length,
        pinError = pinError,
        keypadState = keypadState,
        continueButtonState = continueButtonState,
        secondaryActionButtonState = secondaryActionButtonState,
    )

enum class PinScreenMode(
    val title: String,
    val subtitle: String,
) {
    Create(
        title = "Придумайте PIN-код",
        subtitle = "Для быстрого входа без пароля",
    ),
    Login(
        title = "Введите PIN-код",
        subtitle = "С возвращением!",
    ),
    Change(
        title = "Введите текущий PIN",
        subtitle = "После проверки задайте новый PIN-код",
    ),
}
