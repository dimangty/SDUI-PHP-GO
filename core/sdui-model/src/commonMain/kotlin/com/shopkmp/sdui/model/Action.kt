package com.shopkmp.sdui.model

import kotlinx.serialization.DeserializationStrategy
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonContentPolymorphicSerializer
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

@Serializable(with = ActionSerializer::class)
sealed interface Action {
    val id: String
    @Serializable data class Route(override val id: String, val type: RouteKind, val destination: Destination) : Action
    @Serializable data class Product(override val id: String, val productId: String) : Action
    @Serializable data class Order(override val id: String, val orderId: String) : Action
    @Serializable data class UpdateField(override val id: String, val fieldId: String) : Action
    @Serializable data class SubmitForm(override val id: String, val formId: FormId) : Action
    @Serializable data class AddToCart(override val id: String, val productId: String, val quantityField: String? = null) : Action
    @Serializable data class Cart(override val id: String, val type: CartKind, val productId: String) : Action
    @Serializable data class CancelOrder(override val id: String, val orderId: String, val confirmation: Confirmation) : Action
    @Serializable data class RevokeDevice(override val id: String, val deviceId: String, val deviceKind: DeviceKind, val confirmation: Confirmation) : Action
    @Serializable data class Checkout(override val id: String, val formId: FormId) : Action
    @Serializable data class Native(override val id: String, val type: NativeKind) : Action
    @Serializable data class Unknown(override val id: String) : Action
}
@Serializable enum class RouteKind { Navigate, Refresh, Retry }
@Serializable enum class CartKind { RemoveFromCart, UpdateQuantity }
@Serializable enum class NativeKind { EditProfile, Logout, ChangePin, ManageBiometric, Login }
@Serializable enum class DeviceKind { pin, biometric }
@Serializable data class Confirmation(val title: String, val message: String, val confirmLabel: String)

object ActionSerializer : JsonContentPolymorphicSerializer<Action>(Action::class) {
    override fun selectDeserializer(element: JsonElement): DeserializationStrategy<Action> = when (element.jsonObject["type"]?.jsonPrimitive?.content) {
        "Navigate", "Refresh", "Retry" -> Action.Route.serializer()
        "OpenProduct" -> Action.Product.serializer()
        "OpenOrder" -> Action.Order.serializer()
        "UpdateField" -> Action.UpdateField.serializer()
        "SubmitForm" -> Action.SubmitForm.serializer()
        "AddToCart" -> Action.AddToCart.serializer()
        "RemoveFromCart", "UpdateQuantity" -> Action.Cart.serializer()
        "CancelOrder" -> Action.CancelOrder.serializer()
        "RevokeDevice" -> Action.RevokeDevice.serializer()
        "Checkout" -> Action.Checkout.serializer()
        "EditProfile", "Logout", "ChangePin", "ManageBiometric", "Login" -> Action.Native.serializer()
        else -> Action.Unknown.serializer()
    }
}
