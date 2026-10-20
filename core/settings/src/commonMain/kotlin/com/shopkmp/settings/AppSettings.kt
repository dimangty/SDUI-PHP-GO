package com.shopkmp.settings

import com.russhwolf.settings.Settings

class AppSettings(
    private val settings: Settings,
    private val secureSettings: Settings = settings,
    val isSecure: Boolean = true,
) {

    fun getString(key: String, defaultValue: String = ""): String {
        return settings.getString(key, defaultValue)
    }

    fun putString(key: String, value: String) {
        settings.putString(key, value)
    }

    fun remove(key: String) {
        settings.remove(key)
    }

    fun getSecureString(key: String, defaultValue: String = ""): String {
        return secureSettings.getString(key, defaultValue)
    }

    fun putSecureString(key: String, value: String) {
        secureSettings.putString(key, value)
    }

    fun removeSecure(key: String) {
        secureSettings.remove(key)
    }
}
