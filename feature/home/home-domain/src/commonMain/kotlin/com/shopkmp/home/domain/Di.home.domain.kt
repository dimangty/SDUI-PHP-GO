package com.shopkmp.home.domain

import org.koin.dsl.module

val moduleHomeDomain = module {
    factory { CheckoutUseCase(get()) }
}
