package com.shopkmp.network.api.request

import com.shopkmp.network.ApiErrorWrapper
import com.shopkmp.network.ApiError
import com.shopkmp.network.BackendSession
import com.shopkmp.network.api.ApiRoute
import com.shopkmp.network.auth.RefreshRequest
import com.shopkmp.network.auth.TokenResponse
import com.shopkmp.remoteresult.RemoteResult
import io.ktor.client.HttpClient
import io.ktor.client.call.body
import io.ktor.client.plugins.HttpRequestTimeoutException
import io.ktor.client.request.post
import io.ktor.client.request.header
import io.ktor.client.request.parameter
import io.ktor.client.request.request
import io.ktor.client.request.prepareRequest
import io.ktor.client.request.setBody
import io.ktor.client.statement.HttpResponse
import io.ktor.client.statement.bodyAsText
import io.ktor.client.statement.bodyAsChannel
import io.ktor.utils.io.readRemaining
import kotlinx.io.readByteArray
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.contentType
import io.ktor.http.isSuccess
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.io.IOException
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.Json
import kotlin.time.TimeMark
import kotlin.time.TimeSource
import io.github.aakira.napier.Napier
import com.shopkmp.network.randomUuid

internal class RequestExecutor(
    httpClient: HttpClient,
    private val json: Json,
    private val backendSession: BackendSession,
    private val isDebugLoggingEnabled: Boolean = false,
) {
    @PublishedApi
    internal data class ConditionalEntry(
        val etag: String,
        val body: String,
        val storedAt: TimeMark,
    )

    @PublishedApi
    internal data class ParsedServerError(
        val rawBody: String,
        val error: ApiError?,
    )

    private val client = httpClient
    // The mutex serializes refreshes: concurrent 401 responses wait for one
    // refresh instead of reusing a refresh token that has already been rotated.
    private val refreshMutex = Mutex()
    private val conditionalCacheMutex = Mutex()
    private val conditionalCache = mutableMapOf<String, ConditionalEntry>()
    private val conditionalCacheCapacity = 256
    private val conditionalBodyLimitBytes = 1_048_576
    private val conditionalCacheTtlMs = 5 * 60 * 1000L
    private var lastRefreshResult: Boolean = false
    private var lastRefreshMark: TimeMark? = null
    private var lastSuccessfulRefreshToken: String? = null
    private val refreshCooldownMs = 5000L

    /** Streaming, uncached SDUI boundary; bounded before decoding, including error bodies. */
    internal suspend fun executeBoundedJson(
        method: HttpMethod,
        path: String,
        body: String? = null,
        queryParams: Map<String, String> = emptyMap(),
        headers: Map<String, String> = emptyMap(),
    ): RemoteResult<String> {
        suspend fun attempt(): Pair<Int, String?> = client.prepareRequest(path) {
            this.method = method
            header(HttpHeaders.CacheControl, "no-store")
            header("X-Request-Id", randomUuid())
            headers.forEach { (key, value) -> header(key, value) }
            queryParams.forEach { (key, value) -> parameter(key, value) }
            if (backendSession.accessToken.isNotBlank()) header(HttpHeaders.Authorization, "Bearer ${backendSession.accessToken}")
            if (body != null) { contentType(ContentType.Application.Json); setBody(body) }
        }.execute { response ->
            val bytes = response.bodyAsChannel().readRemaining(1_048_577).readByteArray()
            response.status.value to if (bytes.size > 1_048_576) null else runCatching { bytes.decodeToString(throwOnInvalidSequence = true) }.getOrNull()
        }
        return try {
            var response = attempt()
            if (response.first == 401 && isSduiAuthenticationChallenge(response.second?.let { decodeServerError(it).error?.code }) && refreshTokens()) response = attempt()
            val (status, text) = response
            when {
                status == 401 -> { backendSession.clearTokens(); RemoteResult.Error.ServerSignError }
                status == 403 -> {
                    val error = text?.let { decodeServerError(it).error }
                        ?.takeIf { !it.code.isNullOrBlank() && !it.message.isNullOrBlank() }
                    RemoteResult.Error.ServerError(403, error?.message ?: "Доступ запрещён", error?.code ?: "FORBIDDEN", error?.details)
                }
                text == null -> RemoteResult.Error.SerializationError
                status in 200..299 -> RemoteResult.Success(text)
                else -> {
                    val error = decodeServerError(text).error
                    RemoteResult.Error.ServerError(status, error?.message ?: "Ошибка сервера", error?.code, error?.details)
                }
            }
        } catch (error: kotlin.coroutines.cancellation.CancellationException) { throw error
        } catch (_: HttpRequestTimeoutException) { RemoteResult.Error.TimeoutError
        } catch (_: IOException) { RemoteResult.Error.NetworkError
        } catch (_: SerializationException) { RemoteResult.Error.SerializationError
        } catch (_: Exception) { RemoteResult.Error.ServerError(-1, "Ошибка соединения") }
    }

    suspend inline fun <reified T> execute(
        method: HttpMethod,
        path: String,
        body: Any? = null,
        queryParams: Map<String, String> = emptyMap(),
        headers: Map<String, String> = emptyMap(),
        requiresAuth: Boolean = false,
        allowRefresh: Boolean = true,
    ): RemoteResult<T> {
        val requestMark = TimeSource.Monotonic.markNow()
        val requestId = headers["X-Request-Id"] ?: randomUuid()
        return try {
            val correlatedHeaders = headers + ("X-Request-Id" to requestId)
            var response = sendRequest(method, path, body, queryParams, correlatedHeaders, requiresAuth)
            var parsedServerError: ParsedServerError? = null
            if (response.status.value == 401 && requiresAuth && allowRefresh) {
                parsedServerError = decodeServerError(response.bodyAsText())
                val errorCode = parsedServerError.error?.code
                // A protected endpoint may use 401 for a business credential error
                // (for example INVALID_PIN while changing a PIN). Retrying such a
                // mutation would count one user action twice. Refresh only when the
                // access token itself was rejected.
                if (isAuthenticationChallenge(errorCode) && refreshTokens()) {
                    response = sendRequest(method, path, body, queryParams, correlatedHeaders, requiresAuth)
                    parsedServerError = null
                }
            }

            val durationMillis = requestMark.elapsedNow().inWholeMilliseconds
            val serverRequestId = response.headers["X-Request-Id"].orEmpty()
            if (isDebugLoggingEnabled) {
                Napier.i(
                    message = "${method.value} $path -> ${response.status.value} in ${durationMillis}ms " +
                        "requestId=$requestId serverRequestId=$serverRequestId",
                    tag = "HTTP",
                    throwable = null,
                )
            }

            when {
                response.status.isSuccess() && T::class == Unit::class ->
                    RemoteResult.Success(Unit as T)
                response.status.isSuccess() ->
                    RemoteResult.Success(response.body())
                else -> {
                    val parsed = parsedServerError ?: decodeServerError(response.bodyAsText())
                    if (response.status.value == 401 && isAuthenticationChallenge(parsed.error?.code)) {
                        backendSession.clearTokens()
                        RemoteResult.Error.ServerSignError
                    } else {
                        parseServerError(response, parsed)
                    }
                }
            }
        } catch (error: HttpRequestTimeoutException) {
            Napier.w("${method.value} $path timed out after ${requestMark.elapsedNow().inWholeMilliseconds}ms requestId=$requestId", error, "HTTP")
            RemoteResult.Error.TimeoutError
        } catch (error: SerializationException) {
            Napier.e("${method.value} $path response decoding failed requestId=$requestId", error, "HTTP")
            RemoteResult.Error.SerializationError
        } catch (error: IOException) {
            Napier.w("${method.value} $path network failure requestId=$requestId", error, "HTTP")
            RemoteResult.Error.NetworkError
        } catch (e: kotlin.coroutines.cancellation.CancellationException) {
            throw e
        } catch (error: Throwable) {
            Napier.e("${method.value} $path unexpected failure requestId=$requestId", error, "HTTP")
            RemoteResult.Error.ServerError(code = -1, message = error.message.orEmpty())
        }
    }

    suspend inline fun <reified T> executeConditionalGet(
        path: String,
        queryParams: Map<String, String> = emptyMap(),
    ): RemoteResult<T> {
        val cacheKey = buildString {
            append(path.length).append(':').append(path)
            queryParams.entries.sortedBy { it.key }.forEach { (key, value) ->
                append('|').append(key.length).append(':').append(key)
                append('=').append(value.length).append(':').append(value)
            }
        }
        val cached = cachedConditionalEntry(cacheKey)
        val requestId = randomUuid()
        return try {
            val headers = buildMap {
                put("X-Request-Id", requestId)
                cached?.etag?.takeIf { it.isNotBlank() }?.let { put(HttpHeaders.IfNoneMatch, it) }
            }
            val response = sendRequest(HttpMethod.Get, path, null, queryParams, headers, false)
            when {
                response.status.value == 304 && cached != null ->
                    RemoteResult.Success(json.decodeFromString<T>(cached.body))
                response.status.isSuccess() -> {
                    val rawBody = response.bodyAsText()
                    val value = json.decodeFromString<T>(rawBody)
                    response.headers[HttpHeaders.ETag]?.let { etag ->
                        if (rawBody.encodeToByteArray().size <= conditionalBodyLimitBytes) {
                            cacheConditionalEntry(cacheKey, ConditionalEntry(etag, rawBody, TimeSource.Monotonic.markNow()))
                        }
                    }
                    RemoteResult.Success(value)
                }
                else -> parseServerError(response, decodeServerError(response.bodyAsText()))
            }
        } catch (error: HttpRequestTimeoutException) {
            RemoteResult.Error.TimeoutError
        } catch (error: SerializationException) {
            RemoteResult.Error.SerializationError
        } catch (error: IOException) {
            RemoteResult.Error.NetworkError
        } catch (error: kotlin.coroutines.cancellation.CancellationException) {
            throw error
        } catch (error: Throwable) {
            RemoteResult.Error.ServerError(code = -1, message = error.message.orEmpty())
        }
    }

    // Preserve the server error code and structured details for typed UI errors.
    @PublishedApi
    internal fun parseServerError(
        response: HttpResponse,
        parsed: ParsedServerError,
    ): RemoteResult.Error.ServerError {
        return RemoteResult.Error.ServerError(
            code = response.status.value,
            message = parsed.error?.message ?: parseErrorMessage(parsed.rawBody),
            errorCode = parsed.error?.code,
            details = parsed.error?.details,
        )
    }

    @PublishedApi
    internal fun decodeServerError(body: String): ParsedServerError =
        ParsedServerError(
            rawBody = body,
            error = runCatching { json.decodeFromString<ApiErrorWrapper>(body).error }.getOrNull(),
        )

    @PublishedApi
    internal suspend fun cachedConditionalEntry(cacheKey: String): ConditionalEntry? =
        conditionalCacheMutex.withLock {
            val entry = conditionalCache.remove(cacheKey) ?: return@withLock null
            if (entry.storedAt.elapsedNow().inWholeMilliseconds >= conditionalCacheTtlMs) {
                return@withLock null
            }
            // Reinsert on access: insertion order becomes a portable common-code LRU.
            conditionalCache[cacheKey] = entry
            entry
        }

    @PublishedApi
    internal suspend fun cacheConditionalEntry(cacheKey: String, entry: ConditionalEntry) {
        conditionalCacheMutex.withLock {
            conditionalCache.remove(cacheKey)
            while (conditionalCache.size >= conditionalCacheCapacity) {
                val eldest = conditionalCache.keys.firstOrNull() ?: break
                conditionalCache.remove(eldest)
            }
            conditionalCache[cacheKey] = entry
        }
    }

    private fun isAuthenticationChallenge(errorCode: String?): Boolean =
        errorCode == null || errorCode == "UNAUTHORIZED" || errorCode == "INVALID_TOKEN"

    // Only the fixed SDUI boundary recognizes the BFF's normalized auth gate.
    // Native credential 401s (INVALID_PIN/assertions) retain their existing policy.
    private fun isSduiAuthenticationChallenge(errorCode: String?): Boolean =
        isAuthenticationChallenge(errorCode) || errorCode == "BFF_UPSTREAM_SECURITY" || errorCode == "BFF_UNAUTHORIZED"

    suspend fun sendRequest(
        method: HttpMethod,
        path: String,
        body: Any?,
        queryParams: Map<String, String>,
        headers: Map<String, String>,
        requiresAuth: Boolean,
    ): HttpResponse =
        client.request(path) {
            this.method = method
            queryParams.forEach { (key, value) -> parameter(key, value) }
            headers.forEach { (key, value) -> header(key, value) }
            if (requiresAuth && backendSession.accessToken.isNotBlank()) {
                header(HttpHeaders.Authorization, "Bearer ${backendSession.accessToken}")
            }
            if (body != null) {
                contentType(ContentType.Application.Json)
                setBody(body)
            }
        }

    internal fun parseErrorMessage(rawBody: String): String {
        if (rawBody.isBlank()) {
            return "Unknown server error"
        }

        return runCatching {
            json.decodeFromString<ApiErrorWrapper>(rawBody).error?.message
        }.getOrNull().orEmpty().ifBlank { rawBody }
    }

    // A successful refresh is reused briefly only while the session still
    // contains the token issued by that refresh. External token rotation
    // invalidates the cooldown and forces server-side validation.
    suspend fun refreshTokens(): Boolean = refreshMutex.withLock {
        val refreshToken = backendSession.refreshToken
        if (refreshToken.isBlank()) {
            backendSession.clearTokens()
            return@withLock false
        }

        val elapsed = lastRefreshMark?.elapsedNow()?.inWholeMilliseconds ?: Long.MAX_VALUE
        if (
            lastRefreshResult &&
            lastSuccessfulRefreshToken == refreshToken &&
            elapsed < refreshCooldownMs
        ) {
            return@withLock true
        }

        runCatching {
            val response = client.post("${ApiRoute.AUTH}/refresh") {
                contentType(ContentType.Application.Json)
                setBody(RefreshRequest(refreshToken))
            }
            if (!response.status.isSuccess()) {
                val shouldClear = response.status.value == 401
                if (shouldClear) backendSession.clearTokens()
                lastRefreshResult = false
                lastSuccessfulRefreshToken = null
                lastRefreshMark = TimeSource.Monotonic.markNow()
                return@runCatching false
            }
            val tokens = response.body<TokenResponse>()
            backendSession.saveTokens(
                userId = tokens.userId,
                accessToken = tokens.accessToken,
                refreshToken = tokens.refreshToken,
            )
            lastRefreshResult = true
            lastSuccessfulRefreshToken = tokens.refreshToken
            lastRefreshMark = TimeSource.Monotonic.markNow()
            true
        }.getOrElse { e ->
            if (e is kotlin.coroutines.cancellation.CancellationException) {
                throw e
            }
            // Clear tokens only on confirmed server-side auth refusal (HTTP 401 with invalid_token).
            // Network, parsing, and unexpected errors should not destroy the session.
            when (e) {
                is IOException -> { /* network error, tokens remain valid */ }
                is SerializationException -> { /* parse error, tokens remain valid */ }
                else -> { /* unexpected error, preserve tokens to avoid losing session */ }
            }
            lastRefreshResult = false
            lastSuccessfulRefreshToken = null
            lastRefreshMark = TimeSource.Monotonic.markNow()
            false
        }
    }
}
