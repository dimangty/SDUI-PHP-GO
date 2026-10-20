package com.shopkmp.network

/** Resolved network endpoints and diagnostics policy for the active platform. */
class NetworkSettings {
    val serverUrl = defaultServerUrl()
    val isDebugLoggingEnabled = defaultNetworkDebugLogging()
    val paymentOrigins: Set<String> = defaultPaymentOrigins()
    val apiV1Url = "$serverUrl/api/v1"
    val authUrl = "$apiV1Url/auth"
    val categoriesUrl = "$apiV1Url/categories"
    val productsUrl = "$apiV1Url/products"
    val cartUrl = "$apiV1Url/cart"
    val ordersUrl = "$apiV1Url/orders"
    val addressesUrl = "$apiV1Url/addresses"
    val profileUrl = "$apiV1Url/users/me"
}

internal expect fun defaultServerUrl(): String
internal expect fun defaultNetworkDebugLogging(): Boolean
internal expect fun defaultPaymentOrigins(): Set<String>
