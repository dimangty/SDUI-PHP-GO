package com.shopkmp.home.ui

import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleHomeUi = module {
    viewModelOf(::HomeViewModel)
}
