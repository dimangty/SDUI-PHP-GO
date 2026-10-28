package com.shopkmp.sdui.renderer

import com.shopkmp.sdui.model.*
import com.shopkmp.sdui.actions.*
import kotlin.test.*

class PresentationTest {
    @Test fun oversoldCartKeepsAbsoluteReductionAndDisablesIncreaseWhileBusyBlocksAllEvents() {
        val props = CartProps("p", "Лампа", 3, "50.01", null, false, 1, "open", "quantity", "remove")
        val document = ScreenDocument(1, ScreenName.cart, "a".repeat(64), emptyList(),
            listOf(Action.Cart("quantity", CartKind.UpdateQuantity, "p")), WireState(true, emptyMap(), 3), Metadata("Корзина", "ru-RU", false))
        val sent = mutableListOf<SduiEvent>()
        val state = cartItemPresentation(props, "https://shop.example", EventRegistry(document, false) { sent += it })
        assertFalse(state.quantityStepperState.increaseEnabled)
        assertTrue(state.quantityStepperState.decreaseEnabled)
        assertEquals("Недостаточно товара · Доступно: 1", state.availabilityText)
        state.quantityStepperState.onDecrease()
        assertEquals(listOf<SduiEvent>(SduiEvent.QuantityChanged("quantity", 2)), sent)
        val busy = cartItemPresentation(props, "https://shop.example", EventRegistry(document, true) { sent += it })
        assertFalse(busy.quantityStepperState.decreaseEnabled)
        busy.quantityStepperState.onDecrease()
        assertEquals(1, sent.size)
    }
    @Test fun cartImageUsesConfiguredPublicOriginAndNeverLoadsProtectedOrExternalSources() {
        val props = CartProps("p", "Лампа", 1, "50.01", "/images/products/lamp.png", true, 5, "open", "quantity", "remove")
        val document = ScreenDocument(1, ScreenName.cart, "a".repeat(64), emptyList(), emptyList(), WireState(true, emptyMap(), 1), Metadata("Корзина", "ru-RU", false))
        val events = EventRegistry(document, false) {}
        val state = cartItemPresentation(props, "https://shop.example/", events)
        assertEquals("https://shop.example/images/products/lamp.png", state.imageUrl)
        assertEquals("50,01 ₽", state.priceText)
        for (path in listOf(null, "/uploads/users/avatar.png", "https://evil.example/lamp.png", "/images/products/../secret.png", "//evil.example/a.png")) {
            assertNull(cartItemPresentation(props.copy(image = path), "https://shop.example", events).imageUrl, path)
        }
    }
    @Test fun removingPromotedNodesRetainsBodyTitleAndUnpromotedActions() {
        val heading = Node.Text("heading", TextProps("Профиль", TextStyle.ScreenTitle))
        val body = Node.Text("body", TextProps("Имя товара", TextStyle.Title))
        val edit = Node.Button("edit", ButtonProps("Изменить", "edit.action", ButtonStyle.Primary, true))
        val unknown = Node.Button("unknown", ButtonProps("Будущее", "unknown.action", ButtonStyle.Primary, true))
        val root = Node.Layout("root", LayoutKind.LazyColumn, LayoutProps(Space.m, Space.m, Align.Start), listOf(heading, body, edit, unknown))
        val result = screenRenderPlan(listOf(root), setOf("heading", "edit"))
        assertEquals(listOf("body", "unknown"), result.items.map { it.id })
        assertEquals(listOf("heading", "body", "edit", "unknown"), screenRenderPlan(listOf(root)).items.map { it.id })
    }

    @Test fun presentationFormattingNeverChangesCanonicalValues() {
        assertEquals("9 999 999 999,99 ₽", rubDisplay("9999999999.99"))
        val labels = OrderStatus.entries.associateWith { it.displayLabel() }
        assertEquals("Оплачен", labels[OrderStatus.PAID])
        assertEquals("Доставляется", labels[OrderStatus.DELIVERING])
        assertEquals(6, labels.values.toSet().size)
        assertEquals("PAID", OrderStatus.PAID.name)
        assertEquals("При получении", PaymentMethod.ON_DELIVERY.displayLabel())
        assertEquals("ON_DELIVERY", PaymentMethod.ON_DELIVERY.name)
        assertNotEquals(OrderStatus.CREATED.displayColor(), OrderStatus.COMPLETED.displayColor())
        assertNotEquals(OrderStatus.CANCELLED.displayColor(), OrderStatus.PAID.displayColor())
    }
}
