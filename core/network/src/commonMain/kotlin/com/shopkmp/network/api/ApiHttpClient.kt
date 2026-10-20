package com.shopkmp.network.api

import io.ktor.client.HttpClient

internal expect class ApiHttpClient {
    val client: HttpClient
}
