package com.shopkmp.mvvm

import androidx.compose.runtime.Stable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.common.device.DeviceService
import com.shopkmp.common.error.RemoteErrorMessageMapper
import com.shopkmp.common.resource.StringsService
import com.shopkmp.mvvm.common.DefaultUiEvent
import com.shopkmp.mvvm.common.ScreenState
import com.shopkmp.mvvm.common.UiEvent
import com.shopkmp.mvvm.common.ViewModelEffect
import com.shopkmp.mvvm.common.connectivity.ConnectivityService
import com.shopkmp.mvvm.common.singleclick.SingleClickService
import com.shopkmp.mvvm.common.topbar.TopBarStateManager
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.navigation.navigationservice.AppNavigator
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.uikit.component.dialog.UiDialogEffect
import com.shopkmp.uikit.component.dialog.alert.AlertDialogState
import com.shopkmp.uikit.component.dialog.bottomsheet.EpsBottomSheetState
import com.shopkmp.uikit.component.dialog.toast.ToastState
import com.shopkmp.uikit.component.dialog.toast.ToastType
import com.shopkmp.uikit.component.fullscreenerror.EpsFullscreenErrorState
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import org.jetbrains.compose.resources.StringResource
import shopkmp.core.uikit.generated.resources.Res
import shopkmp.core.uikit.generated.resources.button_ok
import shopkmp.core.uikit.generated.resources.error_serialization
import shopkmp.core.uikit.generated.resources.error_server_sign
import shopkmp.core.uikit.generated.resources.error_timeout
import shopkmp.core.uikit.generated.resources.error_title

@Stable
interface UiEventReducer<TUiEvent : UiEvent> {
    fun onUiEvent(event: TUiEvent)
    fun onDefaultUiEvent(event: DefaultUiEvent)
}

@Suppress("TooManyFunctions")
abstract class BaseViewModel<
    TUiEvent : UiEvent,
    TState : Any,
    >(initialState: TState, dependencies: BaseViewModelDependencies) : ViewModel(), UiEventReducer<TUiEvent> {
    private val singleClickService = dependencies.singleClickService
    private val connectivityService = dependencies.connectivityService
    private val lceStateManager by lazy { LceStateManager(deviceService, stringsService) }
    private val topBarStateManager by lazy { TopBarStateManager() }
    private val stringsService = dependencies.stringsService
    protected val appNavigator = dependencies.appNavigator
    protected val deviceService = dependencies.deviceService

    private var isScreenDataInitialized = false
    protected open val isRootScreen = false

    private val stateMutex = Mutex()

    private val _stateFlow =
        MutableStateFlow<ScreenState<TState>>(ScreenState(state = initialState))
    val stateFlow get() = _stateFlow.asStateFlow()

    protected val state: TState get() = stateFlow.value.state

    /**
     * Atomically updates the screen state without external locking.
     *
     * Uses [MutableStateFlow.update] which performs an atomic compare-and-swap, making
     * this safe for concurrent synchronous calls.
     *
     * Use this for **pure, non-suspending** transformations (e.g., copying a field,
     * toggling a flag, applying input changes). Do not use this method if the transform
     * lambda contains suspension points.
     */
    protected fun updateState(transform: TState.() -> TState) {
        _stateFlow.update { it.copy(state = transform.invoke(it.state)) }
    }

    /**
     * Serializes suspend state transformations via [stateMutex] to prevent interleaving.
     *
     * When multiple coroutines call this method concurrently, the mutex ensures they
     * execute **sequentially**: a coroutine reads the current state, performs its
     * suspension points (e.g. API calls, DB reads), and writes the new state before the
     * next one starts. Without this serialization, two coroutines could read the same
     * stale state, independently suspend, and then overwrite each other's writes.
     *
     * Use this for transformations that contain **suspension points** inside the lambda.
     * For pure synchronous transformations, prefer [updateState] which uses CAS directly.
     *
     * Note: synchronous [updateState] calls are **not** serialized by this mutex and may
     * interleave with a suspend transformation mid-flight. The final state write is still
     * atomic thanks to [MutableStateFlow.update], but the suspend transform may see an
     * intermediate state produced by a concurrent [updateState] call.
     */
    protected fun updateStateSuspend(transform: suspend TState.() -> TState) {
        viewModelScope.launch {
            stateMutex.withLock {
                _stateFlow.update { it.copy(state = transform.invoke(it.state)) }
            }
        }
    }

    init {
        lceStateManager.lceState.onEach { lceState ->
            _stateFlow.update { it.copy(lceState = lceState) }
        }.launchIn(viewModelScope)

        topBarStateManager.topBarState.onEach { topBarState ->
            _stateFlow.update {
                it.copy(topBarState = topBarState)
            }
        }.launchIn(viewModelScope)

        topBarStateManager.defaultUiEvent.onEach { topBarDefaultUiEvent ->
            onDefaultUiEvent(topBarDefaultUiEvent)
        }.launchIn(viewModelScope)

        lceStateManager.initCheckInternetAction {
            viewModelScope.launch {
                connectivityService.checkInternetAvailable(
                    onAvailable = {
                        hideError()
                        viewModelScope.launch { onInternetReconnected() }
                    },
                    onNotAvailable = {
                        showNoInternetError()
                    },
                )
            }
        }
    }

    private fun initializeScreenData() {
        viewModelScope.launch {
            lceStateManager.setUiInitialized(false)
            initTopBarInternal()
            initScreenStringsInternal()
            lceStateManager.setUiInitialized(true)
            loadScreenDataInternal()
        }
    }

    private suspend fun initTopBarInternal() {
        topBarStateManager.initTopBar {
            topBarStateManager.updateTopBar { initTopBarState().invoke(this) }
            topBarStateManager.topBarState.value
        }
    }

    protected suspend fun updateTopBar(action: suspend EpsTopBarState.() -> EpsTopBarState) {
        topBarStateManager.updateTopBar(action)
    }

    private suspend fun initScreenStringsInternal() {
        _stateFlow.update { it.copy(state = initScreenStrings().invoke(it.state)) }
    }

    protected fun forceLoadScreenData() {
        loadScreenDataInternal()
    }

    private fun loadScreenDataInternal() {
        viewModelScope.launch {
            showLoading()

            when (val loadDataResult = loadScreenData()) {
                is RemoteResult.Success -> {
                    hideLoading()
                    hideError()
                }

                RemoteResult.Error.NetworkError -> {
                    hideLoading()
                    showNoInternetError()
                }

                RemoteResult.Error.SerializationError -> {
                    hideLoading()
                    showFullScreenError(
                        repeatAction = ::loadScreenDataInternal,
                        errorTitle = getString(Res.string.error_serialization),
                    )
                }

                RemoteResult.Error.ServerSignError -> {
                    hideLoading()
                    showFullScreenError(
                        repeatAction = ::loadScreenDataInternal,
                        errorTitle = getString(Res.string.error_server_sign),
                    )
                }

                RemoteResult.Error.TimeoutError,
                -> {
                    hideLoading()
                    showFullScreenError(
                        repeatAction = ::loadScreenDataInternal,
                        errorTitle = getString(Res.string.error_timeout),
                    )
                }

                is RemoteResult.Error.ServerError -> {
                    hideLoading()

                    if (loadDataResult.isErrorHandled()) {
                        return@launch
                    }

                    showFullScreenError(
                        repeatAction = ::loadScreenDataInternal,
                        errorMessage = RemoteErrorMessageMapper.message(loadDataResult),
                    )
                }
            }
        }
    }

    private fun showFullScreenError(
        repeatAction: () -> Unit,
        errorTitle: String = "",
        errorMessage: String = "",
    ) {
        lceStateManager.showFullScreenError(
            repeatAction = repeatAction,
            backAction = { navigateBack() },
            errorTitle = errorTitle,
            errorMessage = errorMessage,
        )
    }

    private fun showNoInternetError() {
        lceStateManager.showNoInternetError()
    }

    protected open fun showLoading() {
        lceStateManager.showLoading()
    }

    protected open fun hideLoading() {
        lceStateManager.hideLoading()
    }

    protected fun isLoading(): Boolean {
        return lceStateManager.isLoading()
    }

    protected fun processRemoteError(error: RemoteResult.Error) {
        if (error.isErrorHandled()) {
            return
        }

        if (error is RemoteResult.Error.NetworkError) {
            showNoInternetError()
        } else {
            showRemoteErrorBottomSheet(error)
        }
    }

    private fun showRemoteErrorBottomSheet(error: RemoteResult.Error) {
        if (error.isErrorHandled()) {
            return
        }

        viewModelScope.launch {
            emitDefaultEffect(
                EpsBottomSheetState.ApiErrorBottomSheet(
                    title = getString(Res.string.error_title),
                    subtitle = RemoteErrorMessageMapper.message(error),
                    bottomButtonTitle = getString(Res.string.button_ok),
                )
            )
        }
    }

    protected fun showBottomSheet(epsBottomSheetState: EpsBottomSheetState) {
        emitDefaultEffect(epsBottomSheetState)
    }

    protected fun showError(errorState: EpsFullscreenErrorState) {
        lceStateManager.showError(errorState)
    }

    protected fun hideError() {
        lceStateManager.hideError()
    }

    fun showAlert(alertDialog: AlertDialogState) {
        emitDefaultEffect(alertDialog)
    }

    fun showToast(message: String, isErrorToast: Boolean = false) {
        emitDefaultEffect(ToastState(if (isErrorToast) ToastType.Error else ToastType.Success, message))
    }

    protected open fun initTopBarState(): suspend EpsTopBarState.() -> EpsTopBarState {
        return { copy() }
    }

    protected open fun initScreenStrings(): suspend TState.() -> TState {
        return { state }
    }

    protected open suspend fun loadScreenData(): RemoteResult<*> {
        return RemoteResult.Success(Unit)
    }

    private val _effectFlow = MutableSharedFlow<ViewModelEffect>()
    val effectFlow = _effectFlow.asSharedFlow()

    private val _defaultEffectFlow = MutableSharedFlow<UiDialogEffect>()
    val defaultEffectFlow = _defaultEffectFlow.asSharedFlow()

    protected fun emitEffect(effect: ViewModelEffect) {
        viewModelScope.launch { _effectFlow.emit(effect) }
    }

    protected fun emitDefaultEffect(effect: UiDialogEffect) {
        viewModelScope.launch { _defaultEffectFlow.emit(effect) }
    }

    override fun onUiEvent(event: TUiEvent) {
        if (!singleClickService.isClickAllowed(event)) {
            return
        }

        processUiEvent(event)
    }

    override fun onDefaultUiEvent(event: DefaultUiEvent) {
        if (!singleClickService.isClickAllowed(event)) {
            return
        }

        when (event) {
            DefaultUiEvent.OnScreenCreated -> {
                if (!isScreenDataInitialized) {
                    isScreenDataInitialized = true
                    initializeScreenData()
                }
                onScreenCreated()
                if (isRootScreen) {
                    lceStateManager.initializeIsRootScreen(true)
                }
            }

            DefaultUiEvent.OnScreenResumed -> onScreenResumed()
            DefaultUiEvent.OnScreenStarted -> onScreenStarted()
            DefaultUiEvent.OnBackClicked -> processBackClicked()
            DefaultUiEvent.OnNotificationsClicked -> { /* override in subclass if needed */ }
        }
    }

    protected open fun processBackClicked() {
        navigateBack()
    }

    protected abstract fun processUiEvent(event: TUiEvent)

    protected open fun onScreenResumed() = Unit

    protected open fun onScreenStarted() = Unit

    protected open fun onScreenCreated() = Unit

    protected open suspend fun onInternetReconnected() {
        loadScreenDataInternal()
    }

    protected suspend fun getString(stringRes: StringResource): String {
        return stringsService.getString(stringRes)
    }

    protected suspend fun getString(stringRes: StringResource, args: List<String>): String {
        return stringsService.getString(stringRes, args)
    }

    protected fun navigate(navigationAction: NavigationAction) {
        appNavigator.push(navigationAction)
    }

    protected open fun navigateBack() {
        appNavigator.pop()
    }

    protected fun replaceRoot(action: NavigationAction) {
        appNavigator.replaceAll(action)
    }

    protected inline fun withNavigator(block: AppNavigator.() -> Unit) = block(appNavigator)

    protected fun <T> setResult(key: String, value: T) {
        appNavigator.setPreviousBackStackEntry(key, value)
    }

    protected fun <T> getResult(key: String): T? {
        return appNavigator.getCurrentBackStackEntry(key)
    }

    protected fun <T> clearResult(key: String) {
        return appNavigator.clearCurrentBackStackEntry<T>(key)
    }

    protected fun <T> consumeResult(key: String): T? {
        return getResult<T>(key).also {
            clearResult<T>(key)
        }
    }
}
