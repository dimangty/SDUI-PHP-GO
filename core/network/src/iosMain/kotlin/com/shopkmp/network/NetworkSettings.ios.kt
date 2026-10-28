package com.shopkmp.network

import platform.Foundation.NSBundle
import com.shopkmp.network.api.networkDebugLoggingAllowed
import kotlin.experimental.ExperimentalNativeApi
import kotlin.native.Platform

internal actual fun defaultServerUrl(): String {
    val fromInfoPlist = (NSBundle.mainBundle.objectForInfoDictionaryKey("BackendServerURL") as? String)
        ?.takeIf { it.isNotBlank() }
    val legacyValue = (NSBundle.mainBundle.objectForInfoDictionaryKey("SERVER_URL") as? String)
        ?.takeIf { it.isNotBlank() }
    return fromInfoPlist ?: legacyValue ?: "http://127.0.0.1:8080"
}

@OptIn(ExperimentalNativeApi::class)
internal actual fun defaultNetworkDebugLogging(): Boolean =
    networkDebugLoggingAllowed(
        debugBuild = Platform.isDebugBinary,
        configured = NSBundle.mainBundle.objectForInfoDictionaryKey("NetworkDebugLogging") as? Boolean,
    )

internal actual fun defaultPaymentOrigins(): Set<String> =
    (NSBundle.mainBundle.objectForInfoDictionaryKey("PaymentOrigins") as? String).orEmpty()
        .split(',').map(String::trim).filter(String::isNotBlank).toSet()
