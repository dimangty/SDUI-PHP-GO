package com.shopkmp.network.api

import com.shopkmp.network.NetworkSettings
import io.ktor.client.HttpClient
import io.ktor.client.engine.darwin.Darwin
import io.ktor.client.plugins.HttpTimeout
import io.ktor.client.plugins.cache.HttpCache
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.defaultRequest
import io.ktor.http.ContentType
import io.ktor.http.contentType
import io.ktor.serialization.kotlinx.json.json
import kotlinx.serialization.json.Json

internal actual class ApiHttpClient(
    json: Json,
    networkSettings: NetworkSettings,
) {
    actual val client = HttpClient(Darwin) {
        expectSuccess = false
        install(ContentNegotiation) {
            json(json)
        }
        install(HttpTimeout) {
            requestTimeoutMillis = 30_000
            connectTimeoutMillis = 15_000
            socketTimeoutMillis = 30_000
        }
        install(HttpCache)
        configureBackendLogging(networkSettings.isDebugLoggingEnabled)
        defaultRequest {
            url(networkSettings.serverUrl)
            contentType(ContentType.Application.Json)
        }
    }
}
