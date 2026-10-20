package com.shopkmp.product.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.home.domain.ShopProduct
import com.shopkmp.home.domain.ShopRepository
import com.shopkmp.uikit.component.shop.EpsProductDetailsContentState
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class ProductDetailsState(
    val content: EpsProductDetailsContentState? = null,
    val error: String? = null,
)

class ProductDetailsViewModel(
    private val repository: ShopRepository,
) : ViewModel() {
    private val mutableState = MutableStateFlow(ProductDetailsState())
    val stateFlow = mutableState.asStateFlow()
    private val builder = ProductDetailsStateBuilder()
    private var product: ShopProduct? = null
    private var quantity = 1
    private var onBack: () -> Unit = {}
    private var onAdded: () -> Unit = {}
    private var loadJob: Job? = null

    fun load(productId: String, onBack: () -> Unit, onAdded: () -> Unit) {
        this.onBack = onBack
        this.onAdded = onAdded
        loadJob?.cancel()
        loadJob = viewModelScope.launch {
            product = repository.getProduct(productId)
            quantity = 1
            publish()
        }
    }

    private fun decrease() {
        quantity = (quantity - 1).coerceAtLeast(1)
        publish()
    }

    private fun increase() {
        val max = product?.stock ?: quantity
        quantity = (quantity + 1).coerceAtMost(max)
        publish()
    }

    private fun add() {
        val current = product ?: return
        viewModelScope.launch {
            if (repository.addToCart(current.id, quantity)) {
                onAdded()
            } else {
                mutableState.value = mutableState.value.copy(error = "Не удалось добавить товар в корзину")
            }
        }
    }

    private fun publish() {
        val current = product
        mutableState.value = if (current == null) {
            ProductDetailsState(error = "Товар не найден")
        } else {
            ProductDetailsState(
                content = builder.build(
                    product = current,
                    quantity = quantity,
                    onBack = onBack,
                    onDecrease = ::decrease,
                    onIncrease = ::increase,
                    onAdd = ::add,
                ),
            )
        }
    }
}
