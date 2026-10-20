package com.shopkmp.orders.ui

import com.shopkmp.orders.ui.history.OrderHistoryViewModel
import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleOrdersUi = module {
    viewModelOf(::OrderHistoryViewModel)
}
