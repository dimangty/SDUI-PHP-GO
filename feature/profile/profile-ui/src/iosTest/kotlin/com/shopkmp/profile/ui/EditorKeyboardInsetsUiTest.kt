package com.shopkmp.profile.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.InternalComposeUiApi
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.platform.LocalPlatformWindowInsets
import androidx.compose.ui.platform.PlatformInsets
import androidx.compose.ui.platform.PlatformWindowInsets
import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.assertHeightIsEqualTo
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.test.runSkikoComposeUiTest
import androidx.compose.ui.unit.dp
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.ui.theme.AppTheme
import kotlin.test.Test
import kotlin.time.Duration.Companion.seconds

@OptIn(ExperimentalTestApi::class, InternalComposeUiApi::class)
class EditorKeyboardInsetsUiTest {
    @Test
    fun editorAncestorNavigationPaddingAlreadyConsumesTheKeyboardSafeArea() =
        runSkikoComposeUiTest(size = Size(402f, 874f), testTimeout = 30.seconds) {
            val keyboardVisible = mutableStateOf(false)
            val insets = object : PlatformWindowInsets {
                override val statusBars = PlatformInsets(top = 62)
                override val navigationBars = PlatformInsets(getBottom = { if (keyboardVisible.value) 335 else 34 })
                override val ime = PlatformInsets(getBottom = { if (keyboardVisible.value) 301 else 0 })
            }
            val content = EditProfileStateBuilder().build(
                profile = CustomerProfile(email = "buyer@example.com"),
                name = "Покупатель", phone = "+79991234567", address = "Омск, Мира, 5, 7",
                onBack = {}, onName = {}, onPhone = {}, onAddress = {}, onSave = {},
            )
            setContent {
                CompositionLocalProvider(LocalPlatformWindowInsets provides insets) {
                    AppTheme {
                        // Same inset ownership as MainContainerScreen's native-editor branch.
                        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
                            EditProfileContent(EditProfileState(content, loading = false), {})
                        }
                    }
                }
            }

            onNode(hasScrollAction()).assertHeightIsEqualTo(714.dp)
            onNodeWithTag("editProfile.name").performClick()
            runOnIdle { keyboardVisible.value = true }
            onNode(hasScrollAction()).assertHeightIsEqualTo(413.dp)
            onNodeWithTag("editProfile.address").performScrollTo().assertIsDisplayed()
            onNode(hasScrollAction()).performScrollToNode(hasTestTag("editProfile.save"))
            onNodeWithTag("editProfile.save").assertIsDisplayed()
            onNodeWithTag("editProfile.title").assertIsDisplayed()
        }
}
