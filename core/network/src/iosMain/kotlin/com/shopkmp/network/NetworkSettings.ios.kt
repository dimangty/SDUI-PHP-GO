package com.shopkmp.network

import platform.Foundation.NSBundle

internal actual fun defaultServerUrl(): String {
    val fromInfoPlist = (NSBundle.mainBundle.objectForInfoDictionaryKey("BackendServerURL") as? String)
        ?.takeIf { it.isNotBlank() }
    val legacyValue = (NSBundle.mainBundle.objectForInfoDictionaryKey("SERVER_URL") as? String)
        ?.takeIf { it.isNotBlank() }
    return fromInfoPlist ?: legacyValue ?: "http://127.0.0.1:8080"
}

internal actual fun defaultNetworkDebugLogging(): Boolean =
    NSBundle.mainBundle.objectForInfoDictionaryKey("NetworkDebugLogging") as? Boolean ?: false

internal actual fun defaultPaymentOrigins(): Set<String> =
    (NSBundle.mainBundle.objectForInfoDictionaryKey("PaymentOrigins") as? String).orEmpty()
        .split(',').map(String::trim).filter(String::isNotBlank).toSet()
