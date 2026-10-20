package com.shopkmp.network.api.request

import com.shopkmp.remoteresult.RemoteResult
import io.ktor.http.HttpMethod

internal class GetRequest(
    private val requestExecutor: RequestExecutor,
) {
    internal suspend inline fun <reified T> get(
        path: String,
        queryParams: Map<String, String> = emptyMap(),
        headers: Map<String, String> = emptyMap(),
        requiresAuth: Boolean = false,
    ): RemoteResult<T> = requestExecutor.execute(
        method = HttpMethod.Get,
        path = path,
        queryParams = queryParams,
        headers = headers,
        requiresAuth = requiresAuth,
    )

    internal suspend inline fun <reified T> getConditional(
        path: String,
        queryParams: Map<String, String> = emptyMap(),
    ): RemoteResult<T> = requestExecutor.executeConditionalGet(path, queryParams)
}
