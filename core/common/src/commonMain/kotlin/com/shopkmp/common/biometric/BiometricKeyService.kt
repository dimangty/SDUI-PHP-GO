package com.shopkmp.common.biometric

data class BiometricPublicKey(
    val spkiBase64: String,
    val algorithm: String = "ES256",
)

/**
 * Owns the non-exportable platform key. Implementations require a successful
 * system biometric prompt for every signature.
 */
interface BiometricKeyService {
    fun isAvailable(): Boolean
    suspend fun createKey(reason: String): BiometricPublicKey
    suspend fun sign(challenge: String, reason: String): String
    fun deleteKey()
}

class BiometricUnavailableException(message: String) : IllegalStateException(message)
class BiometricCancelledException(message: String) : IllegalStateException(message)

object UnavailableBiometricKeyService : BiometricKeyService {
    override fun isAvailable(): Boolean = false
    override suspend fun createKey(reason: String): BiometricPublicKey =
        throw BiometricUnavailableException("Биометрия недоступна")
    override suspend fun sign(challenge: String, reason: String): String =
        throw BiometricUnavailableException("Биометрия недоступна")
    override fun deleteKey() = Unit
}
