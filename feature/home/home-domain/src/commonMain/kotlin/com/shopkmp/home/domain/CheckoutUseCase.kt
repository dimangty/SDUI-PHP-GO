package com.shopkmp.home.domain

class CheckoutUseCase(
    private val shopRepository: ShopRepository,
) {
    suspend fun execute(
        city: String,
        street: String,
        house: String,
        apartment: String,
        paymentMethod: ShopPaymentMethod,
    ): ShopCheckoutResult {
        return shopRepository.checkout(city, street, house, apartment, paymentMethod)
    }
}
