package com.shopkmp.network.api.request

import com.shopkmp.remoteresult.RemoteResult
import io.ktor.http.HttpMethod

internal class PostRequest(
    private val requestExecutor: RequestExecutor,
) {
    internal suspend inline fun <reified T> post(
        path: String,
        body: Any? = null,
        queryParams: Map<String, String> = emptyMap(),
        headers: Map<String, String> = emptyMap(),
        requiresAuth: Boolean = false,
    ): RemoteResult<T> = requestExecutor.execute(
        method = HttpMethod.Post,
        path = path,
        body = body,
        queryParams = queryParams,
        headers = headers,
        requiresAuth = requiresAuth,
    )
}
