package com.shopkmp.sdui.renderer

import androidx.compose.runtime.*
import androidx.compose.ui.test.*
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.unit.dp
import com.shopkmp.sdui.model.*
import com.shopkmp.sdui.actions.*
import kotlin.test.Test
import kotlin.test.assertEquals
import com.shopkmp.uikit.component.error.EpsErrorMessage

@OptIn(ExperimentalTestApi::class)
class SduiScreenUiTest {
    @Test fun nestedVerticalLayoutRetainsItsPaddingSpacingAlignmentAndSingleScrollOwner() = runComposeUiTest {
        val nested = Node.Layout("nested", LayoutKind.LazyColumn, LayoutProps(Space.xl, Space.l, Align.End), listOf(
            Node.Text("a", TextProps("A", TextStyle.Body)), Node.Text("b", TextProps("B", TextStyle.Body)),
        ))
        val root = Node.Layout("root", LayoutKind.LazyColumn, LayoutProps(Space.none, Space.none, Align.Start), listOf(nested))
        val document = layoutDocument(root)
        setContent { SduiScreen(document, businessOrigin = "https://shop.example", onEvent = {}) }
        onNodeWithTag("nested").assertIsDisplayed()
        val outer = onNodeWithTag("nested").fetchSemanticsNode().boundsInRoot
        val a = onNodeWithTag("a").fetchSemanticsNode().boundsInRoot
        val b = onNodeWithTag("b").fetchSemanticsNode().boundsInRoot
        assertEquals(with(density) { 24.dp.toPx() }, outer.right - a.right, 0.5f)
        assertEquals(with(density) { 24.dp.toPx() }, a.top - outer.top, 0.5f)
        assertEquals(with(density) { 32.dp.toPx() }, b.top - a.bottom, 0.5f)
        onAllNodes(SemanticsMatcher.keyIsDefined(SemanticsProperties.VerticalScrollAxisRange)).assertCountEquals(1)
    }

    @Test fun rootVerticalLayoutHonorsEndAlignment() = runComposeUiTest {
        val root = Node.Layout("root", LayoutKind.LazyColumn, LayoutProps(Space.s, Space.m, Align.End), listOf(
            Node.Icon("icon", IconProps(IconName.Check, "Check")),
        ))
        setContent { SduiScreen(layoutDocument(root), businessOrigin = "https://shop.example", onEvent = {}) }
        val outer = onNodeWithTag("sdui.vertical").fetchSemanticsNode().boundsInRoot
        val icon = onNodeWithTag("icon").fetchSemanticsNode().boundsInRoot
        assertEquals(with(density) { 16.dp.toPx() }, outer.right - icon.right, 0.5f)
    }

    @Test fun duplicateSelectOptionsFallBackAtDecodedUiBoundary() = duplicateCollectionUi(select = true)
    @Test fun duplicateCategoriesFallBackAtDecodedUiBoundary() = duplicateCollectionUi(select = false)

    @Test fun unknownCategoryActionIsVisiblyDisabled() = runComposeUiTest {
        val document = ScreenDocument(1, ScreenName.home, "a".repeat(64), listOf(
            Node.CategoryList("categories", CategoriesProps(listOf(Category("44444444-4444-4444-8444-444444444444", "Unknown category", null, "future", false)))),
        ), listOf(Action.Unknown("future")), WireState(false, emptyMap(), 0), Metadata("Home", "ru-RU", false))
        setContent { SduiScreen(document, businessOrigin = "https://shop.example", onEvent = {}) }
        onNodeWithText("Unknown category").assertIsNotEnabled()
    }

    @Test fun controlledInputUpdatesAndUnknownPurchaseCannotExecute() = runComposeUiTest {
        var fields by mutableStateOf(mapOf("catalog.q" to ""))
        var submits = 0
        val document = ScreenDocument(1, ScreenName.catalog, "a".repeat(64), listOf(
            Node.Field("query", FieldKind.SearchField, FieldProps("catalog.q", "Поиск", Input.Text, false, false, 200, "change", 200)),
            Node.Button("submit", ButtonProps("Найти", "submit", ButtonStyle.Primary, true)),
            Node.Button("unknown", ButtonProps("Неизвестная покупка", "future", ButtonStyle.Primary, true)),
        ), listOf(Action.UpdateField("change", "catalog.q"), Action.SubmitForm("submit", FormId.catalog), Action.Unknown("future")),
            WireState(false, fields, 0), Metadata("Каталог", "ru-RU", false))
        setContent {
            SduiScreen(document, fields, businessOrigin = "https://shop.example", onEvent = {
                when (it) {
                    is SduiEvent.FieldChanged -> fields = fields + ("catalog.q" to it.value)
                    is SduiEvent.Activate -> submits++
                    else -> Unit
                }
            })
        }
        onNodeWithTag("query").performTextInput("лампа")
        onNodeWithTag("query").assertTextContains("лампа")
        onNodeWithTag("unknown").assertIsNotEnabled()
        onNodeWithTag("submit").performClick()
        runOnIdle { assertEquals(1, submits) }
    }
}

private fun layoutDocument(root: Node) = ScreenDocument(1, ScreenName.home, "a".repeat(64), listOf(root), emptyList(),
    WireState(false, emptyMap(), 0), Metadata("Layout", "ru-RU", false))

@OptIn(ExperimentalTestApi::class)
private fun duplicateCollectionUi(select: Boolean) = runComposeUiTest {
    val option = """{"value":"rating","label":"A"}"""
    val category = """{"id":"44444444-4444-4444-8444-444444444444","label":"A","image":null,"actionId":"change","selected":false}"""
    val component = if (select) """{"id":"field","type":"FormField","props":{"fieldId":"catalog.sort","label":"Sort","input":"Select","required":false,"readOnly":false,"maxLength":20,"changeAction":"change","options":[$option,$option]}}"""
        else """{"id":"categories","type":"CategoryList","props":{"items":[$category,$category]}}"""
    val action = if (select) """{"id":"change","type":"UpdateField","fieldId":"catalog.sort"}"""
        else """{"id":"change","type":"Navigate","destination":{"screen":"catalog"}}"""
    val body = """{"schemaVersion":1,"screenId":"catalog","revision":"${"a".repeat(64)}","components":[$component],"actions":[$action],"state":{"authenticated":false,"fields":{"catalog.sort":"rating"},"cartItemsCount":0},"metadata":{"title":"Catalog","locale":"ru-RU","partial":false}}"""
    val decoded = ScreenDecoder().decode(body)
    var events = 0
    setContent {
        when (decoded) {
            is ScreenResult.Content -> SduiScreen(decoded.screen, businessOrigin = "https://shop.example", onEvent = { events++ })
            is ScreenResult.Invalid -> EpsErrorMessage("Screen unavailable", androidx.compose.ui.Modifier.testTag("document.error"))
        }
    }
    onNodeWithTag("document.error").assertIsDisplayed()
    runOnIdle { assertEquals(0, events) }
}
