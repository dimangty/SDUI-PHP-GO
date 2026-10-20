package com.shopkmp.sdui.actions

import com.shopkmp.sdui.model.CatalogQuery
import com.shopkmp.sdui.model.CollectionQuery
import com.shopkmp.sdui.model.Command
import com.shopkmp.sdui.model.Destination
import com.shopkmp.sdui.model.FieldProps
import com.shopkmp.sdui.model.Input
import com.shopkmp.sdui.model.OrderStatus
import com.shopkmp.sdui.model.OrdersQuery
import com.shopkmp.sdui.model.PaymentMethod
import com.shopkmp.sdui.model.ProfileInput
import com.shopkmp.sdui.model.ScreenName
import com.shopkmp.sdui.model.codePointLength
import com.shopkmp.sdui.model.validateCommand
import com.shopkmp.sdui.model.validateDestination
import io.ktor.http.Url
import kotlinx.serialization.Serializable

object Forms {
    fun catalog(fields: Map<String, String>, categories: CollectionQuery = CollectionQuery()): Destination {
        val q = fields["catalog.q"]?.trim()?.takeIf { it.isNotEmpty() }
        val destination = Destination(ScreenName.catalog, catalog = CatalogQuery(
            q = q, categoryId = fields["catalog.categoryId"]?.takeIf { it.isNotBlank() },
            sort = if (q != null) null else fields["catalog.sort"]?.takeIf { it.isNotBlank() },
            priceMin = fields["catalog.priceMin"]?.takeIf { it.isNotBlank() },
            priceMax = fields["catalog.priceMax"]?.takeIf { it.isNotBlank() },
            size = fields["catalog.size"]?.toIntOrNull() ?: 20,
        ), categories = categories)
        validateDestination(destination)
        return destination
    }
    fun orders(fields: Map<String, String>): Destination {
        val status = fields["orders.status"]?.takeIf { it.isNotBlank() }?.let(OrderStatus::valueOf)
        return Destination(ScreenName.orders, orders = OrdersQuery(status, size = fields["orders.size"]?.toIntOrNull() ?: 20))
            .also(::validateDestination)
    }
    fun profile(fields: Map<String, String>): ProfileInput = ProfileInput(
        fields["profile.fullName"].orEmpty().trim(), fields["profile.phone"].orEmpty().trim(),
    ).also { validateCommand(Command.UpdateProfile(it)) }
    fun checkout(fields: Map<String, String>): CheckoutInput {
        fun address(key: String, max: Int, required: Boolean): String = fields["checkout.$key"].orEmpty().trim().also {
            require((!required || it.isNotBlank()) && it.codePointLength() <= max && it.none(Char::isISOControl))
        }
        return CheckoutInput(address("city", 255, true), address("street", 255, true), address("house", 64, true),
            address("apartment", 64, false), PaymentMethod.valueOf(fields["checkout.paymentMethod"].orEmpty()))
    }
    fun validateField(props: FieldProps, value: String): Boolean =
        value.codePointLength() <= props.maxLength && (props.maxUtf8Bytes?.let { value.encodeToByteArray().size <= it } != false) &&
            (!props.required || value.isNotBlank()) && (props.input != Input.Phone || Regex("\\+[1-9][0-9]{7,14}").matches(value)) &&
            (props.input != Input.Select || props.options.any { it.value == value })
}

@Serializable data class CheckoutInput(val city: String, val street: String, val house: String, val apartment: String, val paymentMethod: PaymentMethod)

/** Origins come from local application configuration, never a screen document. */
class PaymentUrlPolicy(businessOrigin: String, paymentOrigins: Set<String> = emptySet()) {
    private val origins = (paymentOrigins + businessOrigin).mapNotNull { origin(it) }.toSet()
    fun allowed(value: String): String? = value.takeIf {
        it.none { c -> c.isWhitespace() || c.isISOControl() || c == '\\' } && origin(it) in origins
    }
    private fun origin(value: String): String? = runCatching {
        require(value.startsWith("http://") || value.startsWith("https://"))
        val url = Url(value)
        require(url.protocol.name in setOf("http", "https") && url.host.isNotBlank() && url.user == null && url.password == null)
        "${url.protocol.name}://${url.host.lowercase()}:${url.port}"
    }.getOrNull()
}
