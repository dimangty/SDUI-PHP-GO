package com.shopkmp.auth.ui

import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.runComposeUiTest
import com.shopkmp.auth.ui.login.LoginContent
import com.shopkmp.auth.ui.login.LoginState
import com.shopkmp.auth.ui.pin.PinContent
import com.shopkmp.auth.ui.pin.PinState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import androidx.compose.runtime.mutableStateOf
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

@OptIn(ExperimentalTestApi::class)
class AuthScreenUiTest {
    @Test
    fun loginAcceptsEmailAndInvokesPrimaryAction() = runComposeUiTest {
        var email = ""
        var clicked = false
        setContent {
            LoginContent(
                LoginState(
                    emailTextFieldState = EpsTextFieldState(
                        value = email,
                        label = "Email",
                        onValueChange = { email = it },
                    ),
                    loginButtonState = EpsButtonState(
                        text = "Войти",
                        onClick = { clicked = true },
                    ),
                ),
            )
        }

        onNodeWithTag("login.email").performTextInput("user@example.com")
        onNodeWithText("Войти").performClick()

        assertEquals("user@example.com", email)
        assertTrue(clicked)
    }

    @Test
    fun pinKeypadAndContinueInvokeScreenActions() = runComposeUiTest {
        var selectedDigit: Int? = null
        var continued = false
        setContent {
            PinContent(
                PinState(
                    // Submit is only presented for a valid PIN; keep the original action assertions.
                    pin = "1234",
                    isVerifyEnabled = true,
                    digitButtonStates = (0..9).associateWith { digit ->
                        EpsButtonState(
                            text = digit.toString(),
                            onClick = { selectedDigit = digit },
                        )
                    },
                    continueButtonState = EpsButtonState(
                        text = "Продолжить",
                        onClick = { continued = true },
                    ),
                ),
            )
        }

        onNodeWithText("1").performClick()
        onNodeWithText("Продолжить").performClick()

        assertEquals(1, selectedDigit)
        assertTrue(continued)
    }

    @Test
    fun emptyAndPartialPinDoNotExposeSubmit() = runComposeUiTest {
        val pin = mutableStateOf("")
        setContent {
            PinContent(
                PinState(
                    pin = pin.value,
                    digitButtonStates = (0..9).associateWith { EpsButtonState(text = it.toString()) },
                    continueButtonState = EpsButtonState(text = "Продолжить", enabled = false),
                ),
            )
        }

        listOf("", "1", "12", "123").forEach { partial ->
            runOnIdle { pin.value = partial }
            onNodeWithTag("pin.submit").assertDoesNotExist()
            onNodeWithTag("pin.secondary").assertExists()
        }
    }

    @Test
    fun pinBackspaceInvokesTheActualKeyAction() = runComposeUiTest {
        var deleted = false
        setContent {
            PinContent(
                PinState(
                    pin = "123",
                    digitButtonStates = (0..9).associateWith { EpsButtonState(text = it.toString()) },
                    backspaceButtonState = EpsButtonState(onClick = { deleted = true }),
                ),
            )
        }

        onNodeWithTag("pin.backspace").performClick()
        assertTrue(deleted)
    }
}
