package com.shopkmp.network.api.request

import com.shopkmp.remoteresult.RemoteResult
import io.ktor.http.HttpMethod

internal class DeleteRequest(
    private val requestExecutor: RequestExecutor,
) {
    internal suspend inline fun <reified T> delete(
        path: String,
        queryParams: Map<String, String> = emptyMap(),
        headers: Map<String, String> = emptyMap(),
        requiresAuth: Boolean = false,
    ): RemoteResult<T> = requestExecutor.execute(
        method = HttpMethod.Delete,
        path = path,
        queryParams = queryParams,
        headers = headers,
        requiresAuth = requiresAuth,
    )
}
