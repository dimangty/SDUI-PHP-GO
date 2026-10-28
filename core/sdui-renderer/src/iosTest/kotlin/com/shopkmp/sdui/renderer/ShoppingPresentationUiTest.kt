package com.shopkmp.sdui.renderer

import androidx.compose.runtime.*
import androidx.compose.ui.test.*
import androidx.compose.ui.Modifier
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.sdui.model.*
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

@OptIn(ExperimentalTestApi::class)
class ShoppingPresentationUiTest {
    @Test fun compactHomeSearchKeepsAccessibleInputAndOriginalExplicitSubmit() = runComposeUiTest {
        val events = mutableListOf<SduiEvent>()
        var fields by mutableStateOf(mapOf("catalog.q" to ""))
        val document = ScreenDocument(1, ScreenName.home, "a".repeat(64), listOf(
            Node.Form("home.search.form", FormProps(FormId.catalog, "Поиск", "original.search"), listOf(
                Node.Field("catalog.q", FieldKind.SearchField, FieldProps("catalog.q", "Поиск товаров", Input.Text, false, false, 200, "change", 200)),
                Node.Button("home.search.button", ButtonProps("Найти", "original.search", ButtonStyle.Primary, true)),
            )),
        ), listOf(Action.UpdateField("change", "catalog.q"), Action.SubmitForm("original.search", FormId.catalog)),
            WireState(false, fields, 0), Metadata("ShopKMP", "ru-RU", false))
        setContent { SduiScreen(document, fields, businessOrigin = "https://shop.example", onEvent = {
            events += it
            if (it is SduiEvent.FieldChanged) fields = fields + ("catalog.q" to it.value)
        }) }
        onNodeWithTag("catalog.q").performTextInput("лампа")
        onNodeWithTag("catalog.q").assertContentDescriptionEquals("Поиск товаров")
        onNodeWithTag("home.search.button").assertHasClickAction().performClick()
        runOnIdle { assertEquals(listOf<SduiEvent>(SduiEvent.FieldChanged("change", "лампа"), SduiEvent.Activate("original.search")), events) }
    }

    @Test fun profileShowsRealDefaultAndDisclosesEveryAdditionalAddressWithoutActions() = runComposeUiTest {
        val document = ScreenDocument(1, ScreenName.profile, "a".repeat(64), listOf(
            Node.Text("profile.address.default", TextProps("Москва, Светлая, 1, 2", TextStyle.Body)),
            Node.Text("address.default", TextProps("Москва, Светлая, 1, 2", TextStyle.Body)),
            Node.Text("address.other", TextProps("Томск, Лесная, 3, 4", TextStyle.Body)),
        ), emptyList(), WireState(true, emptyMap(), 0), Metadata("Профиль", "ru-RU", false))
        var events = 0
        setContent { SduiScreen(document, businessOrigin = "https://shop.example", onEvent = { events++ }) }
        onNodeWithText("Москва, Светлая, 1, 2").assertExists()
        onNodeWithTag("address.other").assertDoesNotExist()
        onNodeWithTag("profile.addresses.expand").performClick()
        onNodeWithTag("address.other").assertTextEquals("Томск, Лесная, 3, 4")
        onAllNodesWithText("Москва, Светлая, 1, 2").assertCountEquals(1)
        runOnIdle { assertEquals(0, events) }
    }
    @Test fun legacyPositionalRendererCallStillDispatchesOriginalAction() = runComposeUiTest {
        val events = mutableListOf<SduiEvent>()
        val document = checkoutDocument()
        setContent { SduiScreen(document, document.state.fields, emptyMap(), false, "https://shop.example", Modifier, null, { events += it }) }
        onNodeWithTag("checkout.button").assertHasClickAction().performClick()
        runOnIdle { assertEquals(listOf<SduiEvent>(SduiEvent.Activate("checkout.submit")), events) }
    }
    @Test fun missingOrInvalidCheckoutAddressUnfoldsAndRetainsErrorsAndPaymentValue() = runComposeUiTest {
        val document = checkoutDocument()
        var fields by mutableStateOf(document.state.fields + ("checkout.city" to "") + ("checkout.paymentMethod" to "SBP"))
        var errors by mutableStateOf(mapOf("checkout.street" to "Проверьте улицу"))
        val events = mutableListOf<SduiEvent>()
        setContent { SduiScreen(document, fields, errors, businessOrigin = "https://shop.example", onEvent = { events += it }) }
        onNodeWithTag("checkout.city").assertExists()
        onNodeWithText("Проверьте улицу").assertExists()
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.city").assertExists()
        onNodeWithTag("checkout.payment.edit").performClick()
        onNodeWithTag("checkout.paymentMethod.option.SBP").assertIsSelected()
        runOnIdle { errors = emptyMap(); fields = fields + ("checkout.city" to "Казань") }
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.city").assertDoesNotExist()
        runOnIdle { assertTrue(events.isEmpty()); assertEquals("SBP", fields["checkout.paymentMethod"]) }
    }

    @Test fun biometricUsesOriginalActionOnceAndNeverOptimisticallyFlipsConfiguredTruth() = runComposeUiTest {
        val events = mutableListOf<SduiEvent>()
        var busy by mutableStateOf(false)
        val document = biometricDocument(Action.Native("native.original", NativeKind.ManageBiometric))
        setContent { SduiScreen(document, actionInProgress = busy, businessOrigin = "https://shop.example",
            biometricAvailable = false, biometricConfigured = true, onEvent = { events += it }) }
        onNodeWithTag("profile.biometric.button").assertIsOn().assertIsEnabled().performClick()
        onNodeWithTag("profile.biometric.button").assertIsOn()
        runOnIdle { assertEquals(listOf<SduiEvent>(SduiEvent.Activate("native.original")), events); busy = true }
        onNodeWithTag("profile.biometric.button").assertIsNotEnabled().assertIsOn()
    }

    @Test fun missingBiometricCapabilityOrTypedActionIsDisabled() = runComposeUiTest {
        var available by mutableStateOf(false)
        var typed by mutableStateOf(true)
        var events = 0
        setContent {
            val action = if (typed) Action.Native("native.original", NativeKind.ManageBiometric) else Action.Unknown("native.original")
            SduiScreen(biometricDocument(action), businessOrigin = "https://shop.example", biometricAvailable = available, onEvent = { events++ })
        }
        onNodeWithTag("profile.biometric.button").assertIsOff().assertIsNotEnabled()
        runOnIdle { available = true }
        onNodeWithTag("profile.biometric.button").assertIsEnabled()
        runOnIdle { typed = false }
        onNodeWithTag("profile.biometric.button").assertIsNotEnabled()
        runOnIdle { assertEquals(0, events) }
    }

    @Test fun ordersDisclosureDoesNotFilterUntilExplicitApplyAndCardUsesLocalizedStatus() = runComposeUiTest {
        val events = mutableListOf<SduiEvent>()
        val form = Node.Form("orders.form", FormProps(FormId.orders, "Фильтр", "apply"), listOf(
            Node.Field("orders.status", FieldKind.FormField, FieldProps("orders.status", "Статус", Input.Select, false, false, 20, "change", options = listOf(Option("PAID", "Оплачен")))),
            Node.Button("orders.filter", ButtonProps("Применить", "apply", ButtonStyle.Primary, true)),
        ))
        val document = ScreenDocument(1, ScreenName.orders, "a".repeat(64), listOf(form,
            Node.OrderCard("order.real", OrderProps("real", 42, OrderStatus.PAID, "100.02", "Москва", PaymentMethod.CARD, "2026-10-27", "open"))),
            listOf(Action.UpdateField("change", "orders.status"), Action.SubmitForm("apply", FormId.orders), Action.Order("open", "real")),
            WireState(true, mapOf("orders.status" to "PAID"), 0), Metadata("Заказы", "ru-RU", false))
        setContent { SduiScreen(document, businessOrigin = "https://shop.example", onEvent = { events += it }) }
        onNodeWithTag("orders.status").assertDoesNotExist()
        onNodeWithTag("order.real.open").assertTextContains("● Оплачен")
        onNodeWithTag("orders.filter.edit").performClick()
        onNodeWithTag("orders.status.option.PAID").assertIsSelected()
        runOnIdle { assertTrue(events.isEmpty()) }
        onNodeWithTag("orders.filter").performClick()
        runOnIdle { assertEquals(listOf<SduiEvent>(SduiEvent.Activate("apply")), events); assertEquals(OrderStatus.PAID, document.nodes().filterIsInstance<Node.OrderCard>().single().props.status) }
    }

    @Test fun automaticallyOpenedBlankAddressStaysOpenThroughMultiCharacterHouseAndApartment() = runComposeUiTest {
        val document = checkoutDocument()
        var fields by mutableStateOf(document.state.fields.mapValues { (id, value) -> if (id == "checkout.paymentMethod") value else "" })
        val events = mutableListOf<SduiEvent>()
        setContent { SduiScreen(document, fields, businessOrigin = "https://shop.example", onEvent = {
            events += it
            if (it is SduiEvent.FieldChanged) fields = fields + (it.actionId.removePrefix("change.") to it.value)
        }) }
        // No Edit click: a fresh account's missing required address opens automatically.
        onNodeWithTag("checkout.city").performTextInput("Москва")
        onNodeWithTag("checkout.street").performTextInput("Светлая")
        onNodeWithTag("checkout.house").performTextInput("5")
        onNodeWithTag("checkout.house").assertIsDisplayed().assertIsFocused().assertTextContains("5")
        onNodeWithTag("checkout.house").performTextInput("1")
        onNodeWithTag("checkout.house").assertTextContains("51")
        onNodeWithTag("checkout.apartment").performTextInput("24")
        onNodeWithTag("checkout.apartment").assertTextContains("24")
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.house").assertDoesNotExist()
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.house").assertTextContains("51")
        onNodeWithTag("checkout.apartment").assertTextContains("24")
        runOnIdle {
            assertEquals(listOf<SduiEvent>(
                SduiEvent.FieldChanged("change.checkout.city", "Москва"),
                SduiEvent.FieldChanged("change.checkout.street", "Светлая"),
                SduiEvent.FieldChanged("change.checkout.house", "5"),
                SduiEvent.FieldChanged("change.checkout.house", "51"),
                SduiEvent.FieldChanged("change.checkout.apartment", "24"),
            ), events)
            assertEquals("CARD", fields["checkout.paymentMethod"])
        }
    }

    @Test fun automaticallyExposedAddressErrorStaysOpenAfterLastErrorIsCleared() = runComposeUiTest {
        val document = checkoutDocument()
        var fields by mutableStateOf(document.state.fields)
        var errors by mutableStateOf<Map<String, String>>(emptyMap())
        val events = mutableListOf<SduiEvent>()
        setContent { SduiScreen(document, fields, errors, businessOrigin = "https://shop.example", onEvent = {
            events += it
            if (it is SduiEvent.FieldChanged) {
                val field = it.actionId.removePrefix("change.")
                fields = fields + (field to it.value)
                errors = errors - field
            }
        }) }
        onNodeWithTag("checkout.street").assertDoesNotExist() // Valid saved address stays compact.
        runOnIdle { errors = mapOf("checkout.street" to "Проверьте улицу") }
        onNodeWithText("Проверьте улицу").assertExists()
        // No Edit click: validation exposes the existing controlled fields.
        onNodeWithTag("checkout.street").performTextReplacement("Лесная")
        onNodeWithText("Проверьте улицу").assertDoesNotExist()
        onNodeWithTag("checkout.street").assertIsDisplayed().assertTextContains("Лесная")
        onNodeWithTag("checkout.apartment").performTextReplacement("12")
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.street").assertDoesNotExist()
        onNodeWithText("Москва, Лесная, 1, 12", substring = true).assertExists()
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.apartment").assertTextContains("12")
        runOnIdle {
            assertTrue(errors.isEmpty())
            assertEquals(listOf<SduiEvent>(
                SduiEvent.FieldChanged("change.checkout.street", "Лесная"),
                SduiEvent.FieldChanged("change.checkout.apartment", "12"),
            ), events)
        }
    }

    @Test fun checkoutDisclosurePreservesControlledValuesWithoutSubmitting() = runComposeUiTest {
        val document = checkoutDocument()
        var fields by mutableStateOf(document.state.fields)
        val events = mutableListOf<SduiEvent>()
        setContent { SduiScreen(document, fields, businessOrigin = "https://shop.example", onEvent = {
            events += it
            if (it is SduiEvent.FieldChanged) fields = fields + (it.actionId.removePrefix("change.") to it.value)
        }) }
        onNodeWithTag("checkout.city").assertDoesNotExist()
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.city").assertTextContains("Москва")
        onNodeWithTag("checkout.city").performTextReplacement("Томск")
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.city").assertDoesNotExist()
        onNodeWithText("Томск, Светлая, 1, 2", substring = true).assertExists()
        onNodeWithTag("checkout.address.edit").performClick()
        onNodeWithTag("checkout.city").assertTextContains("Томск")
        runOnIdle { assertEquals(listOf<SduiEvent>(SduiEvent.FieldChanged("change.checkout.city", "Томск")), events) }
    }

    @Test fun primaryShoppingActionUsesAvailableWidth() = runComposeUiTest {
        val document = checkoutDocument()
        setContent { SduiScreen(document, businessOrigin = "https://shop.example", onEvent = {}) }
        val form = onNodeWithTag("checkout.form").fetchSemanticsNode().boundsInRoot
        val cta = onNodeWithTag("checkout.button").fetchSemanticsNode().boundsInRoot
        assertEquals(form.width, cta.width, 0.5f)
    }
}

private fun biometricDocument(action: Action) = ScreenDocument(1, ScreenName.profile, "a".repeat(64),
    listOf(Node.Button("profile.biometric.button", ButtonProps("Биометрия", action.id, ButtonStyle.Primary, true))),
    listOf(action), WireState(true, emptyMap(), 0), Metadata("Профиль", "ru-RU", false))

internal fun checkoutDocument(): ScreenDocument {
    val fields = mapOf("checkout.city" to "Москва", "checkout.street" to "Светлая", "checkout.house" to "1", "checkout.apartment" to "2", "checkout.paymentMethod" to "CARD")
    val inputs = fields.keys.map { id -> Node.Field(id, FieldKind.FormField,
        FieldProps(id, id, if (id.endsWith("paymentMethod")) Input.Select else Input.Text,
            !id.endsWith("apartment"), false, 255, "change.$id", options = if (id.endsWith("paymentMethod")) listOf(Option("CARD", "Карта"), Option("SBP", "СБП")) else emptyList())) }
    return ScreenDocument(1, ScreenName.cart, "a".repeat(64), listOf(Node.Form("checkout.form", FormProps(FormId.checkout, "Доставка и оплата", "checkout.submit"),
        inputs + Node.Button("checkout.button", ButtonProps("Оформить заказ", "checkout.submit", ButtonStyle.Primary, true)))),
        fields.keys.map { Action.UpdateField("change.$it", it) } + Action.Checkout("checkout.submit", FormId.checkout), WireState(true, fields, 1), Metadata("Корзина", "ru-RU", false))
}
