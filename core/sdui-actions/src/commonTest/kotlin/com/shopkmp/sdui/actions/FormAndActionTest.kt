package com.shopkmp.sdui.actions

import com.shopkmp.sdui.model.*
import kotlin.test.*

class FormAndActionTest {
    @Test fun searchUsesUtf8BytesClearsSortAndRetainsCategoryWindow() {
        val fields = mapOf("catalog.q" to "Ж".repeat(100), "catalog.sort" to "rating", "catalog.size" to "20")
        val destination = Forms.catalog(fields, CollectionQuery(3, 2))
        assertEquals(0, destination.catalog!!.page)
        assertNull(destination.catalog!!.sort)
        assertEquals(CollectionQuery(3, 2), destination.categories)
        assertFailsWith<IllegalArgumentException> { Forms.catalog(fields + ("catalog.q" to "Ж".repeat(101))) }
        assertFailsWith<IllegalArgumentException> { Forms.catalog(mapOf("catalog.priceMin" to "50.01", "catalog.priceMax" to "50.00")) }
    }

    @Test fun profileRequiresCanonicalPhoneAndCheckoutRejectsMissingAddress() {
        assertEquals(ProfileInput("Buyer", "+79991234567"), Forms.profile(mapOf("profile.fullName" to " Buyer ", "profile.phone" to "+79991234567")))
        listOf("79991234567", "+09991234567", "+1234567", "+1234567890123456").forEach { phone ->
            assertFailsWith<IllegalArgumentException> { Forms.profile(mapOf("profile.fullName" to "Buyer", "profile.phone" to phone)) }
        }
        assertFailsWith<IllegalArgumentException> { Forms.checkout(mapOf("checkout.paymentMethod" to "CARD")) }
    }

    @Test fun finiteCommandsEncodeExactWireEnvelopeAndRequireConfirmation() {
        val command: Command = Command.UpdateQuantity(QuantityInput("22222222-2222-4222-8222-222222222222", 0))
        assertEquals("""{"command":"UpdateQuantity","input":{"productId":"22222222-2222-4222-8222-222222222222","quantity":0}}""", kotlinx.serialization.json.Json.encodeToString(command))
        assertFailsWith<IllegalArgumentException> { validateCommand(Command.CancelOrder(CancelInput("33333333-3333-4333-8333-333333333333", false))) }
    }

    @Test fun paymentOriginsRequireExactHttpOriginWithoutCredentials() {
        val policy = PaymentUrlPolicy("https://shop.example", setOf("https://pay.example"))
        assertEquals("https://pay.example/pay/1", policy.allowed("https://pay.example/pay/1"))
        listOf("javascript:alert(1)", "https://pay.example.evil/pay", "https://user@pay.example/pay", "http://pay.example/pay", "https://evil/pay").forEach {
            assertNull(policy.allowed(it))
        }
        assertNull(PaymentUrlPolicy("http://shop.example").allowed("//shop.example/pay"))
    }
}
