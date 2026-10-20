package com.shopkmp.navigation

import com.shopkmp.navigation.navigationservice.AppNavigator
import com.shopkmp.navigation.navigationservice.AppNavigatorImpl
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.bind
import org.koin.dsl.module

val moduleNavigation = module {
    singleOf(::AppNavigatorImpl) bind AppNavigator::class
}
