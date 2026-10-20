package com.shopkmp.network.auth

import kotlinx.serialization.Serializable

@Serializable
data class RegisterRequest(
    val email: String,
    val password: String,
    val fullName: String,
    val phone: String,
)

@Serializable
data class LoginRequest(val email: String, val password: String)

@Serializable
data class RefreshRequest(val refreshToken: String)

@Serializable
data class LogoutRequest(
    val refreshToken: String? = null,
)

@Serializable
data class PinSetRequest(val deviceId: String, val deviceName: String, val pin: String)

@Serializable
data class PinLoginRequest(val deviceId: String, val pin: String)

@Serializable
data class PinChangeRequest(val deviceId: String, val currentPin: String, val newPin: String)

@Serializable
data class BiometricEnrollRequest(
    val deviceId: String,
    val deviceName: String,
    val publicKey: String,
    val keyAlgorithm: String,
)

@Serializable
data class BiometricEnrollResponse(val credentialId: String)

@Serializable
data class BiometricChallengeRequest(val credentialId: String)

@Serializable
data class BiometricChallengeResponse(val challenge: String, val expiresIn: Int)

@Serializable
data class BiometricLoginRequest(
    val credentialId: String,
    val challenge: String,
    val signature: String,
)

@Serializable
data class DeviceBiometricResponse(
    val deviceId: String,
    val deviceName: String,
    val keyAlgorithm: String,
    val createdAt: String,
    val lastUsedAt: String,
    val lockedUntil: String?,
)

@Serializable
data class TokenResponse(
    val userId: String,
    val accessToken: String,
    val refreshToken: String,
    val expiresIn: Int,
)

@Serializable
data class DevicePinResponse(
    val deviceId: String,
    val deviceName: String,
    val createdAt: String,
    val lastUsedAt: String,
    val lockedUntil: String?,
)
