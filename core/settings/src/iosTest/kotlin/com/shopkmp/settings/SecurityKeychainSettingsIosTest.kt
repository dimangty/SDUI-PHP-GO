@file:OptIn(kotlinx.cinterop.ExperimentalForeignApi::class)

package com.shopkmp.settings

import com.shopkmp.common.security.useCF
import com.shopkmp.common.security.withCFData
import com.shopkmp.common.security.withCFDictionary
import kotlinx.cinterop.alloc
import kotlinx.cinterop.memScoped
import kotlinx.cinterop.ptr
import kotlinx.cinterop.reinterpret
import kotlinx.cinterop.value
import platform.CoreFoundation.CFDictionaryGetValue
import platform.CoreFoundation.CFDictionaryRef
import platform.CoreFoundation.CFStringCompare
import platform.CoreFoundation.CFStringRef
import platform.CoreFoundation.CFTypeRefVar
import platform.CoreFoundation.kCFCompareEqualTo
import platform.Foundation.NSUUID
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
import platform.Security.kSecReturnAttributes
import platform.Security.kSecValueData
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

/** Integration tests: real Security.framework, never the application's service. */
class SecurityKeychainSettingsIosTest {
    @Test
    fun missingReadReturnsNullAndDefaults() = withSettings { settings ->
        assertNull(settings.getStringOrNull("missing"))
        assertEquals("fallback", settings.getString("missing", "fallback"))
        assertFalse(settings.hasKey("missing"))
        assertEquals(emptySet(), settings.keys)
    }

    @Test
    fun stringWriteReadUpdateAndRemovePersistAcrossInstances() = withService { service ->
        val settings = SecurityKeychainSettings(service)
        settings.putString("value", "first — значение 🔐")
        assertEquals("first — значение 🔐", SecurityKeychainSettings(service).getStringOrNull("value"))
        settings.putString("value", "updated")
        assertEquals("updated", settings.getStringOrNull("value"))
        assertEquals(setOf("value"), settings.keys)
        settings.remove("value")
        assertNull(settings.getStringOrNull("value"))
        assertEquals(emptySet(), settings.keys)
        settings.remove("value")
    }

    @Test
    fun emptyStringIsPresentAndCanBeUpdated() = withSettings { settings ->
        settings.putString("empty", "")
        assertEquals("", settings.getStringOrNull("empty"))
        assertTrue(settings.hasKey("empty"))
        settings.putString("empty", "not empty")
        assertEquals("not empty", settings.getStringOrNull("empty"))
        settings.putString("empty", "")
        assertEquals("", settings.getStringOrNull("empty"))
    }

    @Test
    fun boolIntAndRegistrySurviveReopeningAndClear() = withService { service ->
        val settings = SecurityKeychainSettings(service)
        settings.putBoolean("flag", true)
        settings.putInt("number", -42)
        val reopened = SecurityKeychainSettings(service)
        assertEquals(true, reopened.getBooleanOrNull("flag"))
        assertEquals(-42, reopened.getIntOrNull("number"))
        assertEquals(setOf("flag", "number"), reopened.keys)
        assertEquals(2, reopened.size)
        reopened.putBoolean("flag", false)
        assertEquals(false, settings.getBooleanOrNull("flag"))
        reopened.remove("number")
        assertEquals(setOf("flag"), settings.keys)
        reopened.clear()
        assertNull(settings.getBooleanOrNull("flag"))
        assertNull(settings.getIntOrNull("number"))
        assertEquals(0, settings.size)
        settings.clear()
    }

    @Test
    fun clearAndRemoveAreIsolatedByService() = withSettings { first ->
        withSettings { second ->
            first.putString("shared-account", "first")
            second.putString("shared-account", "second")
            first.remove("shared-account")
            assertEquals("second", second.getStringOrNull("shared-account"))
            first.putString("shared-account", "again")
            first.clear()
            assertEquals("second", second.getStringOrNull("shared-account"))
            assertEquals(setOf("shared-account"), second.keys)
        }
    }

    @Test
    fun existingNativeValuesAndRegistryRemainReadableWithOriginalAccessibility() = withService { service ->
        seedNativeItem(service, "existing", "persisted before adapter repair")
        seedNativeItem(service, "__shopkmp_key_registry__", "existing")
        val settings = SecurityKeychainSettings(service)
        assertEquals("persisted before adapter repair", settings.getStringOrNull("existing"))
        assertEquals(setOf("existing"), settings.keys)
        assertDeviceBoundAccessibility(service, "existing")
        settings.putString("existing", "updated through adapter")
        assertEquals("updated through adapter", settings.getStringOrNull("existing"))
        assertDeviceBoundAccessibility(service, "existing")
    }

    @Test
    fun clearOnlyDeletesRegisteredAccountsEvenWithinSameService() = withService { service ->
        seedNativeItem(service, "unrelated-account", "leave intact")
        val settings = SecurityKeychainSettings(service)
        settings.putString("registered", "remove this")
        settings.clear()
        assertNull(settings.getStringOrNull("registered"))
        assertEquals("leave intact", settings.getStringOrNull("unrelated-account"))
        assertEquals(emptySet(), settings.keys)
    }

    private fun seedNativeItem(service: String, account: String, value: String) {
        withCFData(value.encodeToByteArray()) { data ->
            withCFDictionary(
                kSecClass to kSecClassGenericPassword!!,
                kSecAttrService to service,
                kSecAttrAccount to account,
                kSecAttrAccessible to kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly!!,
                kSecValueData to data,
            ) { assertEquals(errSecSuccess, SecItemAdd(it, null)) }
        }
    }

    private fun assertDeviceBoundAccessibility(service: String, account: String) = memScoped {
        val result = alloc<CFTypeRefVar>()
        result.value = null
        withCFDictionary(
            kSecClass to kSecClassGenericPassword!!,
            kSecAttrService to service,
            kSecAttrAccount to account,
            kSecReturnAttributes to true,
            kSecMatchLimit to kSecMatchLimitOne!!,
        ) { assertEquals(errSecSuccess, SecItemCopyMatching(it, result.ptr)) }
        assertNotNull(result.value).useCF {
            val attributes: CFDictionaryRef = it.reinterpret()
            val accessibility: CFStringRef = assertNotNull(CFDictionaryGetValue(attributes, kSecAttrAccessible)).reinterpret()
            assertEquals(kCFCompareEqualTo, CFStringCompare(
                accessibility, kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly, 0u,
            ))
        }
    }

    private fun withSettings(block: (SecurityKeychainSettings) -> Unit) =
        withService { block(SecurityKeychainSettings(it)) }

    private fun withService(block: (String) -> Unit) {
        val service = "com.shopkmp.tests.keychain.${NSUUID().UUIDString}"
        var failure: Throwable? = null
        try {
            block(service)
        } catch (error: Throwable) {
            failure = error
            throw error
        } finally {
            // Only this test's generated namespace; no broad Keychain deletion.
            try {
                // Include independently seeded, unregistered fixture items too.
                withCFDictionary(
                    kSecClass to kSecClassGenericPassword!!,
                    kSecAttrService to service,
                ) {
                    val status = SecItemDelete(it)
                    assertTrue(status == errSecSuccess || status == errSecItemNotFound)
                }
            } catch (cleanup: Throwable) {
                if (failure != null) failure.addSuppressed(cleanup) else throw cleanup
            }
        }
    }
}
