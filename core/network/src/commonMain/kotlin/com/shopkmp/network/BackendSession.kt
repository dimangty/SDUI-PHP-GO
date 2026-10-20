package com.shopkmp.network

import com.shopkmp.settings.AppSettings
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

class BackendSession(
    private val appSettings: AppSettings,
) {
    val accessToken: String
        get() = appSettings.getSecureString(KEY_ACCESS_TOKEN)

    val refreshToken: String
        get() = appSettings.getSecureString(KEY_REFRESH_TOKEN)

    val userId: String
        get() = appSettings.getSecureString(KEY_USER_ID)

    private val mutableAccountId = MutableStateFlow(userId)
    val accountIdFlow = mutableAccountId.asStateFlow()

    private val stableDeviceId: String by lazy {
            val current = appSettings.getString(KEY_DEVICE_ID)
            // Migrate the legacy "device-<hex>" identifier to a random UUID v4.
            if (current.isNotBlank() && current.isUuid()) return@lazy current
            val generated = randomUuid()
            appSettings.putString(KEY_DEVICE_ID, generated)
            generated
        }

    /** Explicitly performs the one-time read/generate/persist initialization. */
    fun ensureDeviceId(): String = stableDeviceId

    val isPinConfigured: Boolean
        get() = appSettings.getString(KEY_PIN_CONFIGURED) == "true"

    val isBiometricConfigured: Boolean
        get() = appSettings.getString(KEY_BIOMETRIC_CONFIGURED) == "true"

    val biometricCredentialId: String
        get() = appSettings.getSecureString(KEY_BIOMETRIC_CREDENTIAL_ID)

    fun saveTokens(userId: String, accessToken: String, refreshToken: String) {
        val pinOwner = appSettings.getString(KEY_PIN_USER_ID).ifBlank { this.userId }
        val biometricOwner = appSettings.getString(KEY_BIOMETRIC_USER_ID).ifBlank { this.userId }
        if (isPinConfigured && (pinOwner.isBlank() || pinOwner != userId)) {
            clearPin()
        }
        if (isBiometricConfigured && (biometricOwner.isBlank() || biometricOwner != userId)) {
            clearBiometric()
        }
        appSettings.putSecureString(KEY_USER_ID, userId)
        appSettings.putSecureString(KEY_ACCESS_TOKEN, accessToken)
        appSettings.putSecureString(KEY_REFRESH_TOKEN, refreshToken)
        mutableAccountId.value = userId
    }

    fun markPinConfigured(deviceName: String) {
        appSettings.putString(KEY_PIN_CONFIGURED, "true")
        appSettings.putString(KEY_PIN_USER_ID, userId)
        appSettings.putString(KEY_DEVICE_NAME, deviceName)
        appSettings.putString(KEY_DEVICE_LAST_USED, currentIsoTimestamp())
    }

    fun markPinUsed() {
        appSettings.putString(KEY_DEVICE_LAST_USED, currentIsoTimestamp())
    }

    fun markBiometricConfigured(credentialId: String = biometricCredentialId) {
        if (credentialId.isBlank()) {
            clearBiometric()
            return
        }
        appSettings.putString(KEY_BIOMETRIC_CONFIGURED, "true")
        appSettings.putString(KEY_BIOMETRIC_USER_ID, userId)
        appSettings.putSecureString(KEY_BIOMETRIC_CREDENTIAL_ID, credentialId)
    }

    fun clearBiometric() {
        appSettings.remove(KEY_BIOMETRIC_CONFIGURED)
        appSettings.remove(KEY_BIOMETRIC_USER_ID)
        appSettings.removeSecure(KEY_BIOMETRIC_CREDENTIAL_ID)
    }

    fun clearPin() {
        appSettings.remove(KEY_PIN_CONFIGURED)
        appSettings.remove(KEY_PIN_USER_ID)
        appSettings.remove(KEY_DEVICE_NAME)
        appSettings.remove(KEY_DEVICE_LAST_USED)
    }

    fun clearTokens() {
        appSettings.removeSecure(KEY_USER_ID)
        appSettings.removeSecure(KEY_ACCESS_TOKEN)
        appSettings.removeSecure(KEY_REFRESH_TOKEN)
        mutableAccountId.value = ""
    }

    fun currentDeviceName(): String =
        appSettings.getString(KEY_DEVICE_NAME, DEFAULT_DEVICE_NAME)

    fun currentDeviceLastUsed(): String =
        appSettings.getString(KEY_DEVICE_LAST_USED, currentIsoTimestamp())

    private companion object {
        const val KEY_USER_ID = "backend_user_id"
        const val KEY_ACCESS_TOKEN = "backend_access_token"
        const val KEY_REFRESH_TOKEN = "backend_refresh_token"
        const val KEY_DEVICE_ID = "backend_device_id"
        const val KEY_PIN_CONFIGURED = "backend_pin_configured"
        const val KEY_PIN_USER_ID = "backend_pin_user_id"
        const val KEY_BIOMETRIC_CONFIGURED = "backend_biometric_configured"
        const val KEY_BIOMETRIC_USER_ID = "backend_biometric_user_id"
        const val KEY_BIOMETRIC_CREDENTIAL_ID = "backend_biometric_credential_id"
        const val KEY_DEVICE_NAME = "backend_device_name"
        const val KEY_DEVICE_LAST_USED = "backend_device_last_used"
        const val DEFAULT_DEVICE_NAME = ""
    }
}

private fun String.isUuid(): Boolean =
    length == 36 &&
        this[8] == '-' && this[13] == '-' && this[18] == '-' && this[23] == '-' &&
        all { it.isHexDigit() || it == '-' }

private fun Char.isHexDigit(): Boolean =
    this in '0'..'9' || this in 'a'..'f' || this in 'A'..'F'

internal expect fun randomUuid(): String

internal expect fun currentIsoTimestamp(): String
