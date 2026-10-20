package com.shopkmp.auth.data

import com.shopkmp.auth.domain.AuthError
import com.shopkmp.auth.domain.AuthPinDevice
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.auth.domain.SignUpData
import com.shopkmp.network.BackendSession
import com.shopkmp.network.auth.AuthApi
import com.shopkmp.network.auth.LoginRequest
import com.shopkmp.network.auth.LogoutRequest
import com.shopkmp.network.auth.PinChangeRequest
import com.shopkmp.network.auth.PinLoginRequest
import com.shopkmp.network.auth.PinSetRequest
import com.shopkmp.network.auth.RegisterRequest
import com.shopkmp.network.auth.TokenResponse
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.common.error.RemoteErrorMessageMapper
import com.shopkmp.common.biometric.BiometricCancelledException
import com.shopkmp.common.biometric.BiometricKeyService
import com.shopkmp.common.biometric.UnavailableBiometricKeyService
import com.shopkmp.network.auth.BiometricChallengeRequest
import com.shopkmp.network.auth.BiometricEnrollRequest
import com.shopkmp.network.auth.BiometricLoginRequest

class AuthServiceImpl(
    private val authApi: AuthApi,
    private val backendSession: BackendSession,
    private val biometricKeyService: BiometricKeyService = UnavailableBiometricKeyService,
) : AuthService {

    override fun hasConfiguredPin(): Boolean =
        backendSession.isPinConfigured

    override fun hasConfiguredBiometric(): Boolean =
        backendSession.isBiometricConfigured

    override fun isBiometricAvailable(): Boolean =
        biometricKeyService.isAvailable()

    override fun hasSavedSession(): Boolean =
        backendSession.accessToken.isNotBlank() || backendSession.refreshToken.isNotBlank()

    // Session restoration validates the access token server-side, refreshes
    // after an authentication challenge, and updates the PIN configuration.
    override suspend fun restoreSession(): Boolean {
        if (!hasSavedSession()) return false
        val deviceId = backendSession.ensureDeviceId()
        return when (val response = authApi.getSession(deviceId)) {
            is RemoteResult.Success -> {
                if (response.data.device.pinEnabled) {
                    backendSession.markPinConfigured(
                        backendSession.currentDeviceName()
                            .ifBlank { "Device ${deviceId.take(8)}" }
                    )
                } else {
                    backendSession.clearPin()
                }
                if (response.data.device.biometricEnabled) {
                    val credentialId = response.data.device.biometricCredentialId
                        ?: backendSession.biometricCredentialId
                    backendSession.markBiometricConfigured(credentialId)
                } else {
                    backendSession.clearBiometric()
                    biometricKeyService.deleteKey()
                }
                true
            }
            is RemoteResult.Error -> {
                // Keep the session on transient network failures. Confirmed
                // authentication failures are cleared by RequestExecutor.
                when (response) {
                    is RemoteResult.Error.NetworkError,
                    is RemoteResult.Error.TimeoutError,
                    is RemoteResult.Error.SerializationError -> true
                    is RemoteResult.Error.ServerSignError,
                    is RemoteResult.Error.ServerError -> false
                }
            }
        }
    }

    override fun shouldCreatePinAfterPasswordLogin(): Boolean =
        !backendSession.isPinConfigured

    override suspend fun login(email: String, password: String): Result<Unit> =
        requestTokens(
            request = { authApi.login(LoginRequest(email = email, password = password)) },
            fallbackError = AuthError.InvalidCredentials,
        )

    override suspend fun signUp(data: SignUpData): Result<Unit> =
        requestTokens(
            request = {
                authApi.register(
                    RegisterRequest(
                        email = data.email,
                        password = data.password,
                        fullName = data.fullName,
                        phone = data.phone,
                    ),
                )
            },
            fallbackError = AuthError.InvalidData,
        )

    override suspend fun createPin(pin: String): Result<Unit> {
        val deviceName = backendSession.currentDeviceName()
            .ifBlank { "Device ${backendSession.ensureDeviceId().take(8)}" }
        return when (val response =
            authApi.createPin(
                PinSetRequest(
                    deviceId = backendSession.ensureDeviceId(),
                    deviceName = deviceName,
                    pin = pin,
                ),
            )
        ) {
            is RemoteResult.Success -> {
                backendSession.markPinConfigured(deviceName)
                Result.success(Unit)
            }

            is RemoteResult.Error -> Result.failure(AuthError.Remote(RemoteErrorMessageMapper.message(response)))
        }
    }

    override suspend fun changePin(currentPin: String, newPin: String): Result<Unit> =
        when (val response =
            authApi.changePin(
                PinChangeRequest(
                    deviceId = backendSession.ensureDeviceId(),
                    currentPin = currentPin,
                    newPin = newPin,
                ),
            )
        ) {
            is RemoteResult.Success -> Result.success(Unit)
            is RemoteResult.Error -> Result.failure(AuthError.Remote(RemoteErrorMessageMapper.message(response)))
        }

    override suspend fun loginByPin(pin: String): Result<Unit> {
        val response = authApi.loginByPin(PinLoginRequest(deviceId = backendSession.ensureDeviceId(), pin = pin))
        return when (response) {
            is RemoteResult.Success -> {
                backendSession.saveTokens(
                    userId = response.data.userId,
                    accessToken = response.data.accessToken,
                    refreshToken = response.data.refreshToken,
                )
                backendSession.markPinUsed()
                Result.success(Unit)
            }
            is RemoteResult.Error -> {
                // Map the server code and details to typed domain errors.
                val error = when (response) {
                    is RemoteResult.Error.ServerError -> {
                        when (response.errorCode) {
                            "PIN_NOT_SET" -> {
                                backendSession.clearPin()
                                AuthError.PinNotSet
                            }
                            "INVALID_PIN" -> {
                        val attemptsLeft = response.details?.get("attemptsLeft")?.toIntOrNull()
                        val lockedUntil = response.details?.get("lockedUntil")
                        if (attemptsLeft != null) {
                            AuthError.InvalidPinWithAttempts(attemptsLeft)
                        } else if (lockedUntil != null) {
                            AuthError.PinLocked(lockedUntil)
                        } else {
                            backendSession.clearPin()
                            AuthError.PinNotSet
                        }
                    }
                            "PIN_LOCKED" -> {
                                val lockedUntil = response.details?.get("lockedUntil")
                                if (lockedUntil != null) AuthError.PinLocked(lockedUntil)
                                else AuthError.InvalidPin
                            }
                            else -> AuthError.InvalidPin
                        }
                    }
                    else -> AuthError.InvalidPin
                }
                Result.failure(error)
            }
        }
    }

    override suspend fun enableBiometric(): Result<Unit> = runCatching {
        val key = biometricKeyService.createKey("Подтвердите включение входа по биометрии")
        val deviceId = backendSession.ensureDeviceId()
        val deviceName = backendSession.currentDeviceName()
            .ifBlank { "Device ${deviceId.take(8)}" }
        when (
            val response = authApi.enrollBiometric(
                BiometricEnrollRequest(
                    deviceId = deviceId,
                    deviceName = deviceName,
                    publicKey = key.spkiBase64,
                    keyAlgorithm = key.algorithm,
                ),
            )
        ) {
            is RemoteResult.Success ->
                backendSession.markBiometricConfigured(response.data.credentialId)
            is RemoteResult.Error -> {
                biometricKeyService.deleteKey()
                throw AuthError.Remote(RemoteErrorMessageMapper.message(response))
            }
        }
    }.recoverCatching { error ->
        if (error is BiometricCancelledException) throw AuthError.BiometricCancelled
        throw error
    }

    override suspend fun disableBiometric(): Result<Unit> =
        when (val response = authApi.deleteBiometric(backendSession.ensureDeviceId())) {
            is RemoteResult.Success -> {
                biometricKeyService.deleteKey()
                backendSession.clearBiometric()
                Result.success(Unit)
            }
            is RemoteResult.Error -> Result.failure(
                AuthError.Remote(RemoteErrorMessageMapper.message(response)),
            )
        }

    override suspend fun loginByBiometric(): Result<Unit> {
        val credentialId = backendSession.biometricCredentialId
        if (credentialId.isBlank()) {
            backendSession.clearBiometric()
            biometricKeyService.deleteKey()
            return Result.failure(AuthError.BiometricNotSet)
        }
        val challenge = when (
            val response = authApi.biometricChallenge(BiometricChallengeRequest(credentialId))
        ) {
            is RemoteResult.Success -> response.data.challenge
            is RemoteResult.Error -> return Result.failure(mapBiometricError(response))
        }
        val signature = try {
            biometricKeyService.sign(challenge, "Подтвердите вход в ShopKMP")
        } catch (_: BiometricCancelledException) {
            return Result.failure(AuthError.BiometricCancelled)
        } catch (error: Throwable) {
            return Result.failure(error)
        }
        return when (
            val response = authApi.loginByBiometric(
                BiometricLoginRequest(
                    credentialId = credentialId,
                    challenge = challenge,
                    signature = signature,
                ),
            )
        ) {
            is RemoteResult.Success -> {
                backendSession.saveTokens(
                    userId = response.data.userId,
                    accessToken = response.data.accessToken,
                    refreshToken = response.data.refreshToken,
                )
                backendSession.markBiometricConfigured()
                Result.success(Unit)
            }
            is RemoteResult.Error -> Result.failure(mapBiometricError(response))
        }
    }

    private fun mapBiometricError(response: RemoteResult.Error): AuthError {
        if (response is RemoteResult.Error.ServerError) {
            return when (response.errorCode) {
                "BIOMETRIC_NOT_SET", "BIOMETRIC_REVOKED" -> {
                    backendSession.clearBiometric()
                    biometricKeyService.deleteKey()
                    AuthError.BiometricNotSet
                }
                "BIOMETRIC_LOCKED" -> AuthError.BiometricLocked(
                    response.details?.get("lockedUntil").orEmpty(),
                )
                else -> AuthError.Remote(RemoteErrorMessageMapper.message(response))
            }
        }
        return AuthError.Remote(RemoteErrorMessageMapper.message(response))
    }

    override suspend fun getPinDevices(): List<AuthPinDevice> {
        if (backendSession.accessToken.isBlank()) {
            return emptyList()
        }

        return when (val response = authApi.getPinDevices()) {
            is RemoteResult.Success -> response.data.map {
                AuthPinDevice(
                    id = it.deviceId,
                    name = it.deviceName,
                    lastUsedText = it.lastUsedAt,
                    isCurrent = it.deviceId == backendSession.ensureDeviceId(),
                )
            }

            is RemoteResult.Error -> emptyList()
        }
    }

    override suspend fun revokePinDevice(deviceId: String): Result<Unit> =
        when (authApi.revokePinDevice(deviceId)) {
            is RemoteResult.Success -> {
                if (deviceId == backendSession.ensureDeviceId()) {
                    backendSession.clearPin()
                }
                Result.success(Unit)
            }
            is RemoteResult.Error -> Result.failure(IllegalStateException("Не удалось отключить PIN"))
        }

    override suspend fun logout() {
        try {
            authApi.logout(
                LogoutRequest(
                    refreshToken = backendSession.refreshToken.takeIf { it.isNotBlank() },
                ),
            )
        } finally {
            // A cancelled/lost remote response must never cancel local sign-out.
            backendSession.clearTokens()
        }
    }

    private suspend fun requestTokens(
        request: suspend () -> RemoteResult<TokenResponse>,
        fallbackError: AuthError,
        onSuccess: () -> Unit = {},
    ): Result<Unit> =
        when (val response = request()) {
            is RemoteResult.Success -> {
                backendSession.saveTokens(
                    userId = response.data.userId,
                    accessToken = response.data.accessToken,
                    refreshToken = response.data.refreshToken,
                )
                onSuccess()
                Result.success(Unit)
            }

            is RemoteResult.Error -> {
                val mapped = if (response is RemoteResult.Error.ServerError && response.errorCode == "INVALID_CREDENTIALS") {
                    fallbackError
                } else {
                    AuthError.Remote(RemoteErrorMessageMapper.message(response))
                }
                Result.failure(mapped)
            }
        }
}
