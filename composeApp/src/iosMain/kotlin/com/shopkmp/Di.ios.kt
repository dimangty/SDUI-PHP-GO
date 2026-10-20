package com.shopkmp

import com.shopkmp.common.biometric.BiometricKeyService
import com.shopkmp.common.biometric.createPlatformBiometricKeyService
import com.shopkmp.common.device.DeviceService
import org.koin.core.module.Module
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.module

internal actual val platformModule: Module = module {
    singleOf(::DeviceService)
    single<BiometricKeyService> { createPlatformBiometricKeyService() }
}
