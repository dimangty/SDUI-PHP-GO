package com.shopkmp.di

import com.shopkmp.network.api.ApiHttpClient
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.module

internal actual val platformModule = module {
    singleOf(::ApiHttpClient)
}
