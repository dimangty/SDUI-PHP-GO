package com.shopkmp.product.ui

import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleProductUi = module {
    viewModelOf(::ProductDetailsViewModel)
}
