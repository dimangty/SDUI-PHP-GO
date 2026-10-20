package com.shopkmp

import com.shopkmp.common.moduleCommon
import com.shopkmp.di.moduleNetwork
import com.shopkmp.home.data.moduleHomeData
import com.shopkmp.home.domain.moduleHomeDomain
import com.shopkmp.main.moduleMain
import com.shopkmp.mvvm.moduleMvvm
import com.shopkmp.navigation.moduleNavigation
import com.shopkmp.profile.data.moduleProfileData
import com.shopkmp.profile.domain.moduleProfileDomain
import com.shopkmp.settings.moduleSettings
import com.shopkmp.uikit.moduleUiKit
import org.koin.core.module.Module

val appModules get() = listOf(
    moduleCommon,
    moduleUiKit,
    moduleNetwork,
    moduleSettings,
    moduleMvvm,
    moduleNavigation,
    moduleHomeData,
    moduleHomeDomain,
    moduleProfileData,
    moduleProfileDomain,
    moduleMain,
    platformModule,
)

internal expect val platformModule: Module
