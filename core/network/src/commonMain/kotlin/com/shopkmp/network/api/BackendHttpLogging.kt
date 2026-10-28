package com.shopkmp.network.api

import io.ktor.client.HttpClientConfig
import io.ktor.client.HttpClient
import io.ktor.client.plugins.api.ClientHook
import io.ktor.client.plugins.api.createClientPlugin
import io.ktor.client.request.HttpRequestBuilder
import io.ktor.client.request.HttpSendPipeline
import io.ktor.client.statement.HttpResponse
import io.ktor.client.statement.bodyAsChannel
import io.ktor.http.ContentType
import io.ktor.http.URLBuilder
import io.ktor.http.Url
import io.ktor.http.content.OutgoingContent
import io.ktor.http.content.TextContent
import io.ktor.http.contentType
import io.ktor.util.AttributeKey
import io.ktor.util.pipeline.PipelineContext
import io.ktor.utils.io.readBuffer
import io.github.aakira.napier.Napier
import kotlinx.coroutines.CancellationException
import kotlinx.io.readByteArray
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import com.shopkmp.network.randomUuid
import kotlin.time.TimeSource

private const val BODY_LIMIT = 8192
private const val SDUI_BODY_LIMIT = 1_048_576
private val logContextKey = AttributeKey<BackendLogContext>("ShopKmpBackendLog")
private val logJson = Json { prettyPrint = true }
private val sensitiveNames = setOf(
    "pin", "pincode", "oldpin", "currentpin", "newpin", "confirmpin", "authorization", "cookie", "setcookie",
    "secret", "clientsecret", "apikey", "privatekey", "signature", "assertion", "challenge", "nonce",
    "cardnumber", "cvv", "cvc", "paymenturl", "redirecturl", "returnurl", "email", "phone", "fullname",
    "credentialid", "biometriccredentialid",
)

/** Shared, Debug-only diagnostics. Never installs a body observer or consumes a live stream. */
internal fun HttpClientConfig<*>.configureBackendLogging(
    enabled: Boolean,
    sink: (String) -> Unit = { message ->
        Napier.d(message, tag = if (message.startsWith("SDUI ")) "SDUI" else "HTTP")
    },
) {
    if (!enabled) return
    install(createClientPlugin("ShopKmpBackendLogging") {
        on(BackendSendHook) { request, content ->
            val requestId = request.headers["X-Request-Id"] ?: randomUuid().also {
                request.headers.append("X-Request-Id", it)
            }
            val url = request.url.build()
            val screen = url.encodedPath.takeIf { it.startsWith("/api/v1/screens/") }
                ?.removePrefix("/api/v1/screens/")?.substringBefore('/')
            val context = BackendLogContext(request.method.value, safeUrl(url), requestId, screen, sink)
            request.attributes.put(logContextKey, context)
            context.write("--> ${context.label}")
            val body = when (content) {
                is TextContent -> safeJson(content.text, content.contentType)
                is OutgoingContent.NoContent -> "[empty body]"
                else -> "[body omitted: binary or streaming content]"
            }
            context.write("REQUEST JSON ${context.label}\n$body")
            try {
                proceed()
            } catch (error: CancellationException) {
                context.write("CANCELLED ${context.label} ${context.elapsedMillis}ms")
                throw error
            } catch (error: Throwable) {
                // Exception messages can include URLs, credentials or response data.
                context.write("FAILED ${context.label} ${context.elapsedMillis}ms ${error::class.simpleName}")
                throw error
            }
        }
        onResponse { response ->
            response.call.attributes.getOrNull(logContextKey)?.let { context ->
                val serverId = response.headers["X-Request-Id"].orEmpty().safeIdentifier()
                context.write("<-- ${response.status.value} ${context.label} ${context.elapsedMillis}ms serverRequestId=$serverId")
            }
        }
    })
}

/** Native client.request/post responses are already saved by Ktor; read only a bounded replay. */
internal suspend fun HttpResponse.logSavedJsonBody() {
    val context = call.attributes.getOrNull(logContextKey) ?: return
    try {
        val bytes = bodyAsChannel().readBuffer(BODY_LIMIT + 1).readByteArray()
        context.write("RESPONSE JSON ${context.label}\n${safeJson(bytes, contentType())}")
    } catch (error: CancellationException) {
        throw error
    } catch (_: Exception) {
        context.write("RESPONSE JSON ${context.label}\n[body omitted: unavailable replay]")
    }
}

/** The SDUI consumer supplies bytes only after its existing bounded read. */
internal fun HttpResponse.logBoundedJsonBody(bytes: ByteArray) {
    val context = call.attributes.getOrNull(logContextKey) ?: return
    val preview = try {
        safeJson(bytes, contentType(), preview = context.isSdui)
    } catch (error: CancellationException) {
        throw error
    } catch (_: Exception) {
        "[body omitted: invalid response metadata]"
    }
    context.write("RESPONSE JSON ${context.label} receivedBytes=${bytes.size}\n$preview")
}

internal fun networkDebugLoggingAllowed(debugBuild: Boolean, configured: Boolean?): Boolean =
    debugBuild && configured != false

private class BackendLogContext(
    method: String,
    url: String,
    requestId: String,
    screen: String?,
    private val sink: (String) -> Unit,
) {
    private val started = TimeSource.Monotonic.markNow()
    val isSdui = screen != null
    val label = screen?.let { "screen=$it " }.orEmpty() + "$method $url requestId=${requestId.safeIdentifier()}"
    val elapsedMillis: Long get() = started.elapsedNow().inWholeMilliseconds

    fun write(message: String) {
        // A console implementation must not change transport/authentication results.
        runCatching { sink(if (isSdui) "SDUI $message" else message) }
    }
}

private fun safeJson(bytes: ByteArray, type: ContentType?, preview: Boolean = false): String {
    val inputLimit = if (preview) SDUI_BODY_LIMIT else BODY_LIMIT
    if (bytes.size > inputLimit) return "[body omitted: exceeds $inputLimit bytes]"
    val text = runCatching { bytes.decodeToString(throwOnInvalidSequence = true) }.getOrNull()
        ?: return "[body omitted: invalid UTF-8]"
    return safeJson(text, type, preview)
}

private fun safeJson(text: String, type: ContentType?, preview: Boolean = false): String {
    if (text.isEmpty()) return "[empty body]"
    if (type?.contentSubtype != "json" && type?.contentSubtype?.endsWith("+json") != true) {
        return "[body omitted: non-JSON content]"
    }
    val inputLimit = if (preview) SDUI_BODY_LIMIT else BODY_LIMIT
    if (text.length > inputLimit || text.encodeToByteArray().size > inputLimit) {
        return "[body omitted: exceeds $inputLimit bytes]"
    }
    if (!hasBoundedNesting(text)) return "[body omitted: JSON nesting limit]"
    return runCatching {
        val value = Json.parseToJsonElement(text)
        if (value !is JsonObject && value !is JsonArray) return "[body omitted: scalar JSON]"
        val sanitized = logJson.encodeToString(JsonElement.serializer(), redact(value))
        val encoded = sanitized.encodeToByteArray()
        when {
            encoded.size <= BODY_LIMIT -> sanitized
            preview -> encoded.copyOf(BODY_LIMIT).decodeToString() + "\n[JSON truncated to 8192 bytes]"
            else -> "[body omitted: sanitized size limit]"
        }
    }.getOrDefault("[body omitted: invalid JSON]")
}

private fun redact(value: JsonElement): JsonElement = when (value) {
    is JsonObject -> JsonObject(value.mapValues { (key, child) ->
        if (isSensitive(key)) JsonPrimitive("***") else redact(child)
    })
    is JsonArray -> JsonArray(value.map(::redact))
    is JsonPrimitive -> if (value.isString && value.content.startsWith("http")) {
        JsonPrimitive(runCatching { safeUrl(Url(value.content)) }.getOrDefault("[URL omitted]"))
    } else value
}

private fun isSensitive(key: String): Boolean {
    val name = key.lowercase().filter(Char::isLetterOrDigit)
    return name.contains("password") || name.endsWith("token") || name in sensitiveNames
}

private fun safeUrl(url: Url): String = URLBuilder(url).apply {
    user = null
    password = null
    fragment = ""
    // Query values can carry capabilities, search text or identifiers. Keep keys only.
    val names = parameters.names().toList()
    parameters.clear()
    names.forEach { parameters.append(it, "***") }
}.buildString().take(1024)

private fun String.safeIdentifier(): String =
    if (length <= 128 && all { it.isLetterOrDigit() || it in "-_.:" }) this else "[omitted]"

private fun hasBoundedNesting(text: String): Boolean {
    var depth = 0
    var quoted = false
    var escaped = false
    for (character in text) {
        if (quoted) {
            if (escaped) escaped = false
            else if (character == '\\') escaped = true
            else if (character == '"') quoted = false
        } else when (character) {
            '"' -> quoted = true
            '{', '[' -> if (++depth > 32) return false
            '}', ']' -> depth--
        }
    }
    return true
}

/** Monitor the rendered request, after ContentNegotiation, without wrapping its body/channel. */
private object BackendSendHook : ClientHook<suspend BackendSendHook.Context.(HttpRequestBuilder, Any) -> Unit> {
    class Context(private val pipeline: PipelineContext<Any, HttpRequestBuilder>) {
        suspend fun proceed() = pipeline.proceed()
    }

    override fun install(client: HttpClient, handler: suspend Context.(HttpRequestBuilder, Any) -> Unit) {
        client.sendPipeline.intercept(HttpSendPipeline.Monitoring) {
            handler(Context(this), context, subject)
        }
    }
}
