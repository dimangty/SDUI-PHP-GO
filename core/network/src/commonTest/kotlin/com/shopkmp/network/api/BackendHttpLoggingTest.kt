package com.shopkmp.network.api

import com.russhwolf.settings.MapSettings
import com.shopkmp.network.BackendSession
import com.shopkmp.network.api.request.RequestExecutor
import com.shopkmp.network.auth.PinChangeRequest
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.settings.AppSettings
import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.request.prepareGet
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.TextContent
import io.ktor.http.headersOf
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.test.runTest
import kotlinx.io.IOException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertTrue

class BackendHttpLoggingTest {
    @Test
    fun nativeJsonLogsHideCredentialsWithoutChangingTheRequestOrResponse() = runTest {
        val logs = mutableListOf<String>()
        val requestBody = """{"email":"buyer@example.test","password":"password-fixture","items":[{"PIN":"739512"}],"quantity":2}"""
        val responseBody = """{"accessToken":"issued-access-fixture","refresh_token":"issued-refresh-fixture","result":"accepted"}"""
        val engine = MockEngine { request ->
            assertEquals(requestBody, (request.body as TextContent).text)
            assertEquals("Bearer session-access-fixture", request.headers[HttpHeaders.Authorization])
            respond(responseBody, headers = headersOf(
                HttpHeaders.ContentType to listOf("application/json"),
                HttpHeaders.SetCookie to listOf("session=cookie-fixture"),
                "X-Request-Id" to listOf("server-trace"),
            ))
        }
        val client = client(engine, logs::add)
        try {
            val result = executor(client).execute<JsonObject>(
                HttpMethod.Post,
                "https://backend.example/api/v1/auth/login?token=query-token-fixture",
                body = LoggingCredentials("buyer@example.test", "password-fixture", listOf(LoggingPin("739512")), 2),
                requiresAuth = true,
            )
            assertEquals("issued-access-fixture", assertIs<RemoteResult.Success<JsonObject>>(result).data["accessToken"]?.jsonPrimitive?.content)
            val text = logs.joinToString("\n")
            assertTrue(text.contains("POST") && text.contains("backend.example/api/v1/auth/login"))
            assertTrue(text.contains("200") && text.contains("ms") && text.contains("server-trace"))
            assertTrue(text.contains("quantity") && text.contains("accepted"), text)
            assertTrue(text.contains("***"), text)
            for (secret in listOf("password-fixture", "739512", "issued-access-fixture", "issued-refresh-fixture", "session-access-fixture", "cookie-fixture", "query-token-fixture")) {
                assertFalse(text.contains(secret), "Credential escaped through HTTP logs")
            }
        } finally {
            client.close()
        }
    }

    @Test
    fun streamedSduiLogsTheBoundedJsonAndKeepsTheOriginalPayload() = runTest {
        val logs = mutableListOf<String>()
        val body = """{"schemaVersion":1,"nodes":[{"text":"catalog-visible"}],"new_pin":"482951"}"""
        val client = client(MockEngine { respond(body, headers = jsonHeaders) }, logs::add)
        try {
            val result = executor(client).executeBoundedJson(HttpMethod.Get, "https://backend.example/api/v1/screens/home")
            assertEquals(body, assertIs<RemoteResult.Success<String>>(result).data)
            val text = logs.joinToString("\n")
            assertTrue(text.contains("catalog-visible"), text)
            assertFalse(text.contains("482951"))
        } finally {
            client.close()
        }
    }

    @Test
    fun sduiScreenEventsIncludeARedactedPreviewEvenWhenTheDocumentExceedsTheLogLimit() = runTest {
        val logs = mutableListOf<String>()
        val nodes = List(500) { """{"type":"Text","text":"home-title"}""" }.joinToString(",")
        val body = """{"schemaVersion":1,"screenId":"home","password":"screen-password-fixture","nodes":[$nodes]}"""
        val client = client(MockEngine { respond(body, headers = jsonHeaders) }, logs::add)
        try {
            val result = executor(client).executeBoundedJson(HttpMethod.Get, "https://backend.example/api/v1/screens/home")
            assertEquals(body, assertIs<RemoteResult.Success<String>>(result).data)
            val text = logs.joinToString("\n")
            assertTrue(text.contains("SDUI") && text.contains("screen=home"), text)
            assertTrue(text.contains("schemaVersion") && text.contains("home-title"), text)
            assertTrue(text.contains("truncated"), text)
            assertFalse(text.contains("screen-password-fixture"))
            assertTrue(text.length < 12_000, "SDUI preview exceeded its log bound")
        } finally {
            client.close()
        }
    }

    @Test
    fun pinChangesAndNestedBiometricCredentialsAreMaskedWithoutChangingTheWire() = runTest {
        val logs = mutableListOf<String>()
        val client = client(MockEngine { request ->
            val sent = Json.parseToJsonElement((request.body as TextContent).text) as JsonObject
            assertEquals("382649", sent["currentPin"]?.jsonPrimitive?.content)
            assertEquals("594827", sent["newPin"]?.jsonPrimitive?.content)
            respond("""{"items":[{"credentialId":"credential-fixture"}],"device":{"biometricCredentialId":"biometric-fixture"},"result":"updated"}""", headers = jsonHeaders)
        }, logs::add)
        try {
            val result = executor(client).execute<JsonObject>(HttpMethod.Put, "https://backend.example/api/v1/auth/pin",
                PinChangeRequest("device", "382649", "594827"), requiresAuth = true)
            assertEquals("updated", assertIs<RemoteResult.Success<JsonObject>>(result).data["result"]?.jsonPrimitive?.content)
            val text = logs.joinToString("\n")
            for (secret in listOf("382649", "594827", "credential-fixture", "biometric-fixture")) {
                assertFalse(text.contains(secret), "Credential escaped through HTTP logs")
            }
        } finally {
            client.close()
        }
    }

    @Test
    fun malformedResponseMetadataCannotMakeTheSduiDiagnosticCallbackThrow() = runTest {
        val body = """{"schemaVersion":1,"screenId":"home","nodes":[]}"""
        for (enabled in listOf(false, true)) {
            val logs = mutableListOf<String>()
            val client = client(MockEngine { respond(body, headers = headersOf(HttpHeaders.ContentType, "invalid")) }, logs::add, enabled)
            try {
                val status = client.prepareGet("https://backend.example/api/v1/screens/home").execute { response ->
                    response.logBoundedJsonBody(body.encodeToByteArray())
                    response.status
                }
                assertEquals(HttpStatusCode.OK, status)
                if (enabled) assertTrue(logs.joinToString("\n").contains("omitted"))
            } finally {
                client.close()
            }
        }
    }

    @Test
    fun refreshAndTheReissuedCallAreLoggedWithoutLeakingRotatedTokens() = runTest {
        val logs = mutableListOf<String>()
        var refreshes = 0
        var reads = 0
        val engine = MockEngine { request ->
            if (request.url.encodedPath.endsWith("/refresh")) {
                refreshes++
                assertTrue((request.body as TextContent).text.contains("session-refresh-fixture"))
                respond("""{"userId":"u1","accessToken":"rotated-access-fixture","refreshToken":"rotated-refresh-fixture","expiresIn":300}""", headers = jsonHeaders)
            } else {
                reads++
                if (reads == 1) respond("""{"error":{"code":"UNAUTHORIZED","message":"expired"}}""", HttpStatusCode.Unauthorized, jsonHeaders)
                else respond("""{"result":"recovered"}""", headers = jsonHeaders)
            }
        }
        val client = client(engine, logs::add)
        try {
            val result = executor(client).execute<JsonObject>(HttpMethod.Get, "https://backend.example/api/v1/private", requiresAuth = true)
            assertEquals("recovered", assertIs<RemoteResult.Success<JsonObject>>(result).data["result"]?.jsonPrimitive?.content)
            assertEquals(1, refreshes)
            assertEquals(2, reads)
            val text = logs.joinToString("\n")
            assertTrue(text.contains("/api/v1/auth/refresh") && text.contains("recovered"), text)
            for (secret in listOf("session-refresh-fixture", "session-access-fixture", "rotated-access-fixture", "rotated-refresh-fixture")) {
                assertFalse(text.contains(secret), "Refresh credential escaped through HTTP logs")
            }
        } finally {
            client.close()
        }
    }

    @Test
    fun malformedOversizedAndNonJsonBodiesAreOmittedRatherThanPrintedRaw() = runTest {
        for ((body, type) in listOf(
            """{"password":"malformed-sensitive""" to "application/json",
            """{"payload":"${"large-sensitive".repeat(1024)}"}""" to "application/json",
            "<html>html-sensitive</html>" to "text/html",
        )) {
            val logs = mutableListOf<String>()
            val client = client(MockEngine { respond(body, headers = headersOf(HttpHeaders.ContentType, type)) }, logs::add)
            try {
                assertIs<RemoteResult.Success<Unit>>(executor(client).execute<Unit>(HttpMethod.Get, "https://backend.example/api/v1/test"))
                val text = logs.joinToString("\n")
                assertTrue(text.contains("omitted"), text)
                assertFalse(text.contains("sensitive"))
                assertTrue(text.length < 12_000, "Diagnostic output exceeded its bounded size")
            } finally {
                client.close()
            }
        }
    }

    @Test
    fun oversizedSduiIsRejectedAndFailedMutationsAreNotRetriedWithLoggingEnabled() = runTest {
        val logs = mutableListOf<String>()
        var mutations = 0
        val client = client(MockEngine { request ->
            if (request.method == HttpMethod.Post) {
                mutations++
                throw IOException("exception-sensitive")
            }
            respond("x".repeat(1_048_577), headers = jsonHeaders)
        }, logs::add)
        try {
            val executor = executor(client)
            assertIs<RemoteResult.Error.SerializationError>(executor.executeBoundedJson(HttpMethod.Get, "https://backend.example/api/v1/screens/home"))
            assertIs<RemoteResult.Error.NetworkError>(executor.executeBoundedJson(HttpMethod.Post, "https://backend.example/api/v1/sdui/commands", "{}"))
            assertEquals(1, mutations)
            val text = logs.joinToString("\n")
            assertTrue(text.contains("FAILED"), text)
            assertFalse(text.contains("exception-sensitive"))
            assertTrue(text.length < 12_000, "Oversized SDUI payload was copied into the log")
        } finally {
            client.close()
        }
    }

    @Test
    fun disabledLoggingEmitsNothingAndASinkFailureCannotBreakTheRequest() = runTest {
        val logs = mutableListOf<String>()
        val disabled = client(MockEngine { respond("{}", headers = jsonHeaders) }, logs::add, enabled = false)
        val brokenSink = client(MockEngine { respond("{}", headers = jsonHeaders) }, { error("sink failure") })
        try {
            assertIs<RemoteResult.Success<JsonObject>>(executor(disabled).execute<JsonObject>(HttpMethod.Get, "https://backend.example/api/v1/test"))
            assertTrue(logs.isEmpty())
            assertIs<RemoteResult.Success<JsonObject>>(executor(brokenSink).execute<JsonObject>(HttpMethod.Get, "https://backend.example/api/v1/test"))
        } finally {
            disabled.close()
            brokenSink.close()
        }
    }

    @Test
    fun debugBuildDefaultsToLoggingButReleaseCannotBeEnabledByAPlistOverride() {
        assertTrue(networkDebugLoggingAllowed(debugBuild = true, configured = null))
        assertFalse(networkDebugLoggingAllowed(debugBuild = true, configured = false))
        assertFalse(networkDebugLoggingAllowed(debugBuild = false, configured = true))
    }

    private fun client(engine: MockEngine, sink: (String) -> Unit, enabled: Boolean = true): HttpClient =
        HttpClient(engine) {
            install(ContentNegotiation) { json() }
            configureBackendLogging(enabled, sink)
        }

    private fun executor(client: HttpClient): RequestExecutor {
        val session = BackendSession(AppSettings(MapSettings())).apply {
            saveTokens("u1", "session-access-fixture", "session-refresh-fixture")
        }
        return RequestExecutor(client, Json { ignoreUnknownKeys = true }, session)
    }

    private val jsonHeaders = headersOf(HttpHeaders.ContentType, ContentType.Application.Json.toString())
}

@kotlinx.serialization.Serializable
private data class LoggingCredentials(val email: String, val password: String, val items: List<LoggingPin>, val quantity: Int)

@kotlinx.serialization.Serializable
private data class LoggingPin(@kotlinx.serialization.SerialName("PIN") val pin: String)
