package com.shopkmp.common.biometric

import kotlinx.coroutines.test.runTest
import platform.Foundation.NSUUID
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse

class BiometricKeyServiceIosTest {
    @Test
    fun deletingMissingKeyUsesRealSecurityFrameworkWithIsolatedTag() {
        val service = IosBiometricKeyService("com.shopkmp.tests.biometric.${NSUUID().UUIDString}")
        service.deleteKey()
        service.deleteKey()
    }

    @Test
    fun unenrolledSimulatorFailsClosedBeforeCreatingKey() = runTest {
        val service = IosBiometricKeyService("com.shopkmp.tests.biometric.${NSUUID().UUIDString}")
        assertFalse(service.isAvailable(), "This test requires an unenrolled simulator")
        assertFailsWith<BiometricUnavailableException> {
            service.createKey("Isolated native regression test")
        }
    }

    @Test
    fun p256GeneratorPointProducesBackendCompatibleSpki() {
        val generatorPoint = (
            "04" +
                "6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296" +
                "4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5"
            ).hexToByteArray()

        assertEquals(
            "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEaxfR8uEsQkf4vOblY6RA8ncDfYEt6zOg9KE5RdiYwpZP40Li/hp/m47n60p8D54WK84zV2sxXs7LtkBoN79R9Q==",
            encodeP256SubjectPublicKeyInfo(generatorPoint),
        )
    }

    @Test
    fun spkiBuilderRejectsCompressedOrTruncatedPoint() {
        assertFailsWith<IllegalArgumentException> {
            encodeP256SubjectPublicKeyInfo(ByteArray(33) { 0x02 })
        }
    }
}
