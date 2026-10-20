package com.shopkmp.settings

import com.russhwolf.settings.Settings

internal val secureSettingKeys = setOf(
    "backend_access_token",
    "backend_refresh_token",
    "backend_user_id",
    "backend_biometric_credential_id",
)

/**
 * Copies a legacy settings store into the current split stores. The source is
 * cleared only after every value has been copied successfully, so a failed
 * migration remains retryable on the next launch.
 */
internal fun migrateSettingsValues(
    source: Settings,
    regular: Settings,
    secure: Settings,
) {
    val values = source.keys.associateWith { key -> source.getStringOrNull(key) }
    values.forEach { (key, value) ->
        if (value == null) return@forEach
        if (key in secureSettingKeys) {
            secure.putString(key, value)
        } else {
            regular.putString(key, value)
        }
    }
    source.clear()
}

/**
 * Last-resort mode never keeps credential material in disk-backed settings.
 */
internal fun insecureFallback(regular: Settings): AppSettings {
    secureSettingKeys.forEach(regular::remove)
    return AppSettings(
        settings = regular,
        secureSettings = MemorySettings(),
        isSecure = false,
    )
}
