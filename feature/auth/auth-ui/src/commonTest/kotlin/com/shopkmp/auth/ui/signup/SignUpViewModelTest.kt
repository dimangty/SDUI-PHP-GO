package com.shopkmp.auth.ui.signup

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
import kotlin.test.assertFalse
import kotlin.test.assertTrue

@OptIn(ExperimentalCoroutinesApi::class)
class SignUpViewModelTest {
    @Test
    fun validFormSubmitsNormalizedDataAndRoutesToPin() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val auth = SignUpFakeAuthService()
        val navigator = SignUpFakeNavigator()
        val viewModel = SignUpViewModel(auth, dependencies(navigator))

        viewModel.onUiEvent(SignUpUiEvent.OnFullNameChanged("Иван Петров"))
        viewModel.onUiEvent(SignUpUiEvent.OnEmailChanged("user@example.com"))
        viewModel.onUiEvent(SignUpUiEvent.OnPhoneChanged("+79130000000"))
        viewModel.onUiEvent(SignUpUiEvent.OnPasswordChanged("S3cretPass"))
        viewModel.onUiEvent(SignUpUiEvent.OnRepeatedPasswordChanged("S3cretPass"))
        viewModel.onUiEvent(SignUpUiEvent.OnTermsChanged(true))

        assertTrue(viewModel.stateFlow.value.state.isValid)
        viewModel.onUiEvent(SignUpUiEvent.OnSignUpClicked)
        advanceUntilIdle()

        assertEquals(
            SignUpData("Иван Петров", "user@example.com", "+79130000000", "S3cretPass"),
            auth.submitted,
        )
        assertEquals(NavigationAction.NavigateToPin, navigator.replacedWith)
    }

    @Test
    fun passwordMismatchAndVisibilityTogglesAreReflectedInState() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val viewModel = SignUpViewModel(SignUpFakeAuthService(), dependencies(SignUpFakeNavigator()))

        viewModel.onUiEvent(SignUpUiEvent.OnPasswordChanged("S3cretPass"))
        viewModel.onUiEvent(SignUpUiEvent.OnRepeatedPasswordChanged("different"))
        viewModel.onUiEvent(SignUpUiEvent.OnPasswordVisibilityToggleClicked)
        viewModel.onUiEvent(SignUpUiEvent.OnRepeatedPasswordVisibilityToggleClicked)

        val state = viewModel.stateFlow.value.state
        assertFalse(state.isValid)
        assertEquals("Пароли не совпадают", state.repeatedPasswordTextFieldState.error)
        assertTrue(state.passwordVisible)
        assertTrue(state.repeatedPasswordVisible)
    }

    @Test
    fun failedSubmissionKeepsUserOnFormAndShowsError() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val auth = SignUpFakeAuthService().apply {
            result = Result.failure(IllegalArgumentException("Email уже используется"))
        }
        val navigator = SignUpFakeNavigator()
        val viewModel = SignUpViewModel(auth, dependencies(navigator))
        viewModel.onUiEvent(SignUpUiEvent.OnFullNameChanged("Иван Петров"))
        viewModel.onUiEvent(SignUpUiEvent.OnEmailChanged("user@example.com"))
        viewModel.onUiEvent(SignUpUiEvent.OnPhoneChanged("+79130000000"))
        viewModel.onUiEvent(SignUpUiEvent.OnPasswordChanged("S3cretPass"))
        viewModel.onUiEvent(SignUpUiEvent.OnRepeatedPasswordChanged("S3cretPass"))
        viewModel.onUiEvent(SignUpUiEvent.OnTermsChanged(true))

        viewModel.onUiEvent(SignUpUiEvent.OnSignUpClicked)
        advanceUntilIdle()

        assertEquals("Email уже используется", viewModel.stateFlow.value.state.errorMessage)
        assertEquals(null, navigator.replacedWith)
    }

    @Test
    fun generatedControlCallbacksUpdateTheForm() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        val viewModel = SignUpViewModel(SignUpFakeAuthService(), dependencies(SignUpFakeNavigator()))
        val controls = viewModel.stateFlow.value.state

        controls.fullNameTextFieldState.onValueChange?.invoke("Анна Смирнова")
        controls.emailTextFieldState.onValueChange?.invoke("anna@example.com")
        controls.phoneTextFieldState.onValueChange?.invoke("+79130000001")
        controls.passwordTextFieldState.onValueChange?.invoke("StrongPass1")
        controls.passwordTextFieldState.onVisibilityToggle?.invoke()
        controls.repeatedPasswordTextFieldState.onValueChange?.invoke("StrongPass1")
        controls.repeatedPasswordTextFieldState.onVisibilityToggle?.invoke()
        controls.termsCheckboxState.onCheckedChange?.invoke(true)

        val state = viewModel.stateFlow.value.state
        assertEquals("Анна Смирнова", state.fullName)
        assertEquals("anna@example.com", state.email)
        assertEquals("+79130000001", state.phone)
        assertTrue(state.passwordVisible)
        assertTrue(state.repeatedPasswordVisible)
        assertTrue(state.isValid)
    }

    private fun dependencies(navigator: SignUpFakeNavigator) = BaseViewModelDependencies(
        SingleClickServiceImpl(),
        SignUpFakeConnectivityService(),
        SignUpFakeStringsService(),
        navigator,
        DeviceService(),
    )
}

private class SignUpFakeAuthService : AuthService {
    var submitted: SignUpData? = null
    var result: Result<Unit> = Result.success(Unit)

    override fun hasConfiguredPin(): Boolean = false
    override fun hasSavedSession(): Boolean = false
    override suspend fun restoreSession(): Boolean = false
    override suspend fun login(email: String, password: String): Result<Unit> = Result.success(Unit)
    override suspend fun signUp(data: SignUpData): Result<Unit> {
        submitted = data
        return result
    }
    override fun shouldCreatePinAfterPasswordLogin(): Boolean = true
    override suspend fun createPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun changePin(currentPin: String, newPin: String): Result<Unit> = Result.success(Unit)
    override suspend fun loginByPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun getPinDevices(): List<AuthPinDevice> = emptyList()
    override suspend fun revokePinDevice(deviceId: String): Result<Unit> = Result.success(Unit)
    override suspend fun logout() = Unit
}

private class SignUpFakeConnectivityService : ConnectivityService {
    override suspend fun checkInternetAvailable(onAvailable: () -> Unit, onNotAvailable: () -> Unit) =
        onAvailable()
}

private class SignUpFakeStringsService : StringsService {
    override suspend fun getString(stringRes: StringResource): String = ""
    override suspend fun getString(stringRes: StringResource, args: List<String>): String = ""
}

private class SignUpFakeNavigator : AppNavigator {
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
