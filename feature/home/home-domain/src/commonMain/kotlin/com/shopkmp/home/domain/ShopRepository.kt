package com.shopkmp.home.domain

/** Platform-neutral shop operations consumed by presentation view models. */
interface ShopRepository {
    suspend fun getCategories(): List<String>?
    suspend fun getProducts(query: String = "", category: String? = null, page: Int = 0, size: Int = 20, sort: String? = null): Pair<List<ShopProduct>, Int>?
    suspend fun getPopularProducts(limit: Int): List<ShopProduct>?
    suspend fun getProduct(id: String): ShopProduct?
    suspend fun getCartItems(): Pair<List<ShopCartItem>, String?>?
    suspend fun getOrders(page: Int = 0, size: Int = 20): Pair<List<ShopOrder>, Int>?
    suspend fun addToCart(productId: String, quantity: Int): Boolean
    suspend fun setCartQuantity(productId: String, quantity: Int): Boolean
    suspend fun checkout(city: String, street: String, house: String, apartment: String, paymentMethod: ShopPaymentMethod): ShopCheckoutResult
}
