package com.shopkmp.network.api.request

import com.shopkmp.network.BackendSession
import com.shopkmp.network.sdui.ExecutorSduiFacade
import com.shopkmp.sdui.model.*
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.settings.AppSettings
import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.*
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.http.*
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.delay
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlin.test.*

class SduiFacadeTest {
    @Test fun finiteBffSecurityChallengesShareTheExistingRefreshMutex() = runTest {
        for (code in listOf("BFF_UPSTREAM_SECURITY", "BFF_UNAUTHORIZED")) {
            val session = BackendSession(memorySettings()).apply { saveTokens("u1", "old", "refresh") }
            var refreshes = 0
            val client = HttpClient(MockEngine { request ->
                if (request.url.encodedPath == "/api/v1/auth/refresh") {
                    refreshes++; delay(10)
                    respond("""{"userId":"u1","accessToken":"new","refreshToken":"next","expiresIn":3600}""", headers = headersOf(HttpHeaders.ContentType, "application/json"))
                } else if (request.headers[HttpHeaders.Authorization] == "Bearer old") {
                    respond("""{"error":{"code":"$code","message":"Authentication rejected","traceId":"trace"}}""", HttpStatusCode.Unauthorized)
                } else respond("recovered")
            }) { install(ContentNegotiation) { json() } }
            val facade = ExecutorSduiFacade(RequestExecutor(client, Json { ignoreUnknownKeys = true }, session))
            val results = List(2) { async { facade.get(Destination(ScreenName.profile), "device") } }.awaitAll()
            results.forEach { assertEquals("recovered", assertIs<RemoteResult.Success<String>>(it).data, code) }
            assertEquals(1, refreshes, code)
            assertEquals("new", session.accessToken)
            assertEquals("next", session.refreshToken)
            client.close()
        }
    }

    @Test fun finalBff401ClearsSessionAfterOnlyOneRefreshAndReissue() = runTest {
        val session = BackendSession(memorySettings()).apply { saveTokens("u1", "old", "refresh") }
        var refreshes = 0
        var reads = 0
        val client = HttpClient(MockEngine { request ->
            if (request.url.encodedPath == "/api/v1/auth/refresh") {
                refreshes++
                respond("""{"userId":"u1","accessToken":"new","refreshToken":"next","expiresIn":3600}""", headers = headersOf(HttpHeaders.ContentType, "application/json"))
            } else {
                reads++
                respond("""{"error":{"code":"BFF_UPSTREAM_SECURITY","message":"Authentication rejected","traceId":"trace"}}""", HttpStatusCode.Unauthorized)
            }
        }) { install(ContentNegotiation) { json() } }
        val facade = ExecutorSduiFacade(RequestExecutor(client, Json { ignoreUnknownKeys = true }, session))
        assertIs<RemoteResult.Error.ServerSignError>(facade.get(Destination(ScreenName.profile), "device"))
        assertEquals(1, refreshes)
        assertEquals(2, reads)
        assertTrue(session.accessToken.isEmpty() && session.refreshToken.isEmpty() && session.userId.isEmpty())
        client.close()
    }

    @Test fun valid403KeepsBoundedBusinessErrorDetailsWithoutRefreshOrTokenClearing() = runTest {
        val session = BackendSession(memorySettings()).apply { saveTokens("u1", "access", "refresh") }
        var calls = 0
        val client = HttpClient(MockEngine { request ->
            calls++
            assertEquals("/api/v1/screens/devices", request.url.encodedPath)
            respond("""{"error":{"code":"DEVICE_FORBIDDEN","message":"Device belongs to another account","traceId":"trace","details":{"deviceId":"Not owned"}}}""", HttpStatusCode.Forbidden)
        })
        val facade = ExecutorSduiFacade(RequestExecutor(client, Json { ignoreUnknownKeys = true }, session))
        val error = assertIs<RemoteResult.Error.ServerError>(facade.get(Destination(ScreenName.devices), "device"))
        assertEquals(403, error.code)
        assertEquals("DEVICE_FORBIDDEN", error.errorCode)
        assertEquals("Device belongs to another account", error.message)
        assertEquals(mapOf("deviceId" to "Not owned"), error.details)
        assertEquals(1, calls)
        assertEquals("access", session.accessToken)
        client.close()
    }

    @Test fun malformedAndOversized403StayForbiddenWithoutLeakingRawBody() = runTest {
        for (body in listOf("<html>internal</html>", "x".repeat(1_048_577))) {
            val session = BackendSession(memorySettings()).apply { saveTokens("u1", "access", "refresh") }
            val client = HttpClient(MockEngine { respond(body, HttpStatusCode.Forbidden) })
            val facade = ExecutorSduiFacade(RequestExecutor(client, Json, session))
            val error = assertIs<RemoteResult.Error.ServerError>(facade.get(Destination(ScreenName.profile), "device"))
            assertEquals(403, error.code)
            assertEquals("FORBIDDEN", error.errorCode)
            assertEquals("access", session.accessToken)
            assertFalse(error.message.contains("internal"))
            client.close()
        }
    }

    @Test fun boundedFacadeUsesFixedRoutesQueriesAndExistingRefreshMutex() = runTest {
        val session = BackendSession(memorySettings()).apply { saveTokens("u1", "old", "refresh") }
        var refreshes = 0
        val engine = MockEngine { request ->
            when (request.url.encodedPath) {
                "/api/v1/auth/refresh" -> {
                    refreshes++; delay(10)
                    respond("""{"userId":"u1","accessToken":"new","refreshToken":"next","expiresIn":3600}""", headers = headersOf(HttpHeaders.ContentType, "application/json"))
                }
                "/api/v1/screens/cart" -> {
                    assertEquals("2", request.url.parameters["page"])
                    assertEquals("10", request.url.parameters["size"])
                    if (request.headers[HttpHeaders.Authorization] == "Bearer old") respond("", HttpStatusCode.Unauthorized)
                    else respond("{}", headers = headersOf(HttpHeaders.ContentType, "application/json"))
                }
                else -> error("Unexpected request ${request.url}")
            }
        }
        val client = HttpClient(engine) { install(ContentNegotiation) { json() } }
        val facade = ExecutorSduiFacade(RequestExecutor(client, Json { ignoreUnknownKeys = true }, session))
        val results = List(2) { async { facade.get(Destination(ScreenName.cart, cart = CollectionQuery(2, 10)), "device") } }.awaitAll()
        results.forEach { assertIs<RemoteResult.Success<String>>(it) }
        assertEquals(1, refreshes)
        client.close()
    }

    @Test fun oversizedBodiesFailAndMutationTimeoutIsNeverRetried() = runTest {
        val session = BackendSession(memorySettings())
        var mutations = 0
        val client = HttpClient(MockEngine { request ->
            if (request.method == HttpMethod.Post) { mutations++; throw kotlinx.io.IOException("lost") }
            respond("x".repeat(1_048_577), headers = headersOf(HttpHeaders.ContentType, "application/json"))
        })
        val facade = ExecutorSduiFacade(RequestExecutor(client, Json, session))
        assertIs<RemoteResult.Error.SerializationError>(facade.get(Destination(ScreenName.home), "device"))
        assertIs<RemoteResult.Error.NetworkError>(facade.command(Command.AddToCart(AddInput("22222222-2222-4222-8222-222222222222", 2)), "intent-1"))
        assertEquals(1, mutations)
        client.close()
    }
}

private fun memorySettings(): AppSettings {
    return AppSettings(com.russhwolf.settings.MapSettings())
}
