package com.shopkmp.sdui.model

import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.decodeFromJsonElement
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

/** Raw JSON exists only at this bounded protocol boundary, never in a render state. */
class ScreenDecoder {
    private val json = Json { ignoreUnknownKeys = true }
    fun decode(body: String): ScreenResult {
        if (body.encodeToByteArray().size > 1_048_576 || !structurallyBounded(body)) return ScreenResult.Invalid(InvalidReason.Bounds)
        return try {
            val root = json.parseToJsonElement(body).jsonObject
            if (root["schemaVersion"]?.jsonPrimitive?.int != 1) return ScreenResult.Invalid(InvalidReason.UnsupportedVersion)
            validateRaw(root)
            val screen = json.decodeFromJsonElement<ScreenDocument>(root)
            validate(screen)
            ScreenResult.Content(screen)
        } catch (_: IllegalArgumentException) {
            ScreenResult.Invalid(InvalidReason.Malformed)
        } catch (_: SerializationException) {
            ScreenResult.Invalid(InvalidReason.Malformed)
        } catch (_: IllegalStateException) {
            ScreenResult.Invalid(InvalidReason.Malformed)
        }
    }

    private fun validateRaw(root: JsonObject) {
        fun strings(e: JsonElement) {
            when (e) {
                is JsonObject -> e.forEach { (key, value) -> require(key.codePointLength() <= 8192); strings(value) }
                is JsonArray -> e.forEach(::strings)
                is JsonPrimitive -> if (e.isString) require(e.content.codePointLength() <= 8192)
            }
        }
        strings(root)
        var count = 0
        val ids = mutableSetOf<String>()
        fun visit(node: JsonElement, depth: Int) {
            require(++count <= 500 && depth <= 16)
            val obj = node.jsonObject
            val id = obj.getValue("id").jsonPrimitive.content
            require(validId(id) && ids.add(id))
            require(obj.getValue("type").jsonPrimitive.isString && obj.getValue("props") is JsonObject)
            val type = obj.getValue("type").jsonPrimitive.content
            val knownLeaf = type in setOf("Text", "Image", "Icon", "Button", "TextField", "SearchField", "FormField", "ProductCard", "CategoryList", "Price", "QuantityStepper", "CartItem", "OrderCard", "ProfileHeader", "Empty", "Loading", "Error", "Spacer", "Divider")
            require(!knownLeaf || "children" !in obj)
            obj["children"]?.jsonArray?.forEach { visit(it, depth + 1) }
        }
        root.getValue("components").jsonArray.forEach { visit(it, 1) }
        val actions = root.getValue("actions").jsonArray
        require(actions.size <= 500)
        val actionIds = mutableSetOf<String>()
        actions.forEach { require(validId(it.jsonObject.getValue("id").jsonPrimitive.content) && actionIds.add(it.jsonObject.getValue("id").jsonPrimitive.content)) }
    }

    private fun validate(screen: ScreenDocument) {
        require(Regex("[a-f0-9]{64}").matches(screen.revision))
        require(screen.metadata.locale == "ru-RU" && screen.metadata.warnings.size <= 3)
        require(screen.state.cartItemsCount >= 0 && screen.state.fields.keys.all { it in fieldIds })
        require(screen.state.authenticated == (screen.metadata.accountId != null))
        screen.metadata.accountId?.let { require(validUuid(it)) }
        screen.state.pagination?.let(::validatePagination)
        screen.state.collections?.cart?.let(::validatePagination)
        screen.state.collections?.categories?.let { validatePagination(it); require(it.size <= 50) }
        val actions = screen.actions.associateBy { it.id }
        fun ref(id: String?) { if (id != null) require(id in actions) }
        fun field(id: String) { require(id in fieldIds && id in screen.state.fields) }
        screen.actions.forEach { action ->
            when (action) {
                is Action.Route -> validateDestination(action.destination)
                is Action.Product -> require(validUuid(action.productId))
                is Action.Order -> require(validUuid(action.orderId))
                is Action.AddToCart -> { require(validUuid(action.productId)); action.quantityField?.let { require(it == "product.quantity"); field(it) } }
                is Action.Cart -> require(validUuid(action.productId))
                is Action.CancelOrder -> require(validUuid(action.orderId))
                is Action.RevokeDevice -> require(validDeviceId(action.deviceId))
                is Action.UpdateField -> field(action.fieldId)
                is Action.SubmitForm -> require(action.formId != FormId.checkout)
                is Action.Checkout -> require(action.formId == FormId.checkout)
                else -> Unit
            }
        }
        screen.nodes().forEach { node ->
            when (node) {
                is Node.Button -> ref(node.props.actionId)
                is Node.Field -> with(node.props) {
                    field(fieldId); ref(changeAction)
                    require(maxLength in 1..2000 && options.size <= 500)
                    require(options.map { it.value }.toSet().size == options.size)
                    require(screen.state.fields.getValue(fieldId).codePointLength() <= maxLength)
                    require(if (fieldId == "catalog.q") maxUtf8Bytes == 200 && screen.state.fields.getValue(fieldId).encodeToByteArray().size <= 200 else maxUtf8Bytes == null)
                    val action = actions[changeAction]
                    require(action is Action.Unknown || action is Action.UpdateField && action.fieldId == fieldId)
                }
                is Node.ProductCard -> with(node.props) {
                    require(validUuid(productId) && Money.valid(price) && (discountPrice == null || Money.valid(discountPrice)))
                    require(stock >= 0 && reviewsCount >= 0 && (rating == null || rating.isFinite() && rating in 1.0..5.0))
                    require(description.codePointLength() <= 256); ref(openAction); ref(addAction)
                }
                is Node.ProductGrid -> require(node.props.columns == 2 && node.children.all { it is Node.ProductCard || it is Node.Unknown })
                is Node.CategoryList -> {
                    require(node.props.items.size <= 50)
                    require(node.props.items.map { it.id }.toSet().size == node.props.items.size)
                    node.props.items.forEach { require(validUuid(it.id)); ref(it.actionId) }
                }
                is Node.Price -> require(Money.valid(node.props.amount) && node.props.currency == "RUB")
                is Node.QuantityStepper -> with(node.props) {
                    field(fieldId); ref(actionId); require(min in 0..1_000_000 && max in min..1_000_000 && value in 0..1_000_000)
                    require(screen.state.fields.getValue(fieldId).toIntOrNull() in min..max)
                }
                is Node.CartItem -> with(node.props) {
                    require(validUuid(productId) && Money.valid(price) && quantity in 1..1_000_000 && stock >= 0)
                    ref(openAction); ref(quantityAction); ref(removeAction)
                }
                is Node.OrderCard -> with(node.props) { require(validUuid(orderId) && number >= 1 && Money.valid(total)); ref(openAction) }
                is Node.Status -> ref(node.props.retryAction)
                is Node.Spacer -> require(node.props.size != Space.none)
                is Node.Form -> ref(node.props.submitAction)
                else -> Unit
            }
        }
    }
}

private fun validId(value: String) = Regex("[A-Za-z0-9][A-Za-z0-9._:-]{0,127}").matches(value)
private fun validatePagination(p: Pagination) { require(p.page >= 0 && p.size in 1..100 && p.totalPages >= 0 && p.totalItems >= 0) }

fun validateDestination(d: Destination) {
    require(if (d.screen == ScreenName.product || d.screen == ScreenName.order) d.id?.let(::validUuid) == true else d.id == null)
    require(d.catalog == null || d.screen == ScreenName.catalog)
    require(d.orders == null || d.screen == ScreenName.orders)
    require(d.categories == null || d.screen == ScreenName.home || d.screen == ScreenName.catalog)
    require(d.cart == null || d.screen == ScreenName.cart)
    d.catalog?.let { q ->
        require(q.page in 0..1_000_000 && q.size in 1..100)
        require(q.q == null || q.q.encodeToByteArray().size <= 200 && q.sort == null && (q.page.toLong() + 1) * q.size <= 10000)
        require(q.categoryId == null || validUuid(q.categoryId))
        require(q.sort == null || q.sort in setOf("price_asc", "price_desc", "rating", "new"))
        require(q.priceMin == null || Money.valid(q.priceMin)); require(q.priceMax == null || Money.valid(q.priceMax))
        require(q.priceMin == null || q.priceMax == null || Money.kopecks(q.priceMin) <= Money.kopecks(q.priceMax))
    }
    d.orders?.let { require(it.page in 0..1_000_000 && it.size in 1..100) }
    d.categories?.let { require(it.page in 0..1_000_000 && it.size in 1..50) }
    d.cart?.let { require(it.page in 0..1_000_000 && it.size in 1..100) }
}

/** Reject pathological nesting before invoking the recursive serialization parser. */
private fun structurallyBounded(body: String): Boolean {
    val objects = mutableListOf<MutableSet<String>?>()
    var inString = false
    var escaped = false
    var stringStart = 0
    body.forEachIndexed { i, c ->
        if (inString) {
            if (escaped) escaped = false else if (c == '\\') escaped = true else if (c == '"') {
                inString = false
                var next = i + 1
                while (next < body.length && body[next].isWhitespace()) next++
                if (next < body.length && body[next] == ':') {
                    val key = runCatching { Json.decodeFromString<String>(body.substring(stringStart, i + 1)) }.getOrNull() ?: return false
                    if (objects.lastOrNull()?.add(key) != true) return false
                }
            }
        } else when (c) {
            '"' -> { inString = true; stringStart = i }
            '{', '[' -> { objects.add(if (c == '{') mutableSetOf() else null); if (objects.size > 80) return false }
            '}', ']' -> { if (objects.isEmpty()) return false; objects.removeAt(objects.lastIndex) }
        }
    }
    return !inString && objects.isEmpty()
}
