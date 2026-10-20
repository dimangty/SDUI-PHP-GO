package com.shopkmp.auth.ui.login

import com.shopkmp.auth.domain.AuthPinDevice
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.auth.domain.SignUpData
import com.shopkmp.common.device.DeviceService
import com.shopkmp.common.resource.StringsService
import com.shopkmp.mvvm.BaseViewModelDependencies
import com.shopkmp.mvvm.common.connectivity.ConnectivityService
import com.shopkmp.mvvm.common.singleclick.SingleClickServiceImpl
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
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

@OptIn(ExperimentalCoroutinesApi::class)
class LoginViewModelTest {

    @Test
    fun passwordLoginValidatesInputAndRoutesToPinCreation() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val navigator = LoginFakeNavigator()
        val auth = LoginFakeAuthService().apply { createPinAfterLogin = true }
        val viewModel = LoginViewModel(auth, dependencies(navigator))

        viewModel.onUiEvent(LoginUiEvent.OnEmailChanged("user@example.com"))
        viewModel.onUiEvent(LoginUiEvent.OnPasswordChanged("S3cret!pass"))
        assertTrue(viewModel.stateFlow.value.state.isLoginEnabled)

        viewModel.onUiEvent(LoginUiEvent.OnLoginClicked)
        advanceUntilIdle()

        assertEquals("user@example.com" to "S3cret!pass", auth.passwordLogin)
        assertEquals(NavigationAction.NavigateToPin, navigator.replacedWith)
    }

    @Test
    fun biometricLoginIsVisibleAndRoutesToMain() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val navigator = LoginFakeNavigator()
        val auth = LoginFakeAuthService().apply {
            biometricConfigured = true
            biometricAvailable = true
        }
        val viewModel = LoginViewModel(auth, dependencies(navigator))

        assertNotNull(viewModel.stateFlow.value.state.biometricLoginButtonState)
        viewModel.onUiEvent(LoginUiEvent.OnBiometricLoginClicked)
        advanceUntilIdle()

        assertTrue(auth.biometricLoginCalled)
        assertEquals(NavigationAction.NavigateToMain, navigator.replacedWith)
    }

    private fun dependencies(navigator: LoginFakeNavigator) = BaseViewModelDependencies(
        SingleClickServiceImpl(),
        LoginFakeConnectivityService(),
        LoginFakeStringsService(),
        navigator,
        DeviceService(),
    )
}

private class LoginFakeAuthService : AuthService {
    var passwordLogin: Pair<String, String>? = null
    var createPinAfterLogin = false
    var biometricConfigured = false
    var biometricAvailable = false
    var biometricLoginCalled = false

    override fun hasConfiguredPin(): Boolean = false
    override fun hasConfiguredBiometric(): Boolean = biometricConfigured
    override fun isBiometricAvailable(): Boolean = biometricAvailable
    override fun hasSavedSession(): Boolean = false
    override suspend fun restoreSession(): Boolean = false
    override suspend fun login(email: String, password: String): Result<Unit> {
        passwordLogin = email to password
        return Result.success(Unit)
    }
    override suspend fun signUp(data: SignUpData): Result<Unit> = Result.success(Unit)
    override fun shouldCreatePinAfterPasswordLogin(): Boolean = createPinAfterLogin
    override suspend fun createPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun changePin(currentPin: String, newPin: String): Result<Unit> = Result.success(Unit)
    override suspend fun loginByPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun loginByBiometric(): Result<Unit> {
        biometricLoginCalled = true
        return Result.success(Unit)
    }
    override suspend fun getPinDevices(): List<AuthPinDevice> = emptyList()
    override suspend fun revokePinDevice(deviceId: String): Result<Unit> = Result.success(Unit)
    override suspend fun logout() = Unit
}

private class LoginFakeConnectivityService : ConnectivityService {
    override suspend fun checkInternetAvailable(onAvailable: () -> Unit, onNotAvailable: () -> Unit) = onAvailable()
}

private class LoginFakeStringsService : StringsService {
    override suspend fun getString(stringRes: StringResource): String = ""
    override suspend fun getString(stringRes: StringResource, args: List<String>): String = ""
}

private class LoginFakeNavigator : AppNavigator {
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
