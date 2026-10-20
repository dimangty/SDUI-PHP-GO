package com.shopkmp.home.domain

data class ShopProduct(
    val id: String,
    val name: String,
    val category: String,
    val description: String,
    val priceRub: String,
    val stock: Int,
    val rating: Double,
    val reviews: Int,
    val imageUrl: String? = null,
)

data class ShopCartItem(
    val product: ShopProduct,
    val quantity: Int,
    val serverTotalRub: String? = null,
    val isAvailable: Boolean = true,
) {
    val totalRub: String get() {
        if (serverTotalRub != null) return serverTotalRub
        return Money.parseOrNull(product.priceRub)?.times(quantity)?.toString() ?: "—"
    }
}

enum class ShopPaymentMethod {
    Card,
    Sbp,
    OnDelivery,
}

enum class ShopOrderStatus {
    Created,
    Paid,
    Assembling,
    Delivering,
    Completed,
    Cancelled,
}

data class ShopOrder(
    val number: Int,
    val status: ShopOrderStatus,
    val totalRub: String,
    val deliveryAddress: String,
    val paymentMethod: ShopPaymentMethod,
)

sealed class ShopCheckoutResult {
    data class Success(
        val order: ShopOrder,
        val paymentUrl: String? = null,
        val paymentInitiationFailed: Boolean = false,
    ) : ShopCheckoutResult()
    data class Error(val errorCode: String?, val details: Map<String, String>?) : ShopCheckoutResult()
}
