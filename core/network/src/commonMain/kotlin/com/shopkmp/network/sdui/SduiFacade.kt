package com.shopkmp.network.sdui

import com.shopkmp.network.api.request.RequestExecutor
import com.shopkmp.network.randomUuid
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.sdui.model.Command
import com.shopkmp.sdui.model.Destination
import com.shopkmp.sdui.model.ScreenName
import com.shopkmp.sdui.model.validDeviceId
import com.shopkmp.sdui.model.validateCommand
import com.shopkmp.sdui.model.validateDestination
import io.ktor.http.HttpMethod
import kotlinx.serialization.json.Json

/** No caller-supplied path, method, origin or arbitrary headers. Uses the singleton executor. */
interface SduiFacade {
    suspend fun get(destination: Destination, deviceId: String): RemoteResult<String>
    suspend fun command(command: Command, idempotencyKey: String? = null): RemoteResult<String>
    fun newIntentKey(): String
}

internal class ExecutorSduiFacade(private val executor: RequestExecutor) : SduiFacade {
    private val json = Json { encodeDefaults = true }
    override fun newIntentKey() = randomUuid()
    override suspend fun get(destination: Destination, deviceId: String): RemoteResult<String> {
        validateDestination(destination)
        require(validDeviceId(deviceId))
        val path = "/api/v1/screens/${destination.screen}" + (destination.id?.let { "/$it" } ?: "")
        val query = buildMap {
            if (destination.screen == ScreenName.home) put("deviceId", deviceId)
            destination.catalog?.let { q ->
                q.q?.let { put("q", it) }; q.categoryId?.let { put("categoryId", it) }; q.sort?.let { put("sort", it) }
                q.priceMin?.let { put("priceMin", it) }; q.priceMax?.let { put("priceMax", it) }
                put("page", q.page.toString()); put("size", q.size.toString())
            }
            destination.orders?.let { q -> q.status?.let { put("status", it.name) }; put("page", q.page.toString()); put("size", q.size.toString()) }
            destination.categories?.let { put("categoryPage", it.page.toString()); put("categorySize", it.size.toString()) }
            destination.cart?.let { put("page", it.page.toString()); put("size", it.size.toString()) }
        }
        return executor.executeBoundedJson(HttpMethod.Get, path, queryParams = query)
    }
    override suspend fun command(command: Command, idempotencyKey: String?): RemoteResult<String> {
        validateCommand(command)
        require(if (command is Command.AddToCart) idempotencyKey != null && idempotencyKey.length in 1..255 && idempotencyKey.all { it.code in 33..126 } else idempotencyKey == null)
        val body = json.encodeToString<Command>(command)
        require(body.encodeToByteArray().size <= 32768)
        return executor.executeBoundedJson(HttpMethod.Post, "/api/v1/sdui/actions", body,
            headers = idempotencyKey?.let { mapOf("Idempotency-Key" to it) } ?: emptyMap())
    }
}
