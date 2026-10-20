package com.shopkmp.settings

import org.koin.core.module.Module
import org.koin.dsl.module

actual val moduleSettings: Module = module {
    single {
        AppSettings(SecurityKeychainSettings(service = SETTINGS_NAME))
    }
}

private const val SETTINGS_NAME = "shopkmp"
