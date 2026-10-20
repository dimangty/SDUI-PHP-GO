package com.shopkmp.orders.ui.history

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.home.domain.ShopOrder
import com.shopkmp.home.domain.ShopRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

class OrderHistoryViewModel(
    private val repository: ShopRepository,
) : ViewModel() {
    private val mutableState = MutableStateFlow(OrderHistoryState())
    val stateFlow = mutableState.asStateFlow()
    private val builder = OrderHistoryStateBuilder()
    private var orders: List<ShopOrder> = emptyList()
    private var page = 0
    private var totalPages = 0
    private var onBack: () -> Unit = {}

    fun load(onBack: () -> Unit) {
        this.onBack = onBack
        viewModelScope.launch {
            val result = repository.getOrders()
            orders = result?.first.orEmpty()
            page = 0
            totalPages = result?.second ?: 0
            publish()
        }
    }

    fun loadMore() {
        if (page >= totalPages - 1) return
        viewModelScope.launch {
            val result = repository.getOrders(page = page + 1) ?: return@launch
            page++
            totalPages = result.second
            orders += result.first
            publish()
        }
    }

    private fun publish() {
        mutableState.value = OrderHistoryState(
            contentState = builder.build(orders, onBack),
        )
    }
}
