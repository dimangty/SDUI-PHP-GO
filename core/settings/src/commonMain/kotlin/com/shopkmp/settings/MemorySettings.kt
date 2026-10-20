package com.shopkmp.settings

import com.russhwolf.settings.Settings

/** Process-local settings used when the platform secure store is unavailable. */
class MemorySettings : Settings {
    private val values = mutableMapOf<String, Any>()

    override val keys: Set<String>
        get() = values.keys.toSet()

    override val size: Int
        get() = values.size

    override fun clear() = values.clear()

    override fun remove(key: String) {
        values.remove(key)
    }

    override fun hasKey(key: String): Boolean = key in values

    override fun putInt(key: String, value: Int) = put(key, value)
    override fun getInt(key: String, defaultValue: Int): Int = get(key) ?: defaultValue
    override fun getIntOrNull(key: String): Int? = get(key)
    override fun putLong(key: String, value: Long) = put(key, value)
    override fun getLong(key: String, defaultValue: Long): Long = get(key) ?: defaultValue
    override fun getLongOrNull(key: String): Long? = get(key)
    override fun putString(key: String, value: String) = put(key, value)
    override fun getString(key: String, defaultValue: String): String = get(key) ?: defaultValue
    override fun getStringOrNull(key: String): String? = get(key)
    override fun putFloat(key: String, value: Float) = put(key, value)
    override fun getFloat(key: String, defaultValue: Float): Float = get(key) ?: defaultValue
    override fun getFloatOrNull(key: String): Float? = get(key)
    override fun putDouble(key: String, value: Double) = put(key, value)
    override fun getDouble(key: String, defaultValue: Double): Double = get(key) ?: defaultValue
    override fun getDoubleOrNull(key: String): Double? = get(key)
    override fun putBoolean(key: String, value: Boolean) = put(key, value)
    override fun getBoolean(key: String, defaultValue: Boolean): Boolean = get(key) ?: defaultValue
    override fun getBooleanOrNull(key: String): Boolean? = get(key)

    private fun put(key: String, value: Any) {
        values[key] = value
    }

    @Suppress("UNCHECKED_CAST")
    private fun <T : Any> get(key: String): T? = values[key] as? T
}
