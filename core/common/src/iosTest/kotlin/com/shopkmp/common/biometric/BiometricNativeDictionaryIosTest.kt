@file:OptIn(kotlinx.cinterop.ExperimentalForeignApi::class)

package com.shopkmp.common.biometric

import com.shopkmp.common.security.copyBytes
import com.shopkmp.common.security.useCF
import kotlinx.cinterop.alloc
import kotlinx.cinterop.memScoped
import kotlinx.cinterop.ptr
import kotlinx.cinterop.reinterpret
import kotlinx.cinterop.value
import platform.CoreFoundation.*
import platform.Foundation.CFBridgingRetain
import platform.Foundation.NSUUID
import platform.LocalAuthentication.LAContext
import platform.Security.*
import kotlin.test.Test
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

class BiometricNativeDictionaryIosTest {
    @Test
    fun creationAttributesUseNativeNestedDictionaryAndRetainAccessControl() {
        val tag = testTag()
        val control = assertNotNull(SecAccessControlCreateWithFlags(
            null, kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
            kSecAccessControlBiometryCurrentSet or kSecAccessControlPrivateKeyUsage, null,
        ))
        control.useCF {
            val baseline = CFGetRetainCount(control)
            withBiometricKeyAttributes(tag, control) { attributes ->
                assertEquals(CFDictionaryGetTypeID(), CFGetTypeID(attributes))
                assertEquals(kSecAttrKeyTypeECSECPrimeRandom, CFDictionaryGetValue(attributes, kSecAttrKeyType))
                assertEquals(kSecAttrTokenIDSecureEnclave, CFDictionaryGetValue(attributes, kSecAttrTokenID))
                val size: CFNumberRef = assertNotNull(CFDictionaryGetValue(attributes, kSecAttrKeySizeInBits)).reinterpret()
                memScoped {
                    val number = alloc<kotlinx.cinterop.IntVar>()
                    assertTrue(CFNumberGetValue(size, kCFNumberIntType, number.ptr))
                    assertEquals(256, number.value)
                }
                val nested: CFDictionaryRef = assertNotNull(CFDictionaryGetValue(attributes, kSecPrivateKeyAttrs)).reinterpret()
                assertEquals(CFDictionaryGetTypeID(), CFGetTypeID(nested))
                assertEquals(kCFBooleanTrue, CFDictionaryGetValue(nested, kSecAttrIsPermanent))
                assertEquals(control, CFDictionaryGetValue(nested, kSecAttrAccessControl))
                assertEquals(baseline + 1, CFGetRetainCount(control))
                assertTag(nested, tag)
            }
            assertEquals(baseline, CFGetRetainCount(control))
        }
    }

    @Test
    fun lookupKeepsNativeTagAndAuthenticationContextAlive() {
        val tag = testTag()
        val context = LAContext()
        assertNotNull(CFBridgingRetain(context)).useCF { nativeContext ->
            withBiometricKeyQuery(tag, context) { query ->
                assertEquals(kSecClassKey, CFDictionaryGetValue(query, kSecClass))
                assertEquals(kSecAttrKeyTypeECSECPrimeRandom, CFDictionaryGetValue(query, kSecAttrKeyType))
                assertEquals(kSecAttrKeyClassPrivate, CFDictionaryGetValue(query, kSecAttrKeyClass))
                assertEquals(kCFBooleanTrue, CFDictionaryGetValue(query, kSecReturnRef))
                assertEquals(kSecMatchLimitOne, CFDictionaryGetValue(query, kSecMatchLimit))
                assertEquals(nativeContext, CFDictionaryGetValue(query, kSecUseAuthenticationContext))
                assertTag(query, tag)
            }
        }
        withBiometricKeyQuery(tag) { deletion ->
            assertTag(deletion, tag)
            assertNull(CFDictionaryGetValue(deletion, kSecReturnRef))
            assertNull(CFDictionaryGetValue(deletion, kSecUseAuthenticationContext))
            assertEquals(errSecItemNotFound, SecItemDelete(deletion))
        }
    }

    @Test
    fun simulatorSecureEnclaveCreationFailsWithoutSoftwareKeyFallback() = memScoped {
        val control = assertNotNull(SecAccessControlCreateWithFlags(
            null, kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
            kSecAccessControlBiometryCurrentSet or kSecAccessControlPrivateKeyUsage, null,
        ))
        control.useCF {
            withBiometricKeyAttributes(testTag(), control) { attributes ->
                val error = alloc<CFErrorRefVar>()
                error.value = null
                val key = SecKeyCreateRandomKey(attributes, error.ptr)
                try {
                    assertNull(key, "Simulator must not supply a software substitute for Secure Enclave")
                    assertNotNull(error.value)
                } finally {
                    key?.let { CFRelease(it) }
                    error.value?.let { CFRelease(it) }
                }
                Unit
            }
        }
    }

    private fun assertTag(dictionary: CFDictionaryRef, expected: String) {
        val data: CFDataRef = assertNotNull(CFDictionaryGetValue(dictionary, kSecAttrApplicationTag)).reinterpret()
        assertEquals(CFDataGetTypeID(), CFGetTypeID(data))
        assertContentEquals(expected.encodeToByteArray(), data.copyBytes())
    }

    private fun testTag() = "com.shopkmp.tests.biometric.${NSUUID().UUIDString}"
}
