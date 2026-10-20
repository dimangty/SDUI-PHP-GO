package com.shopkmp.network.shop

import kotlinx.serialization.Serializable

/**
 * ShopKMP API money contract.
 *
 * Every monetary field is a decimal JSON string (for example, `"4990.00"`),
 * never a floating-point JSON number. Keeping the wire value as [String]
 * prevents precision loss on every KMP target; conversion for presentation is
 * performed only in the domain/UI mapping layer.
 */

@Serializable
data class CategoryDto(
    val id: String,
    val name: String,
    val parentId: String?,
    val iconUrl: String,
    val sortOrder: Int,
)

@Serializable
data class ProductSummaryDto(
    val id: String,
    val categoryId: String,
    val name: String,
    val description: String,
    val price: String,
    val discountPrice: String?,
    val stock: Int,
    val rating: Double?,
    val imageUrls: List<String>,
    val isActive: Boolean,
    val reviewsCount: Int,
)

@Serializable
data class ProductDto(
    val id: String,
    val categoryId: String,
    val name: String,
    val description: String,
    val price: String,
    val discountPrice: String?,
    val stock: Int,
    val rating: Double?,
    val imageUrls: List<String>,
    val isActive: Boolean,
    val reviewsCount: Int,
)

@Serializable
data class PageResponse<T>(
    val items: List<T>,
    val page: Int,
    val size: Int,
    val totalPages: Int,
    val totalItems: Int,
)

@Serializable
data class CartDto(
    val id: String,
    val userId: String,
    val items: List<CartItemDto>,
    val totalAmount: String,
    val updatedAt: String,
)

@Serializable
data class CartItemDto(
    val productId: String,
    val productName: String,
    val quantity: Int,
    val priceSnapshot: String,
    val imageUrl: String?,
    val isAvailable: Boolean,
    val stock: Int,
)

@Serializable
data class AddCartItemRequest(val productId: String, val quantity: Int)

@Serializable
data class UpdateCartItemRequest(val quantity: Int)

@Serializable
data class UpsertAddressRequest(
    val city: String,
    val street: String,
    val house: String,
    val apartment: String = "",
    val comment: String? = null,
)

@Serializable
data class AddressDto(
    val id: String,
    val userId: String,
    val city: String,
    val street: String,
    val house: String,
    val apartment: String,
    val comment: String?,
)

@Serializable
data class CreateOrderRequest(
    val deliveryAddressId: String,
    val paymentMethod: String,
    val comment: String? = null,
)

@Serializable
data class PaymentConfirmResponse(
    val paymentId: String,
    val status: String,
    val paymentUrl: String?,
)

@Serializable
data class OrderDto(
    val id: String,
    val number: Int,
    val userId: String,
    val status: String,
    val items: List<OrderItemDto>,
    val totalAmount: String,
    val deliveryAddressId: String,
    val paymentId: String?,
    val createdAt: String,
    val comment: String?,
    val paymentMethod: String,
    val deliveryAddress: String,
)

@Serializable
data class OrderItemDto(
    val productId: String,
    val productName: String,
    val quantity: Int,
    val priceSnapshot: String,
)
