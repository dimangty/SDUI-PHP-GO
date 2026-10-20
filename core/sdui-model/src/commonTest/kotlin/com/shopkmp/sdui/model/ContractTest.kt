package com.shopkmp.sdui.model

import kotlin.test.*
import kotlinx.serialization.json.*

class ContractTest {
    private val decoder = ScreenDecoder()

    @Test fun allThirteenPublishedDocumentsDecodeWithoutLosingDetailOrPaging() {
        assertEquals(13, contractFixtures.size)
        contractFixtures.forEach { (name, body) ->
            val screen = assertIs<ScreenResult.Content>(decoder.decode(body), name).screen
            assertTrue(screen.nodes().none { it is Node.Unknown }, name)
            assertTrue(screen.actions.none { it is Action.Unknown }, name)
        }
        val detail = content("product-long-description")
        val text = detail.nodes().filterIsInstance<Node.Text>().filter { it.id.startsWith("product.description.") }
            .joinToString("") { it.props.text }
        assertEquals("Ж🌟<>&".repeat(2000), text)
        assertEquals(1, content("cart-continuation").state.collections?.cart?.page)
        assertEquals("50.01", content("product").nodes().filterIsInstance<Node.Price>().first().props.amount)
    }

    @Test fun unknownSubtreesAreSkippedAndUnknownActionsAreNeverEnabled() {
        val body = contractFixtures.getValue("product").replace("\"Image\"", "\"FutureImage\"")
            .replace("\"AddToCart\"", "\"FuturePurchase\"")
        val screen = assertIs<ScreenResult.Content>(decoder.decode(body)).screen
        assertTrue(screen.nodes().any { it is Node.Unknown })
        assertIs<Action.Unknown>(screen.actions.last())
        assertFalse(screen.actionEnabled("product.add"))
    }

    @Test fun invalidVersionsReferencesIdsAndBoundsFallBack() {
        val body = contractFixtures.getValue("product")
        listOf(
            body.replace("\"schemaVersion\": 1", "\"schemaVersion\": 2"),
            body.replace("\"product.add\"", "\"missing\"", false).replace("\"id\": \"missing\"", "\"id\": \"other\""),
            body.replace("\"product.stock\"", "\"product.title\""),
            body.replace("\"50.01\"", "50.01"),
            " ".repeat(1_048_577),
            "[".repeat(10000) + "]".repeat(10000),
        ).forEach { assertIs<ScreenResult.Invalid>(decoder.decode(it)) }
    }

    @Test fun unsafeMediaIsNeverResolvedAndMoneyNeverUsesFloatingPoint() {
        assertNull(MediaPolicy.publicUrl("/uploads/users/avatar.png", "https://shop.test"))
        assertNull(MediaPolicy.publicUrl("/images/products/../secret", "https://shop.test"))
        assertNull(MediaPolicy.publicUrl("https://evil.test/a", "https://shop.test"))
        assertEquals("https://shop.test/images/products/a.png", MediaPolicy.publicUrl("/images/products/a.png", "https://shop.test"))
        assertEquals(999999999999L, Money.kopecks("9999999999.99"))
    }

    @Test fun hardLimitsIncludeUnknownSubtreesAndAllowExactBoundaries() {
        fun text(id: Int) = """{"id":"n$id","type":"Text","props":{"text":"ok","style":"Body"}}"""
        fun envelope(nodes: String, actions: String = "") = """{"schemaVersion":1,"screenId":"home","revision":"${"a".repeat(64)}","components":[$nodes],"actions":[$actions],"state":{"authenticated":false,"fields":{},"cartItemsCount":0},"metadata":{"title":"Test","locale":"ru-RU","partial":false}}"""
        assertIs<ScreenResult.Content>(decoder.decode(envelope((1..500).joinToString(",") { text(it) })))
        assertIs<ScreenResult.Invalid>(decoder.decode(envelope((1..501).joinToString(",") { text(it) })))
        fun nested(depth: Int): String = (1 until depth).fold(text(0)) { child, i ->
            """{"id":"parent$i","type":"FutureContainer","props":{},"children":[$child]}"""
        }
        assertIs<ScreenResult.Content>(decoder.decode(envelope(nested(16))))
        assertIs<ScreenResult.Invalid>(decoder.decode(envelope(nested(17))))
        assertIs<ScreenResult.Invalid>(decoder.decode(envelope(text(0), (1..501).joinToString(",") { """{"id":"a$it","type":"Future"}""" })))
    }

    @Test fun duplicateKeysAndChildrenOnLeafCannotBecomeValidScreens() {
        val body = contractFixtures.getValue("product")
        assertIs<ScreenResult.Invalid>(decoder.decode(body.replace("\"schemaVersion\": 1", "\"schemaVersion\": 1, \"schemaVersion\": 1")))
        assertIs<ScreenResult.Invalid>(decoder.decode(body.replace("\"type\": \"Price\",", "\"type\": \"Price\", \"children\": [],")))
    }

    @Test fun duplicateSelectValuesAndCategoryIdsAreRejectedBeforeLazyRendering() {
        fun duplicate(nodeId: String, property: String): String {
            fun rewrite(element: JsonElement): JsonElement = when (element) {
                is JsonArray -> JsonArray(element.map(::rewrite))
                is JsonObject -> if (element["id"]?.jsonPrimitive?.content == nodeId) {
                    val props = element.getValue("props").jsonObject
                    val first = props.getValue(property).jsonArray.first()
                    JsonObject(element + ("props" to JsonObject(props + (property to JsonArray(listOf(first, first))))))
                } else JsonObject(element.mapValues { rewrite(it.value) })
                else -> element
            }
            return rewrite(Json.parseToJsonElement(contractFixtures.getValue("catalog"))).toString()
        }
        assertIs<ScreenResult.Invalid>(decoder.decode(duplicate("catalog.sort", "options")))
        assertIs<ScreenResult.Invalid>(decoder.decode(duplicate("catalog.categories", "items")))
    }

    private fun content(name: String) = assertIs<ScreenResult.Content>(decoder.decode(contractFixtures.getValue(name))).screen
}
