package com.shopkmp.auth.ui

import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.runComposeUiTest
import com.shopkmp.auth.ui.login.LoginContent
import com.shopkmp.auth.ui.login.LoginState
import com.shopkmp.auth.ui.pin.PinContent
import com.shopkmp.auth.ui.pin.PinState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
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

        onNodeWithText("Email").performTextInput("user@example.com")
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
}
