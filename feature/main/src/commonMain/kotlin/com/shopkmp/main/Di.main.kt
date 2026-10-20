package com.shopkmp.main

import com.shopkmp.home.domain.moduleHomeDomain
import com.shopkmp.devices.ui.moduleDevicesUi
import com.shopkmp.main.container.MainContainerScreen
import com.shopkmp.main.container.MainContainerViewModel
import com.shopkmp.orders.ui.moduleOrdersUi
import com.shopkmp.cart.ui.moduleCartUi
import com.shopkmp.catalog.ui.moduleCatalogUi
import com.shopkmp.home.ui.moduleHomeUi
import com.shopkmp.product.ui.moduleProductUi
import com.shopkmp.profile.ui.moduleProfileUi
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.profile.domain.moduleProfileDomain
import org.koin.core.annotation.KoinExperimentalAPI
import org.koin.core.module.dsl.viewModelOf
import org.koin.core.module.dsl.viewModel
import org.koin.dsl.module
import org.koin.dsl.navigation3.navigation

@OptIn(KoinExperimentalAPI::class)
val moduleMain = module {
    viewModelOf(::MainViewModel)
    viewModelOf(::MainContainerViewModel)
    factory<com.shopkmp.main.sdui.NativeSduiAdapter> { com.shopkmp.main.sdui.ShopNativeSduiAdapter(get(), get(), get(), get(), get(), get(), get()) }
    viewModel { com.shopkmp.main.sdui.SduiViewModel(get(), get()) }
    navigation<NavigationAction.NavigateToMain> { MainContainerScreen() }

    includes(
        com.shopkmp.sdui.network.moduleSduiNetwork,
        com.shopkmp.auth.ui.moduleAuth,
        moduleCartUi,
        moduleCatalogUi,
        moduleDevicesUi,
        moduleHomeDomain,
        moduleHomeUi,
        moduleOrdersUi,
        moduleProductUi,
        moduleProfileUi,
        moduleProfileDomain,
    )
}
