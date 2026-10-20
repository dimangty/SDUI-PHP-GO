package com.shopkmp.network

import kotlinx.serialization.Serializable

@Serializable
internal data class ApiErrorWrapper(
    val error: ApiError?
)

@Serializable
internal data class ApiError(
    val code: String? = null,
    val message: String? = null,
    val traceId: String? = null,
    val details: Map<String, String>? = null,
)
