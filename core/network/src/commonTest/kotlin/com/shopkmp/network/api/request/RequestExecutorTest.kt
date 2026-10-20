package com.shopkmp.network.api.request

import com.shopkmp.network.ApiError
import com.shopkmp.network.ApiErrorWrapper
import com.shopkmp.network.BackendSession
import com.shopkmp.network.auth.TokenResponse
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.settings.AppSettings
import com.russhwolf.settings.Settings
import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertTrue
import kotlin.test.fail
import kotlin.time.TimeSource

class RequestExecutorTest {

    private val testJson = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
        explicitNulls = false
    }

    @Test
    fun successRequestReturnsData() = runTest {
        val engine = MockEngine { _ ->
            respond(
                content = """{"id":"1","name":"test"}""",
                status = HttpStatusCode.OK,
                headers = headersOf(HttpHeaders.ContentType, "application/json"),
            )
        }
        val executor = executor(engine)
        val result = executor.execute<TestDto>(
            method = io.ktor.http.HttpMethod.Get,
            path = "/api/v1/test",
        )
        assertIs<RemoteResult.Success<TestDto>>(result)
        assertEquals("1", result.data.id)
    }

    @Test
    fun conditionalGetReusesCachedBodyOnNotModified() = runTest {
        var calls = 0
        val engine = MockEngine { request ->
            calls++
            if (calls == 1) {
                respond(
                    content = """{"id":"1","name":"cached"}""",
                    status = HttpStatusCode.OK,
                    headers = headersOf(
                        HttpHeaders.ContentType to listOf("application/json"),
                        HttpHeaders.ETag to listOf("\"catalog-v1\""),
                    ),
                )
            } else {
                assertEquals("\"catalog-v1\"", request.headers[HttpHeaders.IfNoneMatch])
                respond(content = "", status = HttpStatusCode.NotModified)
            }
        }
        val executor = executor(engine)

        val first = executor.executeConditionalGet<TestDto>("/api/v1/products/1")
        val second = executor.executeConditionalGet<TestDto>("/api/v1/products/1")

        assertEquals("cached", assertIs<RemoteResult.Success<TestDto>>(first).data.name)
        assertEquals("cached", assertIs<RemoteResult.Success<TestDto>>(second).data.name)
        assertEquals(2, calls)
    }

    @Test
    fun conditionalCacheEvictsOnlyLeastRecentlyUsedEntry() = runTest {
        val executor = executor(MockEngine { respond(status = HttpStatusCode.OK, content = "{}") })
        repeat(257) { index ->
            executor.cacheConditionalEntry(
                cacheKey = "key-$index",
                entry = RequestExecutor.ConditionalEntry(
                    etag = "\"etag-$index\"",
                    body = """{"id":"$index","name":"cached"}""",
                    storedAt = TimeSource.Monotonic.markNow(),
                ),
            )
        }

        assertEquals(null, executor.cachedConditionalEntry("key-0"))
        assertEquals("\"etag-1\"", executor.cachedConditionalEntry("key-1")?.etag)
        assertEquals("\"etag-256\"", executor.cachedConditionalEntry("key-256")?.etag)
    }

    @Test
    fun unauthorizedClearsTokensAndReturnsServerSignError() = runTest {
        var refreshCalled = false
        val session = sessionWithTokens()
        val engine = MockEngine {
            if (it.url.encodedPath == "/api/v1/auth/refresh") {
                refreshCalled = true
                respond(status = HttpStatusCode.Unauthorized, content = "")
            } else {
                respond(status = HttpStatusCode.Unauthorized, content = "")
            }
        }
        val executor = executor(engine, session)
        val result = executor.execute<TestDto>(
            method = io.ktor.http.HttpMethod.Get,
            path = "/api/v1/test",
            requiresAuth = true,
        )
        assertIs<RemoteResult.Error.ServerSignError>(result)
        assertTrue(refreshCalled)
        assertTrue(session.accessToken.isBlank())
    }

    @Test
    fun refreshSuccessRetriesRequest() = runTest {
        var refreshCount = 0
        var requestCount = 0
        val session = sessionWithTokens()
        val engine = MockEngine {
            when {
                it.url.encodedPath == "/api/v1/auth/refresh" -> {
                    refreshCount++
                    respond(
                        content = testJson.encodeToString(
                            TokenResponse.serializer(),
                            TokenResponse(userId = "u1", accessToken = "new-access", refreshToken = "new-refresh", expiresIn = 3600)
                        ),
                        status = HttpStatusCode.OK,
                        headers = headersOf(HttpHeaders.ContentType, "application/json"),
                    )
                }
                it.url.encodedPath == "/api/v1/test" -> {
                    requestCount++
                    if (requestCount == 1) {
                        respond(status = HttpStatusCode.Unauthorized, content = "")
                    } else {
                        respond(
                            content = """{"id":"2","name":"retry"}""",
                            status = HttpStatusCode.OK,
                            headers = headersOf(HttpHeaders.ContentType, "application/json"),
                        )
                    }
                }
                else -> respond(status = HttpStatusCode.NotFound, content = "")
            }
        }
        val executor = executor(engine, session)
        val result = executor.execute<TestDto>(
            method = io.ktor.http.HttpMethod.Get,
            path = "/api/v1/test",
            requiresAuth = true,
        )
        assertIs<RemoteResult.Success<TestDto>>(result)
        assertEquals("2", result.data.id)
        assertEquals(1, refreshCount)
        assertEquals(2, requestCount)
    }

    @Test
    fun cancellationExceptionIsNotSwallowedH1() = runTest {
        val engine = MockEngine {
            // Delay response so cancellation can happen
            kotlinx.coroutines.delay(10_000)
            respond(
                content = """{"id":"1","name":"test"}""",
                status = HttpStatusCode.OK,
                headers = headersOf(HttpHeaders.ContentType, "application/json"),
            )
        }
        val executor = executor(engine)
        var caughtCancellation = false
        val job = backgroundScope.launch {
            try {
                executor.execute<TestDto>(
                    method = io.ktor.http.HttpMethod.Get,
                    path = "/api/v1/test",
                )
                fail("Should have thrown CancellationException")
            } catch (_: kotlin.coroutines.cancellation.CancellationException) {
                caughtCancellation = true
            }
        }
        kotlinx.coroutines.delay(50)
        job.cancel()
        job.join()
        assertTrue(caughtCancellation)
    }

    @Test
    fun serverErrorReturnsServerErrorType() = runTest {
        val errorWrapper = ApiErrorWrapper(ApiError("VALIDATION_ERROR", "Invalid input", "trace1", null))
        val engine = MockEngine { _ ->
            respond(
                content = testJson.encodeToString(ApiErrorWrapper.serializer(), errorWrapper),
                status = HttpStatusCode.BadRequest,
                headers = headersOf(HttpHeaders.ContentType, "application/json"),
            )
        }
        val executor = executor(engine)
        val result = executor.execute<TestDto>(
            method = io.ktor.http.HttpMethod.Post,
            path = "/api/v1/test",
        )
        assertIs<RemoteResult.Error.ServerError>(result)
        assertEquals(400, result.code)
        assertEquals("Invalid input", result.message)
        assertEquals("VALIDATION_ERROR", result.errorCode)
    }

    @Test
    fun pinErrorPreservesStringDetailsFromGoBackend() = runTest {
        val engine = MockEngine { _ ->
            respond(
                content = """{"error":{"code":"INVALID_PIN","message":"invalid PIN","traceId":"trace1","details":{"attemptsLeft":"4"}}}""",
                status = HttpStatusCode.Unauthorized,
                headers = headersOf(HttpHeaders.ContentType, "application/json"),
            )
        }
        val executor = executor(engine)

        val result = executor.execute<TestDto>(
            method = io.ktor.http.HttpMethod.Post,
            path = "/api/v1/auth/pin/login",
        )

        assertIs<RemoteResult.Error.ServerError>(result)
        assertEquals("INVALID_PIN", result.errorCode)
        assertEquals("4", result.details?.get("attemptsLeft"))
    }

    @Test
    fun authenticatedInvalidPinIsNotRefreshedOrRetried() = runTest {
        var protectedRequestCount = 0
        var refreshCount = 0
        val engine = MockEngine { request ->
            if (request.url.encodedPath == "/api/v1/auth/refresh") {
                refreshCount++
                respond(status = HttpStatusCode.InternalServerError, content = "")
            } else {
                protectedRequestCount++
                respond(
                    content = """{"error":{"code":"INVALID_PIN","message":"invalid PIN","traceId":"trace1","details":{"attemptsLeft":"4"}}}""",
                    status = HttpStatusCode.Unauthorized,
                    headers = headersOf(HttpHeaders.ContentType, "application/json"),
                )
            }
        }
        val executor = executor(engine)

        val result = executor.execute<Unit>(
            method = io.ktor.http.HttpMethod.Put,
            path = "/api/v1/auth/pin",
            requiresAuth = true,
        )

        assertIs<RemoteResult.Error.ServerError>(result)
        assertEquals("INVALID_PIN", result.errorCode)
        assertEquals("4", result.details?.get("attemptsLeft"))
        assertEquals(1, protectedRequestCount)
        assertEquals(0, refreshCount)
    }

    @Test
    fun singleFlightRefreshUsesCooldown() = runTest {
        var refreshCount = 0
        val session = sessionWithTokens()
        val engine = MockEngine {
            when (it.url.encodedPath) {
                "/api/v1/auth/refresh" -> {
                    refreshCount++
                    respond(
                        content = testJson.encodeToString(
                            TokenResponse.serializer(),
                            TokenResponse(userId = "u1", accessToken = "new-access", refreshToken = "new-refresh", expiresIn = 3600)
                        ),
                        status = HttpStatusCode.OK,
                        headers = headersOf(HttpHeaders.ContentType, "application/json"),
                    )
                }
                else -> {
                    respond(status = HttpStatusCode.NotFound, content = "")
                }
            }
        }
        val executor = executor(engine, session)
        executor.refreshTokens()
        assertEquals(1, refreshCount)
        executor.refreshTokens()
        assertEquals(1, refreshCount)

        session.saveTokens(
            userId = "u1",
            accessToken = "externally-rotated-access",
            refreshToken = "externally-rotated-refresh",
        )
        executor.refreshTokens()
        assertEquals(2, refreshCount)
    }

    @Test
    fun refreshTokensCancellationIsNotSwallowedH1() = runTest {
        val engine = MockEngine {
            kotlinx.coroutines.delay(10_000)
            respond(
                content = testJson.encodeToString(
                    TokenResponse.serializer(),
                    TokenResponse(userId = "u1", accessToken = "new-access", refreshToken = "new-refresh", expiresIn = 3600)
                ),
                status = HttpStatusCode.OK,
                headers = headersOf(HttpHeaders.ContentType, "application/json"),
            )
        }
        val executor = executor(engine)
        var caughtCancellation = false
        val job = backgroundScope.launch {
            try {
                executor.refreshTokens()
                fail("Should have thrown CancellationException")
            } catch (_: kotlin.coroutines.cancellation.CancellationException) {
                caughtCancellation = true
            }
        }
        kotlinx.coroutines.delay(50)
        job.cancel()
        job.join()
        assertTrue(caughtCancellation)
    }

    private fun sessionWithTokens(): BackendSession {
        val store = mutableMapOf(
            "backend_user_id" to "u1",
            "backend_access_token" to "access",
            "backend_refresh_token" to "refresh",
            "backend_device_id" to "11111111-1111-1111-1111-111111111111",
        )
        return BackendSession(AppSettings(object : Settings {
            override fun getString(key: String, defaultValue: String): String = store[key] ?: defaultValue
            override fun putString(key: String, value: String) { store[key] = value }
            override fun remove(key: String) {
                @Suppress("DEPRECATION")
                store.remove(key)
            }
            override fun getInt(key: String, defaultValue: Int): Int = store[key]?.toIntOrNull() ?: defaultValue
            override fun putInt(key: String, value: Int) { store[key] = value.toString() }
            override fun getLong(key: String, defaultValue: Long): Long = store[key]?.toLongOrNull() ?: defaultValue
            override fun putLong(key: String, value: Long) { store[key] = value.toString() }
            override fun getFloat(key: String, defaultValue: Float): Float = store[key]?.toFloatOrNull() ?: defaultValue
            override fun putFloat(key: String, value: Float) { store[key] = value.toString() }
            override fun getDouble(key: String, defaultValue: Double): Double = store[key]?.toDoubleOrNull() ?: defaultValue
            override fun putDouble(key: String, value: Double) { store[key] = value.toString() }
            override fun getBoolean(key: String, defaultValue: Boolean): Boolean = store[key]?.toBoolean() ?: defaultValue
            override fun putBoolean(key: String, value: Boolean) { store[key] = value.toString() }
            override fun hasKey(key: String): Boolean = key in store
            override fun clear() { store.clear() }
            override fun getIntOrNull(key: String): Int? = store[key]?.toIntOrNull()
            override fun getLongOrNull(key: String): Long? = store[key]?.toLongOrNull()
            override fun getStringOrNull(key: String): String? = store[key]
            override fun getFloatOrNull(key: String): Float? = store[key]?.toFloatOrNull()
            override fun getDoubleOrNull(key: String): Double? = store[key]?.toDoubleOrNull()
            override fun getBooleanOrNull(key: String): Boolean? = store[key]?.toBoolean()
            override val keys: Set<String> get() = store.keys
            override val size: Int get() = store.size
        }))
    }

    private fun executor(engine: MockEngine, session: BackendSession = sessionWithTokens()): RequestExecutor {
        val client = HttpClient(engine) {
            expectSuccess = false
            install(ContentNegotiation) {
                json(testJson)
            }
        }
        return RequestExecutor(client, testJson, session)
    }
}

@kotlinx.serialization.Serializable
private data class TestDto(val id: String, val name: String)
