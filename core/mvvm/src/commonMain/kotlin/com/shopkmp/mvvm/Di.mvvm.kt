package com.shopkmp.mvvm

import com.shopkmp.mvvm.common.connectivity.ConnectivityService
import com.shopkmp.mvvm.common.connectivity.ConnectivityServiceImpl
import com.shopkmp.mvvm.common.singleclick.SingleClickService
import com.shopkmp.mvvm.common.singleclick.SingleClickServiceImpl
import com.shopkmp.mvvm.common.topbar.TopBarStateManager
import org.koin.core.module.dsl.factoryOf
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.bind
import org.koin.dsl.module
import com.shopkmp.common.device.DeviceService
import com.shopkmp.common.resource.StringsService
import com.shopkmp.navigation.navigationservice.AppNavigator
import org.koin.mp.KoinPlatform.getKoin

data class BaseViewModelDependencies(
    val singleClickService: SingleClickService,
    val connectivityService: ConnectivityService,
    val stringsService: StringsService,
    val appNavigator: AppNavigator,
    val deviceService: DeviceService,
)

fun resolveBaseViewModelDependencies(): BaseViewModelDependencies = getKoin().get()

val moduleMvvm = module {
	factoryOf(::TopBarStateManager)
	singleOf(::SingleClickServiceImpl) bind SingleClickService::class
	singleOf(::ConnectivityServiceImpl) bind ConnectivityService::class
	single { BaseViewModelDependencies(get(), get(), get(), get(), get()) }
}
