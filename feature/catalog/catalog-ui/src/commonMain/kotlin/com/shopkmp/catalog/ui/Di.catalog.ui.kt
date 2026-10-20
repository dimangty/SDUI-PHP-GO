package com.shopkmp.catalog.ui

import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleCatalogUi = module {
    viewModelOf(::CatalogViewModel)
}
