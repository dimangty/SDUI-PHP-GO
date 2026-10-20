package com.shopkmp.remoteresult

/** Exhaustive result of a remote operation; transport failures never escape as exceptions. */
sealed class RemoteResult<out T> {
    data class Success<T>(val data: T) : RemoteResult<T>()

    sealed class Error : RemoteResult<Nothing>() {
        data object NetworkError : Error()
        data object SerializationError : Error()
        data object ServerSignError : Error()
        data object TimeoutError : Error()
        data class ServerError(
            val code: Int,
            val message: String,
            val errorCode: String? = null,
            val details: Map<String, String>? = null,
        ) : Error()
    }

    /** Hook retained for the shared MVVM error pipeline. */
    fun isErrorHandled(): Boolean = false
}
