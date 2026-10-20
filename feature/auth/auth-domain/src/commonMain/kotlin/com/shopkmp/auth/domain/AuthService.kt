package com.shopkmp.auth.domain

data class SignUpData(
    val fullName: String = "",
    val email: String = "",
    val phone: String = "",
    val password: String = "",
)

data class AuthPinDevice(
    val id: String,
    val name: String,
    val lastUsedText: String,
    val isCurrent: Boolean,
)

sealed class AuthError : Throwable() {
    data object InvalidCredentials : AuthError()
    data object InvalidData : AuthError()
    data object InvalidPin : AuthError()
    data object PinNotSet : AuthError()
    data class InvalidPinWithAttempts(val attemptsLeft: Int) : AuthError()
    data class PinLocked(val lockedUntil: String) : AuthError()
    data object BiometricNotSet : AuthError()
    data object BiometricCancelled : AuthError()
    data class BiometricLocked(val lockedUntil: String) : AuthError()
    data class Remote(val userMessage: String) : AuthError() {
        override val message: String = userMessage
    }
}

/** Authentication and device-PIN use cases exposed to the UI layer. */
interface AuthService {
    fun hasConfiguredPin(): Boolean
    fun hasConfiguredBiometric(): Boolean = false
    fun isBiometricAvailable(): Boolean = false
    fun hasSavedSession(): Boolean
    suspend fun restoreSession(): Boolean
    suspend fun login(email: String, password: String): Result<Unit>
    suspend fun signUp(data: SignUpData): Result<Unit>
    fun shouldCreatePinAfterPasswordLogin(): Boolean
    suspend fun createPin(pin: String): Result<Unit>
    suspend fun changePin(currentPin: String, newPin: String): Result<Unit>
    suspend fun loginByPin(pin: String): Result<Unit>
    suspend fun enableBiometric(): Result<Unit> =
        Result.failure(IllegalStateException("Biometric login is unavailable"))
    suspend fun disableBiometric(): Result<Unit> =
        Result.failure(IllegalStateException("Biometric login is unavailable"))
    suspend fun loginByBiometric(): Result<Unit> =
        Result.failure(IllegalStateException("Biometric login is unavailable"))
    suspend fun getPinDevices(): List<AuthPinDevice>
    suspend fun revokePinDevice(deviceId: String): Result<Unit>
    suspend fun logout()
}
