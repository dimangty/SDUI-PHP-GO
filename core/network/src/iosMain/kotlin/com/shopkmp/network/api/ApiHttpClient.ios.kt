package com.shopkmp.network.api

import com.shopkmp.network.NetworkSettings
import io.ktor.client.HttpClient
import io.ktor.client.engine.darwin.Darwin
import io.ktor.client.plugins.HttpTimeout
import io.ktor.client.plugins.cache.HttpCache
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.defaultRequest
import io.ktor.client.plugins.logging.LogLevel
import io.ktor.client.plugins.logging.Logger
import io.ktor.client.plugins.logging.Logging
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.contentType
import io.ktor.serialization.kotlinx.json.json
import kotlinx.serialization.json.Json
import io.github.aakira.napier.Napier

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
        install(Logging) {
            // HEADERS avoids leaking access and refresh tokens from response bodies.
            logger = object : Logger {
                override fun log(message: String) {
                    Napier.d(message, tag = "HTTP")
                }
            }
            level = if (networkSettings.isDebugLoggingEnabled) LogLevel.HEADERS else LogLevel.NONE
            sanitizeHeader { header ->
                header == HttpHeaders.Authorization
            }
        }
        defaultRequest {
            url(networkSettings.serverUrl)
            contentType(ContentType.Application.Json)
        }
    }
}
