package com.shopkmp.profile.ui

import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextReplacement
import androidx.compose.ui.test.runComposeUiTest
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.ui.theme.AppTheme
import com.shopkmp.uikit.component.shop.EpsBiometricRow
import com.shopkmp.uikit.component.shop.EpsEditAvatar
import com.shopkmp.uikit.component.shop.EpsEditAvatarState
import kotlin.test.Test
import kotlin.test.assertEquals

@OptIn(ExperimentalTestApi::class)
class EditProfileContentUiTest {
    @Test
    fun actualEditorControlsKeepReadonlyEmailAndFixedAppBar() = runComposeUiTest {
        var enteredName = ""
        var saves = 0
        var backs = 0
        val content = EditProfileStateBuilder().build(
            profile = CustomerProfile(email = "buyer@example.com"),
            name = "Покупатель", phone = "+79991234567", address = "Омск, Мира, 5, 7",
            onBack = {}, onName = { enteredName = it }, onPhone = {}, onAddress = {}, onSave = { saves++ },
        )
        setContent { AppTheme { EditProfileContent(EditProfileState(content, loading = false), { backs++ }) } }

        onNodeWithTag("editProfile.name").assert(hasSetTextAction()).performTextReplacement("Новое имя")
        assertEquals("Новое имя", enteredName)
        onNodeWithTag("editProfile.phone").assert(hasSetTextAction())
        onNodeWithTag("editProfile.address").assert(hasSetTextAction())
        onNodeWithTag("editProfile.email").assertIsEnabled()
            .assert(SemanticsMatcher.keyNotDefined(SemanticsActions.SetText))
        onNodeWithContentDescription("Email можно изменить через поддержку", useUnmergedTree = true)
            .assert(SemanticsMatcher.keyNotDefined(SemanticsActions.OnClick))
        onNodeWithContentDescription("Email (через поддержку)").assertExists()
        onNodeWithTag("editProfile.photo").assertIsNotEnabled()
        onNodeWithText("Изменение фото пока недоступно").assertExists()
        onNodeWithTag("editProfile.save").performScrollTo().performClick()
        onNodeWithTag("editProfile.title").assertIsDisplayed()
        onAllNodesWithText("Редактирование").assertCountEquals(1)
        onNodeWithTag("editProfile.back").performClick()
        assertEquals(1, saves)
        assertEquals(1, backs)
    }

    @Test
    fun photoControlOnlyInvokesAnAvailableCapability() = runComposeUiTest {
        var changes = 0
        val available = mutableStateOf(false)
        setContent {
            AppTheme {
                EpsEditAvatar(EpsEditAvatarState("", "Изменить фото",
                    onChangePhoto = if (available.value) ({ changes++ }) else null))
            }
        }
        onNodeWithTag("editProfile.photo").assertIsNotEnabled().performClick()
        assertEquals(0, changes)
        runOnIdle { available.value = true }
        onNodeWithTag("editProfile.photo").assertIsEnabled().performClick()
        assertEquals(1, changes)
        onNodeWithText("Изменение фото пока недоступно").assertDoesNotExist()
    }

    @Test
    fun loadingAndLoadErrorAreVisibleWithoutLosingBackNavigation() = runComposeUiTest {
        val state = mutableStateOf(EditProfileState())
        var backs = 0
        setContent { AppTheme { EditProfileContent(state.value, { backs++ }) } }
        onNodeWithTag("editProfile.loading").assertIsDisplayed()
        onNodeWithTag("editProfile.save").assertDoesNotExist()
        runOnIdle { state.value = EditProfileState(error = "Не удалось загрузить профиль", loading = false) }
        onNodeWithTag("editProfile.loading").assertDoesNotExist()
        onNodeWithTag("editProfile.error").assertIsDisplayed()
        onNodeWithText("Не удалось загрузить профиль").assertIsDisplayed()
        onNodeWithTag("editProfile.back").performClick()
        assertEquals(1, backs)
    }

    @Test
    fun savingDisablesActualSaveAndFieldsWhileFailureKeepsEnteredText() = runComposeUiTest {
        val content = EditProfileStateBuilder().build(
            profile = CustomerProfile(email = "buyer@example.com"), name = "Новое имя",
            phone = "+79991234567", address = "Омск, Мира, 5, 7",
            onBack = {}, onName = {}, onPhone = {}, onAddress = {}, onSave = {}, saving = true,
        )
        val state = mutableStateOf(EditProfileState(content, loading = true))
        setContent { AppTheme { EditProfileContent(state.value, {}) } }
        onNodeWithTag("editProfile.name").assertIsNotEnabled()
        onNodeWithTag("editProfile.save").performScrollTo().assertIsNotEnabled()
        onNodeWithTag("editProfile.loading").performScrollTo().assertIsDisplayed()
        runOnIdle {
            state.value = EditProfileState(
                content.copy(
                    nameTextFieldState = content.nameTextFieldState.copy(enabled = true),
                    saveButtonState = content.saveButtonState.copy(enabled = true),
                ), error = "Проверьте адрес", loading = false,
            )
        }
        onNodeWithTag("editProfile.error").performScrollTo().assertIsDisplayed()
        onNodeWithTag("editProfile.save").performScrollTo().assertIsEnabled()
        onNodeWithTag("editProfile.name").performScrollTo().assertIsEnabled()
        onNodeWithText("Новое имя").assertExists()
    }

    @Test
    fun biometricRowIsControlledAndCanRevokeConfiguredButUnavailableBiometrics() = runComposeUiTest {
        val checked = mutableStateOf(false)
        val enabled = mutableStateOf(false)
        var toggles = 0
        setContent {
            AppTheme {
                EpsBiometricRow("Вход по биометрии", checked.value, enabled.value, { toggles++ },
                    Modifier.testTag("biometric"))
            }
        }
        onNodeWithTag("biometric").assertIsOff().assertIsNotEnabled().performClick()
        assertEquals(0, toggles)
        runOnIdle { enabled.value = true }
        onNodeWithTag("biometric").performClick().assertIsOff()
        assertEquals(1, toggles)
        runOnIdle { checked.value = true }
        onNodeWithTag("biometric").performClick().assertIsOn()
        assertEquals(2, toggles)
        runOnIdle { enabled.value = false }
        onNodeWithTag("biometric").assertIsOn().assertIsNotEnabled().performClick()
        assertEquals(2, toggles)
    }
}
