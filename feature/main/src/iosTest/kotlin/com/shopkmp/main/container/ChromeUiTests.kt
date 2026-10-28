package com.shopkmp.main.container

import androidx.compose.foundation.layout.Column
import androidx.compose.ui.test.*
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.sdui.model.*
import com.shopkmp.sdui.renderer.SduiScreen
import kotlin.test.*

@OptIn(ExperimentalTestApi::class)
class ChromeUiTests {
    @Test fun oneActualHeadingAndEditControlWithRealBackAndRefreshActions() = runComposeUiTest {
        val document = ScreenDocument(1, ScreenName.profile, "a".repeat(64), listOf(
            Node.Text("profile.title", TextProps("Профиль", TextStyle.ScreenTitle)),
            Node.Button("profile.edit.button", ButtonProps("Изменить", "edit.original", ButtonStyle.Primary, true)),
            Node.Text("body", TextProps("Покупатель", TextStyle.Body)),
        ), listOf(Action.Native("edit.original", NativeKind.EditProfile)), WireState(true, emptyMap(), 0), Metadata("Профиль", "ru-RU", false))
        val events = mutableListOf<SduiEvent>()
        var backs = 0
        setContent {
            val chrome = sduiChromePlan(document, ScreenName.profile)
            Column {
                SduiChrome(chrome, true, false, { backs++ }, { events += it })
                SduiScreen(document, businessOrigin = "https://shop.example", chromeNodeIds = chrome.nodeIds, onEvent = { events += it })
            }
        }
        onAllNodesWithTag("profile.title").assertCountEquals(1)
        onNodeWithTag("profile.title").assertTextEquals("Профиль")
        onAllNodesWithTag("profile.edit.button").assertCountEquals(1)
        onNodeWithTag("profile.edit.button").assertHasClickAction().performClick()
        onNodeWithTag("nav.back").assertHasClickAction().performClick()
        onNodeWithTag("nav.refresh").assertHasClickAction().performClick()
        onNodeWithTag("body").assertExists()
        runOnIdle { assertEquals(1, backs); assertEquals(listOf(SduiEvent.Activate("edit.original"), SduiEvent.Retry), events) }
    }
}
