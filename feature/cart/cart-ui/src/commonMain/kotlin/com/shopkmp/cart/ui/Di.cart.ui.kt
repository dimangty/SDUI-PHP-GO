package com.shopkmp.cart.ui

import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleCartUi = module {
    viewModelOf(::CartViewModel)
}
