package com.shopkmp.auth.ui.pin

import com.shopkmp.auth.domain.AuthError
import com.shopkmp.auth.domain.AuthPinDevice
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.auth.domain.SignUpData
import com.shopkmp.common.device.DeviceService
import com.shopkmp.common.resource.StringsService
import com.shopkmp.mvvm.LceStateManager
import com.shopkmp.mvvm.BaseViewModelDependencies
import com.shopkmp.mvvm.common.connectivity.ConnectivityService
import com.shopkmp.mvvm.common.singleclick.SingleClickService
import com.shopkmp.mvvm.common.singleclick.SingleClickServiceImpl
import com.shopkmp.mvvm.common.topbar.TopBarStateManager
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.navigation.navigationservice.AppNavigator
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.jetbrains.compose.resources.StringResource
import org.koin.core.context.startKoin
import org.koin.core.context.stopKoin
import org.koin.mp.KoinPlatformTools
import org.koin.dsl.module
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

@OptIn(ExperimentalCoroutinesApi::class)
class PinViewModelTest {

    @Test
    fun loginFiltersInputAndSubmitsSixDigitPin() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val navigator = setupKoin()
        val auth = PinFakeAuthService()
        val viewModel = PinViewModel(auth, PinScreenMode.Login)

        viewModel.onUiEvent(PinUiEvent.OnPinChanged("12a34567"))
        assertEquals("123456", viewModel.stateFlow.value.state.pin)
        assertTrue(viewModel.stateFlow.value.state.isVerifyEnabled)

        viewModel.onUiEvent(PinUiEvent.OnVerifyClicked)
        advanceUntilIdle()

        assertEquals("123456", auth.loginPin)
        assertEquals(NavigationAction.NavigateToMain, navigator.replacedWith)
    }

    @Test
    fun invalidPinClearsInputAndShowsAttemptsLeft() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        setupKoin()
        val auth = PinFakeAuthService().apply {
            loginResult = Result.failure(AuthError.InvalidPinWithAttempts(2))
        }
        val viewModel = PinViewModel(auth, PinScreenMode.Login)

        viewModel.onUiEvent(PinUiEvent.OnPinChanged("1357"))
        viewModel.onUiEvent(PinUiEvent.OnVerifyClicked)
        advanceUntilIdle()

        val state = viewModel.stateFlow.value.state
        assertEquals("", state.pin)
        assertFalse(state.isVerifyEnabled)
        assertEquals("Неверный PIN. Осталось попыток: 2", state.pinError)
    }

    private fun setupKoin(): PinFakeNavigator {
        if (KoinPlatformTools.defaultContext().getOrNull() != null) {
            stopKoin()
        }
        val navigator = PinFakeNavigator()
        startKoin {
            modules(
                module {
                    single { DeviceService() }
                    single { TopBarStateManager() }
                    single<SingleClickService> { SingleClickServiceImpl() }
                    single<ConnectivityService> { PinFakeConnectivityService() }
                    single<StringsService> { PinFakeStringsService() }
                    single<AppNavigator> { navigator }
                    single { BaseViewModelDependencies(get(), get(), get(), get(), get()) }
                },
            )
        }
        return navigator
    }
}

private class PinFakeAuthService : AuthService {
    var loginPin: String? = null
    var loginResult: Result<Unit> = Result.success(Unit)

    override fun hasConfiguredPin(): Boolean = true
    override fun hasSavedSession(): Boolean = true
    override suspend fun restoreSession(): Boolean = true
    override suspend fun login(email: String, password: String): Result<Unit> = Result.success(Unit)
    override suspend fun signUp(data: SignUpData): Result<Unit> = Result.success(Unit)
    override fun shouldCreatePinAfterPasswordLogin(): Boolean = false
    override suspend fun createPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun changePin(currentPin: String, newPin: String): Result<Unit> = Result.success(Unit)
    override suspend fun loginByPin(pin: String): Result<Unit> {
        loginPin = pin
        return loginResult
    }
    override suspend fun getPinDevices(): List<AuthPinDevice> = emptyList()
    override suspend fun revokePinDevice(deviceId: String): Result<Unit> = Result.success(Unit)
    override suspend fun logout() = Unit
}

private class PinFakeConnectivityService : ConnectivityService {
    override suspend fun checkInternetAvailable(onAvailable: () -> Unit, onNotAvailable: () -> Unit) = onAvailable()
}

private class PinFakeStringsService : StringsService {
    override suspend fun getString(stringRes: StringResource): String = ""
    override suspend fun getString(stringRes: StringResource, args: List<String>): String = ""
}

private class PinFakeNavigator : AppNavigator {
    var replacedWith: NavigationAction? = null
    override val backstack: androidx.navigation3.runtime.NavBackStack<NavigationAction>
        get() = error("backstack not used in tests")
    override val currentDestination = MutableStateFlow<NavigationAction?>(null)
    override fun push(route: NavigationAction, singleTop: Boolean) = Unit
    override fun pop() = Unit
    override fun popTo(route: NavigationAction, inclusive: Boolean) = Unit
    override fun clear() = Unit
    override fun replaceAll(route: NavigationAction) {
        replacedWith = route
    }
    override fun replaceAll(routes: List<NavigationAction>) {
        replacedWith = routes.lastOrNull()
    }
    override fun <T> setPreviousBackStackEntry(key: String, value: T) = Unit
    override fun <T> getCurrentBackStackEntry(key: String): T? = null
    override fun <T> clearCurrentBackStackEntry(key: String) = Unit
}
