package com.shopkmp.common

import com.shopkmp.common.resource.StringsService
import com.shopkmp.common.resource.StringsServiceImpl
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.bind
import org.koin.dsl.module

val moduleCommon = module {
    singleOf(::StringsServiceImpl) bind StringsService::class
}
