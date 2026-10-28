package com.shopkmp.auth.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.State
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.InternalComposeUiApi
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.platform.LocalPlatformWindowInsets
import androidx.compose.ui.platform.PlatformInsets
import androidx.compose.ui.platform.PlatformWindowInsets
import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.assertHeightIsEqualTo
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.runSkikoComposeUiTest
import androidx.compose.ui.unit.dp
import androidx.navigation3.runtime.NavEntry
import androidx.navigation3.ui.NavDisplay
import com.shopkmp.mvvm.BaseScreen
import com.shopkmp.mvvm.common.LceState
import com.shopkmp.auth.ui.login.LoginContent
import com.shopkmp.auth.ui.login.LoginState
import com.shopkmp.uikit.component.auth.EpsSignUpContent
import com.shopkmp.uikit.component.auth.EpsSignUpContentState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.checkbox.EpsCheckboxState
import com.shopkmp.uikit.component.dialog.UiDialogEffect
import com.shopkmp.uikit.component.main.EpsRootScaffold
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.resetMain
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.time.Duration.Companion.seconds

@OptIn(ExperimentalTestApi::class, InternalComposeUiApi::class, ExperimentalCoroutinesApi::class)
class HostedAuthKeyboardUiTest {
    @BeforeTest
    fun restorePlatformMainForHostedNavigation() {
        // Existing auth VM tests leave Main on their completed test scheduler.
        // NavDisplay's lifecycle work needs the platform Main used by UI tests.
        Dispatchers.resetMain()
    }

    @Test
    fun keyboardSafeAreaDoesNotSubtractTheSignupViewportTwice() =
        runSkikoComposeUiTest(size = Size(402f, 874f), testTimeout = 30.seconds) {
            val keyboardVisible = mutableStateOf(false)
            var password = ""
            val effects = MutableSharedFlow<UiDialogEffect>()
            // Model the installed iOS geometry: the host safe area already reserves
            // 335pt, while Compose also reports a 301pt IME overlap (335 - 34).
            val insets = hostInsets(keyboardVisible, keyboardNavigationBottom = 335, imeBottom = 301)
            setContent {
                HostedAuth(insets, effects) { EpsSignUpContent(signUpState { password = it }) }
            }

            onNodeWithTag("signup.scroll").assertHeightIsEqualTo(714.dp)
            onNodeWithTag("signup.name").performClick()
            runOnIdle { keyboardVisible.value = true }
            onNodeWithTag("signup.scroll").assertHeightIsEqualTo(413.dp)
            onNodeWithTag("signup.password").performScrollTo().assertIsDisplayed().performTextInput("test-password")
            assertEquals("test-password", password)
            onNodeWithTag("signup.passwordRepeat").performScrollTo().assertIsDisplayed()
            onNodeWithTag("signup.submit").performScrollTo().assertIsDisplayed()

            runOnIdle { keyboardVisible.value = false }
            onNodeWithTag("signup.scroll").assertHeightIsEqualTo(714.dp)
        }

    @Test
    fun separateImeInsetStillReducesTheSignupViewportOnce() =
        runSkikoComposeUiTest(size = Size(402f, 874f), testTimeout = 30.seconds) {
            val keyboardVisible = mutableStateOf(false)
            val effects = MutableSharedFlow<UiDialogEffect>()
            // Android edge-to-edge style: navigation remains 34pt; IME is 335pt.
            val insets = hostInsets(keyboardVisible, keyboardNavigationBottom = 34, imeBottom = 335)
            setContent { HostedAuth(insets, effects) { EpsSignUpContent(signUpState {}) } }

            onNodeWithTag("signup.scroll").assertHeightIsEqualTo(714.dp)
            runOnIdle { keyboardVisible.value = true }
            onNodeWithTag("signup.scroll").assertHeightIsEqualTo(413.dp)
            onNodeWithTag("signup.password").performScrollTo().assertIsDisplayed()
            onNodeWithTag("signup.submit").performScrollTo().assertIsDisplayed()
        }

    @Test
    fun loginKeepsItsHostVisibleViewportAndActionsReachableWithKeyboard() =
        runSkikoComposeUiTest(size = Size(402f, 874f), testTimeout = 30.seconds) {
            val keyboardVisible = mutableStateOf(false)
            val effects = MutableSharedFlow<UiDialogEffect>()
            val insets = hostInsets(keyboardVisible, keyboardNavigationBottom = 335, imeBottom = 301)
            var password = ""
            setContent {
                HostedAuth(insets, effects, addTopBar = false) {
                    LoginContent(
                        LoginState(passwordTextFieldState = EpsPasswordTextFieldState(
                            label = "Пароль", onValueChange = { password = it },
                        )),
                    )
                }
            }

            onNode(hasScrollAction()).assertHeightIsEqualTo(778.dp)
            onNodeWithTag("login.email").performClick()
            runOnIdle { keyboardVisible.value = true }
            onNode(hasScrollAction()).assertHeightIsEqualTo(477.dp)
            onNodeWithTag("login.password").performScrollTo().assertIsDisplayed().performTextInput("test-password")
            assertEquals("test-password", password)
            onNodeWithTag("login.submit").performScrollTo().assertIsDisplayed()
            onNodeWithTag("login.signup").performScrollTo().assertIsDisplayed()
        }
}

@OptIn(InternalComposeUiApi::class)
private fun hostInsets(
    keyboardVisible: State<Boolean>,
    keyboardNavigationBottom: Int,
    imeBottom: Int,
): PlatformWindowInsets = object : PlatformWindowInsets {
    override val statusBars = PlatformInsets(top = 62)
    override val navigationBars = PlatformInsets(getBottom = { if (keyboardVisible.value) keyboardNavigationBottom else 34 })
    override val ime = PlatformInsets(getBottom = { if (keyboardVisible.value) imeBottom else 0 })
}

@OptIn(InternalComposeUiApi::class)
@Composable
private fun HostedAuth(
    insets: PlatformWindowInsets,
    effects: MutableSharedFlow<UiDialogEffect>,
    addTopBar: Boolean = true,
    content: @Composable () -> Unit,
) {
    CompositionLocalProvider(LocalPlatformWindowInsets provides insets) {
        EpsRootScaffold { modifier ->
            NavDisplay(
                backStack = listOf("signup"),
                modifier = modifier,
                onBack = {},
                entryProvider = { key ->
                    NavEntry(key) {
                        BaseScreen(
                            lceState = LceState(isUiInitialized = true, isRootScreen = true),
                            topBarState = EpsTopBarState(title = "Регистрация"),
                            defaultEffectFlow = effects,
                            onDefaultUiEvent = {},
                            addTopBar = addTopBar,
                        ) {
                            content()
                        }
                    }
                },
            )
        }
    }
}

private fun signUpState(onPassword: (String) -> Unit) = EpsSignUpContentState(
    fullNameTextFieldState = EpsTextFieldState(label = "Имя и фамилия"),
    emailTextFieldState = EpsTextFieldState(label = "Email"),
    phoneTextFieldState = EpsTextFieldState(label = "Телефон"),
    passwordTextFieldState = EpsPasswordTextFieldState(label = "Пароль", onValueChange = onPassword),
    repeatedPasswordTextFieldState = EpsPasswordTextFieldState(label = "Повторите пароль"),
    termsCheckboxState = EpsCheckboxState(label = "Принимаю условия сервиса"),
    errorMessage = null,
    createAccountButtonState = EpsButtonState(text = "Создать аккаунт", enabled = false),
)
