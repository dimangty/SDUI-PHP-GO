package com.shopkmp.cart.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.common.device.DeviceService
import com.shopkmp.home.domain.CheckoutUseCase
import com.shopkmp.home.domain.ShopCartItem
import com.shopkmp.home.domain.ShopCheckoutResult
import com.shopkmp.home.domain.ShopPaymentMethod
import com.shopkmp.home.domain.ShopRepository
import com.shopkmp.uikit.component.shop.EpsCartContentState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class CartState(
    val content: EpsCartContentState? = null,
    val error: String? = null,
)

class CartViewModel(
    private val repository: ShopRepository,
    private val checkoutUseCase: CheckoutUseCase,
    private val deviceService: DeviceService,
) : ViewModel() {
    private val mutableState = MutableStateFlow(CartState())
    val stateFlow = mutableState.asStateFlow()
    private val builder = CartStateBuilder()
    private var items: List<ShopCartItem> = emptyList()
    private var total: String? = null
    private var city = ""
    private var street = ""
    private var house = ""
    private var apartment = ""
    private var paymentMethod = ShopPaymentMethod.Card
    private var statusMessage: String? = null

    fun load() {
        viewModelScope.launch {
            val result = repository.getCartItems()
            if (result == null) {
                mutableState.value = CartState(error = "Ошибка загрузки")
                return@launch
            }
            items = result.first
            total = result.second
            publish()
        }
    }

    fun retry() = load()

    private fun setQuantity(productId: String, quantity: Int) {
        viewModelScope.launch {
            statusMessage = if (repository.setCartQuantity(productId, quantity)) {
                null
            } else {
                "Не удалось обновить корзину"
            }
            load()
        }
    }

    private fun checkout() {
        if (city.isBlank() || street.isBlank() || house.isBlank()) {
            statusMessage = "Заполните город, улицу и дом"
            publish()
            return
        }
        viewModelScope.launch {
            when (val result = checkoutUseCase.execute(city, street, house, apartment, paymentMethod)) {
                is ShopCheckoutResult.Success -> {
                    result.paymentUrl?.let(deviceService::openUrl)
                    statusMessage = when {
                        result.paymentInitiationFailed ->
                            "Заказ №${result.order.number} создан, но оплату открыть не удалось"
                        result.paymentUrl != null ->
                            "Заказ №${result.order.number} создан, страница оплаты открыта"
                        else -> "Заказ №${result.order.number} создан"
                    }
                }
                is ShopCheckoutResult.Error -> {
                    statusMessage = when (result.errorCode) {
                        "OUT_OF_STOCK" -> "Недостаточно товара на складе"
                        "ADDRESS_NOT_FOUND" -> "Адрес доставки не найден"
                        "CART_IS_EMPTY" -> "Корзина пуста"
                        else -> "Не удалось оформить заказ"
                    }
                }
            }
            load()
        }
    }

    private fun publish() {
        mutableState.value = CartState(
            content = builder.build(
                items = items,
                total = total,
                city = city,
                street = street,
                house = house,
                apartment = apartment,
                paymentMethod = paymentMethod,
                statusMessage = statusMessage,
                onCity = { city = it; statusMessage = null; publish() },
                onStreet = { street = it; statusMessage = null; publish() },
                onHouse = { house = it; statusMessage = null; publish() },
                onApartment = { apartment = it; statusMessage = null; publish() },
                onPaymentMethod = { paymentMethod = it; statusMessage = null; publish() },
                onQuantity = ::setQuantity,
                onCheckout = ::checkout,
            ),
        )
    }
}
