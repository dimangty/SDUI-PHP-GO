@file:OptIn(
    kotlinx.cinterop.BetaInteropApi::class,
    kotlinx.cinterop.ExperimentalForeignApi::class,
)

package com.shopkmp.settings

import com.russhwolf.settings.Settings
import com.shopkmp.common.security.copyBytes
import com.shopkmp.common.security.useCF
import com.shopkmp.common.security.withCFDictionary
import kotlinx.cinterop.alloc
import kotlinx.cinterop.addressOf
import kotlinx.cinterop.memScoped
import kotlinx.cinterop.ptr
import kotlinx.cinterop.reinterpret
import kotlinx.cinterop.usePinned
import kotlinx.cinterop.value
import platform.CoreFoundation.CFDataRef
import platform.CoreFoundation.CFTypeRefVar
import platform.Foundation.NSData
import platform.Foundation.create
import platform.Security.SecItemAdd
import platform.Security.SecItemCopyMatching
import platform.Security.SecItemDelete
import platform.Security.errSecItemNotFound
import platform.Security.errSecSuccess
import platform.Security.kSecAttrAccessible
import platform.Security.kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
import platform.Security.kSecAttrAccount
import platform.Security.kSecAttrService
import platform.Security.kSecClass
import platform.Security.kSecClassGenericPassword
import platform.Security.kSecMatchLimit
import platform.Security.kSecMatchLimitOne
import platform.Security.kSecReturnData
import platform.Security.kSecValueData

/**
 * Stable Settings adapter backed directly by Apple's Security.framework.
 * Values are device-bound and are not migrated to another device or restored
 * from a backup.
 */
internal class SecurityKeychainSettings(
    private val service: String,
) : Settings {
    override val keys: Set<String>
        get() = getRaw(KEY_REGISTRY)
            ?.decodeToString()
            ?.lineSequence()
            ?.filter(String::isNotBlank)
            ?.toSet()
            .orEmpty()

    override val size: Int
        get() = keys.size

    override fun clear() {
        keys.forEach(::deleteRaw)
        deleteRaw(KEY_REGISTRY)
    }

    override fun remove(key: String) {
        deleteRaw(key)
        writeRegistry(keys - key)
    }

    override fun hasKey(key: String): Boolean = getRaw(key) != null

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
        getStringOrNull(key)?.let {
            when (it) {
                "true" -> true
                "false" -> false
                else -> null
            }
        }

    override fun putString(key: String, value: String) {
        require(key != KEY_REGISTRY) { "Reserved Keychain account" }
        putRaw(key, value.encodeToByteArray())
        writeRegistry(keys + key)
    }

    override fun getString(key: String, defaultValue: String): String =
        getStringOrNull(key) ?: defaultValue

    override fun getStringOrNull(key: String): String? =
        getRaw(key)?.decodeToString()

    private fun writeRegistry(keys: Set<String>) {
        if (keys.isEmpty()) {
            deleteRaw(KEY_REGISTRY)
        } else {
            putRaw(KEY_REGISTRY, keys.sorted().joinToString("\n").encodeToByteArray())
        }
    }

    private fun putRaw(account: String, value: ByteArray) {
        deleteRaw(account)
        val status = withCFDictionary(
            kSecClass to kSecClassGenericPassword!!,
            kSecAttrService to service,
            kSecAttrAccount to account,
            kSecAttrAccessible to kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly!!,
            kSecValueData to value.toNSData(),
        ) { SecItemAdd(it, null) }
        check(status == errSecSuccess) { "Keychain write failed with status $status" }
    }

    private fun getRaw(account: String): ByteArray? = memScoped {
        val result = alloc<CFTypeRefVar>()
        result.value = null
        val status = withCFDictionary(
            kSecClass to kSecClassGenericPassword!!,
            kSecAttrService to service,
            kSecAttrAccount to account,
            kSecReturnData to true,
            kSecMatchLimit to kSecMatchLimitOne!!,
        ) { SecItemCopyMatching(it, result.ptr) }
        when (status) {
            errSecSuccess -> checkNotNull(result.value).useCF {
                val data: CFDataRef = it.reinterpret()
                data.copyBytes()
            }
            errSecItemNotFound -> null
            else -> error("Keychain read failed with status $status")
        }
    }

    private fun deleteRaw(account: String) {
        val status = withCFDictionary(
            kSecClass to kSecClassGenericPassword!!,
            kSecAttrService to service,
            kSecAttrAccount to account,
        ) { SecItemDelete(it) }
        check(status == errSecSuccess || status == errSecItemNotFound) {
            "Keychain delete failed with status $status"
        }
    }

    private companion object {
        const val KEY_REGISTRY = "__shopkmp_key_registry__"
    }
}

private fun ByteArray.toNSData(): NSData =
    if (isEmpty()) {
        NSData.create(bytes = null, length = 0u)
    } else {
        usePinned { pinned ->
            NSData.create(bytes = pinned.addressOf(0), length = size.toULong())
        }
    }
