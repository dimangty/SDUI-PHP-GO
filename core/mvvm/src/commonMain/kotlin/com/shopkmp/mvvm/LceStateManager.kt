package com.shopkmp.mvvm

import com.shopkmp.common.device.DeviceService
import com.shopkmp.common.resource.StringsService
import com.shopkmp.mvvm.common.LceState
import com.shopkmp.uikit.component.fullscreenerror.EpsFullscreenErrorState
import com.shopkmp.uikit.component.fullscreenerror.FullScreenErrorType
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlin.time.Duration.Companion.seconds
import shopkmp.core.uikit.generated.resources.Res
import shopkmp.core.uikit.generated.resources.button_back
import shopkmp.core.uikit.generated.resources.button_reconnect
import shopkmp.core.uikit.generated.resources.button_try_again
import shopkmp.core.uikit.generated.resources.error_contact_support
import shopkmp.core.uikit.generated.resources.error_reconnecting
import shopkmp.core.uikit.generated.resources.error_no_internet
import shopkmp.core.uikit.generated.resources.error_something_went_wrong
import shopkmp.core.uikit.generated.resources.error_try_again_later

private const val NO_INTERNET_ERROR_TIMEOUT = 15

class LceStateManager(
    private val deviceService: DeviceService,
    private val stringsService: StringsService,
) {

    private val _lceState = MutableStateFlow(LceState())
    val lceState = _lceState.asStateFlow()

    private var noInternetReconnectJob: Job? = null
    private var noInternetReconnectAttemptTime = NO_INTERNET_ERROR_TIMEOUT
    private var checkInternetAction: (suspend (CoroutineScope) -> Unit)? = null

    private var strErrorTitle = "Something went wrong"
    private var strErrorSubtitle = "Please try again later"
    private var strButtonTryAgain = "Try again"
    private var strButtonBack = "Back"
    private var strNoInternetTitle = "No internet connection"
    private var strReconnectButton = "Reconnect"
    private var strContactSupport = "Contact support"
    private var strReconnectingPattern = "Reconnecting in %s sec"

    private val scope = CoroutineScope(Dispatchers.Main)

    init {
        updateState {
            copy(osType = deviceService.osType)
        }
        scope.launch {
            strErrorTitle = stringsService.getString(Res.string.error_something_went_wrong)
            strErrorSubtitle = stringsService.getString(Res.string.error_try_again_later)
            strButtonTryAgain = stringsService.getString(Res.string.button_try_again)
            strButtonBack = stringsService.getString(Res.string.button_back)
            strNoInternetTitle = stringsService.getString(Res.string.error_no_internet)
            strReconnectButton = stringsService.getString(Res.string.button_reconnect)
            strContactSupport = stringsService.getString(Res.string.error_contact_support)
            strReconnectingPattern = stringsService.getString(Res.string.error_reconnecting)
        }
    }

    fun initCheckInternetAction(action: (suspend (CoroutineScope) -> Unit)?) {
        checkInternetAction = action
    }

    fun initializeIsRootScreen(isRootScreen: Boolean) {
        updateState { copy(isRootScreen = isRootScreen) }
    }

    fun setUiInitialized(isUiInitialized: Boolean) {
        updateState { copy(isUiInitialized = isUiInitialized) }
    }

    fun showLoading() {
        updateState { copy(isLoading = true) }
    }

    fun hideLoading() {
        updateState { copy(isLoading = false) }
    }

    fun isLoading(): Boolean = lceState.value.isLoading

    fun showError(errorState: EpsFullscreenErrorState) {
        updateState { copy(errorState = errorState) }
    }

    fun hideError() {
        noInternetReconnectJob?.cancel()
        updateState { copy(errorState = null) }
    }

    fun isNoInternetErrorShowing(): Boolean {
        val errorState = lceState.value.errorState
        return errorState != null && errorState.errorType == FullScreenErrorType.NoInternetError
    }

    fun showFullScreenError(
        repeatAction: () -> Unit,
        backAction: () -> Unit,
        errorTitle: String = "",
        errorMessage: String = "",
    ) {
        showError(
            if (lceState.value.isRootScreen) {
                EpsFullscreenErrorState.createOneButtonError(
                    title = errorTitle.ifEmpty { strErrorTitle },
                    subtitle = errorMessage.ifEmpty { strErrorSubtitle },
                    buttonTitle = strButtonTryAgain,
                    buttonAction = { repeatAction() },
                )
            } else {
                EpsFullscreenErrorState.createTwoButtonsError(
                    title = errorTitle.ifEmpty { strErrorTitle },
                    subtitle = errorMessage.ifEmpty { strErrorSubtitle },
                    firstButtonTitle = strButtonTryAgain,
                    secondButtonTitle = strButtonBack,
                    firstButtonAction = { repeatAction() },
                    secondButtonAction = { backAction() },
                )
            }
        )
    }

    fun showNoInternetError() {
        noInternetReconnectJob?.cancel()
        showError(
            EpsFullscreenErrorState.createNoInternet(
                title = strNoInternetTitle,
                subtitle = strReconnectingPattern.replace("%s", NO_INTERNET_ERROR_TIMEOUT.toString()),
                reconnectButtonTitle = strReconnectButton,
                reconnectButtonEnabled = true,
                bottomText = strContactSupport,
                bottomLink = "",
                middleButtonAction = { reconnectToInternet() },
                linkTextAction = {},
            )
        )
    }

    private fun reconnectToInternet() {
        noInternetReconnectJob?.cancel()
        checkInternetAction?.let { action ->
            noInternetReconnectJob = scope.launch {
                action(this)
            }
        }
    }

    private fun updateState(transform: LceState.() -> LceState) {
        _lceState.update { transform.invoke(lceState.value) }
    }
}
