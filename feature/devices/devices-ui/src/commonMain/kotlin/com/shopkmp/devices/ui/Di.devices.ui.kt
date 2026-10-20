package com.shopkmp.devices.ui

import com.shopkmp.devices.ui.list.DeviceListViewModel
import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleDevicesUi = module {
    viewModelOf(::DeviceListViewModel)
}
