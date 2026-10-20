package com.shopkmp.sdui.model

import kotlinx.serialization.Serializable

@Serializable
data class ScreenDocument(
    val schemaVersion: Int,
    val screenId: ScreenName,
    val revision: String,
    val components: List<Node>,
    val actions: List<Action>,
    val state: WireState,
    val metadata: Metadata,
) {
    fun nodes(): List<Node> = components.flatMap { listOf(it) + it.descendants() }
    fun actionEnabled(id: String): Boolean = actions.any { it.id == id && it !is Action.Unknown }
}

@Serializable enum class ScreenName { home, catalog, product, cart, orders, order, profile, devices }
@Serializable data class WireState(
    val authenticated: Boolean,
    val fields: Map<String, String>,
    val cartItemsCount: Int,
    val pagination: Pagination? = null,
    val collections: Collections? = null,
)
@Serializable data class Pagination(val page: Int, val size: Int, val totalPages: Int, val totalItems: Int)
@Serializable data class Collections(val categories: Pagination? = null, val cart: Pagination? = null)
@Serializable data class Metadata(
    val title: String,
    val locale: String,
    val partial: Boolean,
    val accountId: String? = null,
    val warnings: List<String> = emptyList(),
)
@Serializable data class Destination(
    val screen: ScreenName,
    val id: String? = null,
    val catalog: CatalogQuery? = null,
    val orders: OrdersQuery? = null,
    val categories: CollectionQuery? = null,
    val cart: CollectionQuery? = null,
)
@Serializable data class CollectionQuery(val page: Int = 0, val size: Int = 50)
@Serializable data class CatalogQuery(
    val q: String? = null, val categoryId: String? = null, val sort: String? = null,
    val priceMin: String? = null, val priceMax: String? = null, val page: Int = 0, val size: Int = 20,
)
@Serializable data class OrdersQuery(val status: OrderStatus? = null, val page: Int = 0, val size: Int = 20)
@Serializable enum class OrderStatus { CREATED, PAID, ASSEMBLING, DELIVERING, COMPLETED, CANCELLED }
@Serializable enum class PaymentMethod { CARD, SBP, ON_DELIVERY }

sealed interface ScreenResult {
    data class Content(val screen: ScreenDocument) : ScreenResult
    data class Invalid(val reason: InvalidReason) : ScreenResult
}
enum class InvalidReason { UnsupportedVersion, Bounds, Malformed }

object Money {
    private val pattern = Regex("^(0|[1-9][0-9]{0,9})\\.[0-9]{2}$")
    fun valid(value: String) = pattern.matches(value)
    fun kopecks(value: String): Long {
        require(valid(value))
        return value.replace(".", "").toLong()
    }
    fun display(value: String): String { require(valid(value)); return "$value ₽" }
}

object MediaPolicy {
    private val pattern = Regex("^/(images/products|icons|uploads/users)/[A-Za-z0-9/_.-]+$")
    fun safePath(value: String) = pattern.matches(value) && ".." !in value && "//" !in value
    // UIKit has no authenticated avatar image state. Use its initials API, never public Coil for uploads.
    fun publicUrl(path: String?, businessOrigin: String): String? = path?.takeIf {
        safePath(it) && !it.startsWith("/uploads/users/")
    }?.let { businessOrigin.trimEnd('/') + it }
}

val fieldIds = setOf(
    "catalog.q", "catalog.categoryId", "catalog.sort", "catalog.priceMin", "catalog.priceMax", "catalog.page", "catalog.size",
    "orders.status", "orders.page", "orders.size", "product.quantity", "checkout.city", "checkout.street", "checkout.house",
    "checkout.apartment", "checkout.paymentMethod", "profile.fullName", "profile.phone",
)
fun String.codePointLength(): Int = length - indices.count { this[it].isLowSurrogate() && it > 0 && this[it - 1].isHighSurrogate() }
fun validUuid(value: String) = Regex("[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}").matches(value)
fun validDeviceId(value: String) = Regex("[A-Za-z0-9._-]{1,255}").matches(value) && value != "." && value != ".."
