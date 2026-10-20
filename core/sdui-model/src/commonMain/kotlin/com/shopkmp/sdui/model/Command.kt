package com.shopkmp.sdui.model

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonClassDiscriminator
import kotlinx.serialization.ExperimentalSerializationApi

@OptIn(ExperimentalSerializationApi::class)
@Serializable
@JsonClassDiscriminator("command")
sealed class Command {
    @Serializable @SerialName("AddToCart") data class AddToCart(val input: AddInput) : Command()
    @Serializable @SerialName("UpdateQuantity") data class UpdateQuantity(val input: QuantityInput) : Command()
    @Serializable @SerialName("RemoveFromCart") data class RemoveFromCart(val input: ProductInput) : Command()
    @Serializable @SerialName("CancelOrder") data class CancelOrder(val input: CancelInput) : Command()
    @Serializable @SerialName("UpdateProfile") data class UpdateProfile(val input: ProfileInput) : Command()
    @Serializable @SerialName("RevokeDevice") data class RevokeDevice(val input: RevokeInput) : Command()
}
@Serializable data class AddInput(val productId: String, val quantity: Int)
@Serializable data class QuantityInput(val productId: String, val quantity: Int)
@Serializable data class ProductInput(val productId: String)
@Serializable data class CancelInput(val orderId: String, val confirmed: Boolean)
@Serializable data class ProfileInput(val fullName: String, val phone: String)
@Serializable data class RevokeInput(val deviceId: String, val deviceKind: DeviceKind, val confirmed: Boolean)
@Serializable enum class CommandName { AddToCart, UpdateQuantity, RemoveFromCart, CancelOrder, UpdateProfile, RevokeDevice }
val Command.name: CommandName get() = when (this) {
    is Command.AddToCart -> CommandName.AddToCart
    is Command.UpdateQuantity -> CommandName.UpdateQuantity
    is Command.RemoveFromCart -> CommandName.RemoveFromCart
    is Command.CancelOrder -> CommandName.CancelOrder
    is Command.UpdateProfile -> CommandName.UpdateProfile
    is Command.RevokeDevice -> CommandName.RevokeDevice
}
@Serializable data class Acknowledgement(val schemaVersion: Int, val command: CommandName, val outcome: String, val refresh: Destination)
@Serializable data class ErrorEnvelope(val error: WireError)
@Serializable data class WireError(val code: String, val message: String, val traceId: String = "", val details: Map<String, String> = emptyMap())

fun validateCommand(command: Command) {
    when (command) {
        is Command.AddToCart -> require(validUuid(command.input.productId) && command.input.quantity in 1..1_000_000)
        is Command.UpdateQuantity -> require(validUuid(command.input.productId) && command.input.quantity in 0..1_000_000)
        is Command.RemoveFromCart -> require(validUuid(command.input.productId))
        is Command.CancelOrder -> require(validUuid(command.input.orderId) && command.input.confirmed)
        is Command.UpdateProfile -> with(command.input) {
            require(fullName == fullName.trim() && fullName.codePointLength() in 1..255 && fullName.none { it.isISOControl() })
            require(Regex("\\+[1-9][0-9]{7,14}").matches(phone))
        }
        is Command.RevokeDevice -> require(validDeviceId(command.input.deviceId) && command.input.confirmed)
    }
}
