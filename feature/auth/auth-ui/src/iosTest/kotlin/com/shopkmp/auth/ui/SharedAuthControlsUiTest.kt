package com.shopkmp.auth.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.runComposeUiTest
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.AnnotatedString
import com.shopkmp.ui.theme.AppTheme
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.navigationbar.EpsNavigationBar
import com.shopkmp.uikit.component.navigationbar.EpsNavigationItemState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextField
import com.shopkmp.uikit.component.textfield.passwordtextfield.EpsPasswordTextFieldState
import com.shopkmp.uikit.component.topbar.EpsTopBar
import com.shopkmp.uikit.component.topbar.EpsTopBarActionState
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

@OptIn(ExperimentalTestApi::class)
class SharedAuthControlsUiTest {
    @Test
    fun filledFieldKeepsEditableTagImeActionAndReadonlyLockSemantics() = runComposeUiTest {
        val value = mutableStateOf("")
        val readOnly = mutableStateOf(false)
        var completed = false
        setContent {
            AppTheme {
                EpsTextField(
                    EpsTextFieldState(
                        value = value.value,
                        label = "Email",
                        readOnly = readOnly.value,
                        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
                        keyboardActions = KeyboardActions(onDone = { completed = true }),
                        onValueChange = { value.value = it },
                    ),
                    Modifier.testTag("field"),
                    trailingIcon = {
                        if (readOnly.value) EpsIcon(EpsIconName.Lock, "Email нельзя изменить")
                    },
                )
            }
        }

        onNodeWithTag("field").assert(hasSetTextAction()).performTextInput("user@example.com")
        onNodeWithTag("field").performImeAction()
        assertEquals("user@example.com", value.value)
        assertTrue(completed)

        runOnIdle { readOnly.value = true }
        onNodeWithTag("field").assert(SemanticsMatcher.keyNotDefined(SemanticsActions.SetText))
        onNodeWithContentDescription("Email нельзя изменить", useUnmergedTree = true)
            .assert(SemanticsMatcher.keyNotDefined(SemanticsActions.OnClick))
    }

    @Test
    fun passwordVisibilityAndValidationRemainOnTheEditableNode() = runComposeUiTest {
        val visible = mutableStateOf(false)
        setContent {
            AppTheme {
                EpsPasswordTextField(
                    EpsPasswordTextFieldState(
                        value = "password123",
                        label = "Пароль",
                        error = "Пароли не совпадают",
                        passwordVisible = visible.value,
                        onVisibilityToggle = { visible.value = !visible.value },
                    ),
                    Modifier.testTag("password"),
                )
            }
        }

        onNodeWithTag("password").assert(hasSetTextAction())
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.Password, Unit))
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.Error, "Пароли не совпадают"))
        onNodeWithContentDescription("Показать пароль").performClick()
        // Revealing characters retains native password-keyboard/security semantics.
        onNodeWithTag("password")
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString("password123")))
        onNodeWithContentDescription("Скрыть пароль").performClick()
        onNodeWithTag("password")
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString("•••••••••••")))
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.Password, Unit))
    }

    @Test
    fun appBarTagsBelongToTheTitleAndEnabledActions() = runComposeUiTest {
        var backs = 0
        var searches = 0
        var edits = 0
        setContent {
            AppTheme {
                EpsTopBar(
                    EpsTopBarState(
                        title = "Регистрация",
                        titleTestTag = "title",
                        showBackButton = true,
                        backTestTag = "back",
                        onBackClick = { backs++ },
                        actions = listOf(
                            EpsTopBarActionState(EpsIconName.Search, "Поиск", "search") { searches++ },
                            EpsTopBarActionState(EpsIconName.Edit, "Редактировать", "edit", false) { edits++ },
                        ),
                    ),
                )
            }
        }

        onNodeWithTag("title").assert(SemanticsMatcher.keyNotDefined(SemanticsActions.OnClick))
        onNodeWithTag("back").performClick()
        onNodeWithTag("search").performClick()
        onNodeWithTag("edit").assertIsNotEnabled().performClick()
        assertEquals(1, backs)
        assertEquals(1, searches)
        assertEquals(0, edits)
    }

    @Test
    fun legacyNotificationsConstructorStillInvokesItsTrailingCallback() = runComposeUiTest {
        var notified = false
        setContent {
            AppTheme {
                EpsTopBar(EpsTopBarState(showNotificationsButton = true) { notified = true })
            }
        }

        onNodeWithContentDescription("Уведомления").performClick()
        assertTrue(notified)
    }

    @Test
    fun navigationPreservesTrailingLambdaCallbacksAndControlledSelection() = runComposeUiTest {
        val selected = mutableStateOf(0)
        setContent {
            AppTheme {
                Column {
                    EpsNavigationBar(
                        listOf(
                            EpsNavigationItemState("Главная", selected.value == 0, "home") { selected.value = 0 },
                            EpsNavigationItemState("Каталог", selected.value == 1, "catalog",
                                icon = EpsIconName.Catalog) { selected.value = 1 },
                            EpsNavigationItemState("Корзина", selected.value == 2, "cart") { selected.value = 2 },
                            EpsNavigationItemState("Профиль", selected.value == 3, "profile") { selected.value = 3 },
                        ),
                    )
                }
            }
        }

        onNodeWithTag("home").assertIsSelected()
        onNodeWithTag("catalog").performClick().assertIsSelected()
        onNodeWithTag("cart").performClick().assertIsSelected()
        onNodeWithTag("profile").performClick().assertIsSelected()
        assertEquals(3, selected.value)
    }
}
