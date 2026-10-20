package com.shopkmp.network

internal actual fun defaultServerUrl(): String = BuildConfig.SERVER_URL
internal actual fun defaultNetworkDebugLogging(): Boolean = BuildConfig.DEBUG
internal actual fun defaultPaymentOrigins(): Set<String> = BuildConfig.PAYMENT_ORIGINS.split(',').map(String::trim).filter(String::isNotBlank).toSet()
