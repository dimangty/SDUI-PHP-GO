package com.shopkmp.main.container

import com.shopkmp.sdui.model.*
import kotlin.test.*

class ChromeTests {
    @Test fun headingAndExistingEditActionArePromotedExactlyOnce() {
        val document = chromeDocument(ScreenName.profile, listOf(
            Node.Text("profile.title", TextProps("Профиль", TextStyle.ScreenTitle)),
            Node.Button("profile.edit.button", ButtonProps("Редактировать", "original.edit", ButtonStyle.Primary, true)),
            Node.Button("unrelated", ButtonProps("Другое", "future", ButtonStyle.Primary, true)),
        ), listOf(Action.Native("original.edit", NativeKind.EditProfile), Action.Unknown("future")))
        val chrome = sduiChromePlan(document, ScreenName.profile)
        assertEquals("profile.title", chrome.titleNodeId)
        assertEquals(setOf("profile.title", "profile.edit.button"), chrome.nodeIds)
        assertEquals("original.edit", chrome.actions.single().actionId)
        assertEquals("profile.edit.button", chrome.actions.single().nodeId)
    }

    @Test fun productNameRemainsBodyContentEvenForLegacyScreenTitleStyle() {
        val chrome = sduiChromePlan(chromeDocument(ScreenName.product,
            listOf(Node.Text("product.title", TextProps("Реальное имя товара", TextStyle.ScreenTitle)))), ScreenName.product)
        assertEquals("Товар", chrome.title)
        assertFalse("product.title" in chrome.nodeIds)
    }

    @Test fun homeShortcutsUseOnlyExistingTypedRoutes() {
        val nodes = listOf(
            Node.Text("home.title", TextProps("ShopKMP", TextStyle.ScreenTitle)),
            Node.Button("home.catalog", ButtonProps("Каталог", "search.route", ButtonStyle.Primary, true)),
            Node.Button("home.profile", ButtonProps("Профиль", "profile.route", ButtonStyle.Primary, true)),
        )
        val actions = listOf(Action.Route("search.route", RouteKind.Navigate, Destination(ScreenName.catalog)),
            Action.Route("profile.route", RouteKind.Navigate, Destination(ScreenName.profile)))
        val chrome = sduiChromePlan(chromeDocument(ScreenName.home, nodes, actions), ScreenName.home)
        assertEquals(listOf("search.route", "profile.route"), chrome.actions.map { it.actionId })
        assertEquals(3, chrome.nodeIds.size)
        val unknown = sduiChromePlan(chromeDocument(ScreenName.home, nodes, listOf(Action.Unknown("search.route"))), ScreenName.home)
        assertTrue(unknown.actions.isEmpty())
        assertEquals(setOf("home.title"), unknown.nodeIds)
    }
}

private fun chromeDocument(screen: ScreenName, nodes: List<Node>, actions: List<Action> = emptyList()) = ScreenDocument(
    1, screen, "a".repeat(64), listOf(Node.Layout("root", LayoutKind.LazyColumn, LayoutProps(Space.m, Space.m, Align.Start), nodes)),
    actions, WireState(true, emptyMap(), 0), Metadata("Backend title", "ru-RU", false),
)
