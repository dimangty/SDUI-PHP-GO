package com.shopkmp.settings

import android.content.Context
import android.content.SharedPreferences
import android.util.Base64
import com.google.crypto.tink.Aead
import com.google.crypto.tink.KeyTemplate
import com.google.crypto.tink.RegistryConfiguration
import com.google.crypto.tink.aead.AeadConfig
import com.google.crypto.tink.aead.PredefinedAeadParameters
import com.google.crypto.tink.integration.android.AndroidKeysetManager
import com.russhwolf.settings.Settings

/**
 * Synchronous Settings adapter backed by Tink AEAD. Ciphertexts live in private
 * SharedPreferences; the wrapping key is non-exportable in Android Keystore.
 */
internal class TinkSettings(context: Context) : Settings {
    private val preferences: SharedPreferences =
        context.getSharedPreferences(VALUES_NAME, Context.MODE_PRIVATE)
    private val aead: Aead

    init {
        AeadConfig.register()
        aead = AndroidKeysetManager.Builder()
            .withSharedPref(context, KEYSET_NAME, KEYSET_PREFERENCES_NAME)
            .withKeyTemplate(KeyTemplate.createFrom(PredefinedAeadParameters.AES256_GCM))
            .withMasterKeyUri(MASTER_KEY_URI)
            .build()
            .keysetHandle
            .getPrimitive(RegistryConfiguration.get(), Aead::class.java)
        // Fail during construction if a restored/corrupted value cannot be
        // decrypted, so the caller can perform its recovery path immediately.
        preferences.all.keys.forEach(::getStringOrNull)
    }

    override val keys: Set<String>
        get() = preferences.all.keys
    override val size: Int
        get() = preferences.all.size

    override fun clear() {
        preferences.edit().clear().apply()
    }

    override fun remove(key: String) {
        preferences.edit().remove(key).apply()
    }

    override fun hasKey(key: String): Boolean = preferences.contains(key)

    override fun putInt(key: String, value: Int) = putString(key, value.toString())
    override fun getInt(key: String, defaultValue: Int): Int = getIntOrNull(key) ?: defaultValue
    override fun getIntOrNull(key: String): Int? = getStringOrNull(key)?.toIntOrNull()
    override fun putLong(key: String, value: Long) = putString(key, value.toString())
    override fun getLong(key: String, defaultValue: Long): Long = getLongOrNull(key) ?: defaultValue
    override fun getLongOrNull(key: String): Long? = getStringOrNull(key)?.toLongOrNull()
    override fun putFloat(key: String, value: Float) = putString(key, value.toString())
    override fun getFloat(key: String, defaultValue: Float): Float = getFloatOrNull(key) ?: defaultValue
    override fun getFloatOrNull(key: String): Float? = getStringOrNull(key)?.toFloatOrNull()
    override fun putDouble(key: String, value: Double) = putString(key, value.toString())
    override fun getDouble(key: String, defaultValue: Double): Double = getDoubleOrNull(key) ?: defaultValue
    override fun getDoubleOrNull(key: String): Double? = getStringOrNull(key)?.toDoubleOrNull()
    override fun putBoolean(key: String, value: Boolean) = putString(key, value.toString())
    override fun getBoolean(key: String, defaultValue: Boolean): Boolean =
        getBooleanOrNull(key) ?: defaultValue
    override fun getBooleanOrNull(key: String): Boolean? =
        getStringOrNull(key)?.let { value ->
            when (value) {
                "true" -> true
                "false" -> false
                else -> null
            }
        }

    override fun putString(key: String, value: String) {
        val encrypted = aead.encrypt(value.encodeToByteArray(), associatedData(key))
        preferences.edit().putString(key, Base64.encodeToString(encrypted, Base64.NO_WRAP)).apply()
    }

    override fun getString(key: String, defaultValue: String): String =
        getStringOrNull(key) ?: defaultValue

    override fun getStringOrNull(key: String): String? {
        val encoded = preferences.getString(key, null) ?: return null
        val decrypted = aead.decrypt(Base64.decode(encoded, Base64.NO_WRAP), associatedData(key))
        return decrypted.decodeToString()
    }

    private fun associatedData(key: String): ByteArray =
        "$ASSOCIATED_DATA_PREFIX$key".encodeToByteArray()

    private companion object {
        const val VALUES_NAME = "shopkmp_tink_secure_values"
        const val KEYSET_NAME = "shopkmp_tink_aead_keyset"
        const val KEYSET_PREFERENCES_NAME = "shopkmp_tink_keysets"
        const val MASTER_KEY_URI = "android-keystore://shopkmp_tink_master_key"
        const val ASSOCIATED_DATA_PREFIX = "shopkmp.settings:"
    }
}
