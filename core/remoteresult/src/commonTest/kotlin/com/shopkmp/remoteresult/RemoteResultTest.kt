package com.shopkmp.remoteresult

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertNull

class RemoteResultTest {

    @Test
    fun successHoldsData() {
        val result = RemoteResult.Success(42)
        assertEquals(42, result.data)
    }

    @Test
    fun networkErrorIsDistinctType() {
        val error: RemoteResult.Error = RemoteResult.Error.NetworkError
        assertIs<RemoteResult.Error.NetworkError>(error)
    }

    @Test
    fun serializationErrorIsDistinctType() {
        val error: RemoteResult.Error = RemoteResult.Error.SerializationError
        assertIs<RemoteResult.Error.SerializationError>(error)
    }

    @Test
    fun serverErrorHoldsDetails() {
        val error = RemoteResult.Error.ServerError(
            code = 422,
            message = "Validation failed",
            errorCode = "VALIDATION_ERROR",
            details = mapOf("field" to "email"),
        )
        assertEquals(422, error.code)
        assertEquals("Validation failed", error.message)
        assertEquals("VALIDATION_ERROR", error.errorCode)
        assertEquals("email", error.details?.get("field"))
    }

    @Test
    fun serverErrorWithoutDetails() {
        val error = RemoteResult.Error.ServerError(code = 500, message = "Internal error")
        assertEquals(500, error.code)
        assertNull(error.errorCode)
        assertNull(error.details)
    }

    @Test
    fun serverSignErrorIsDistinctType() {
        val error: RemoteResult.Error = RemoteResult.Error.ServerSignError
        assertIs<RemoteResult.Error.ServerSignError>(error)
    }

    @Test
    fun timeoutErrorIsDistinctType() {
        val error: RemoteResult.Error = RemoteResult.Error.TimeoutError
        assertIs<RemoteResult.Error.TimeoutError>(error)
    }
}
