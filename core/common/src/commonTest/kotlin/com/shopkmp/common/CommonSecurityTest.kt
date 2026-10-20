package com.shopkmp.common

import com.shopkmp.common.biometric.BiometricUnavailableException
import com.shopkmp.common.biometric.UnavailableBiometricKeyService
import com.shopkmp.common.error.RemoteErrorMessageMapper
import com.shopkmp.remoteresult.RemoteResult
import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertFailsWith

class CommonSecurityTest {
    @Test
    fun unavailableBiometricServiceAlwaysFailsClosed() = runTest {
        assertFalse(UnavailableBiometricKeyService.isAvailable())
        assertFailsWith<BiometricUnavailableException> {
            UnavailableBiometricKeyService.createKey("login")
        }
        assertFailsWith<BiometricUnavailableException> {
            UnavailableBiometricKeyService.sign("challenge", "login")
        }
        UnavailableBiometricKeyService.deleteKey()
    }

    @Test
    fun transportErrorsMapToStableUserMessages() {
        assertEquals(
            "Нет подключения к интернету. Проверьте сеть и повторите попытку.",
            RemoteErrorMessageMapper.message(RemoteResult.Error.NetworkError),
        )
        assertEquals(
            "Товара недостаточно на складе.",
            RemoteErrorMessageMapper.message(
                RemoteResult.Error.ServerError(409, "ignored", "OUT_OF_STOCK"),
            ),
        )
        assertEquals(
            "Сервер временно недоступен.",
            RemoteErrorMessageMapper.message(RemoteResult.Error.ServerError(500, "")),
        )
    }
}
