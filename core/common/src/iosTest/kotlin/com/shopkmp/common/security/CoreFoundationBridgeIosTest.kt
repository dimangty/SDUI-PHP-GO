@file:OptIn(kotlinx.cinterop.ExperimentalForeignApi::class)

package com.shopkmp.common.security

import kotlinx.cinterop.reinterpret
import platform.CoreFoundation.CFBooleanGetTypeID
import platform.CoreFoundation.CFDataRef
import platform.CoreFoundation.CFDictionaryGetValue
import platform.CoreFoundation.CFGetRetainCount
import platform.CoreFoundation.CFGetTypeID
import platform.CoreFoundation.CFNumberGetTypeID
import platform.CoreFoundation.CFStringGetTypeID
import platform.CoreFoundation.kCFBooleanFalse
import platform.CoreFoundation.kCFBooleanTrue
import platform.Security.kSecAttrIsPermanent
import platform.Security.kSecAttrKeySizeInBits
import platform.Security.kSecAttrService
import platform.Security.kSecReturnData
import platform.Security.kSecValueData
import kotlin.test.Test
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith

class CoreFoundationBridgeIosTest {
    @Test
    fun securityValuesHaveNativeBooleanNumberAndStringTypes() {
        withCFDictionary(
            kSecReturnData to true,
            kSecAttrIsPermanent to false,
            kSecAttrKeySizeInBits to 256,
            kSecAttrService to "isolated-test-service",
        ) { dictionary ->
            val yes = CFDictionaryGetValue(dictionary, kSecReturnData)
            val no = CFDictionaryGetValue(dictionary, kSecAttrIsPermanent)
            assertEquals(CFBooleanGetTypeID(), CFGetTypeID(yes))
            assertEquals(CFBooleanGetTypeID(), CFGetTypeID(no))
            assertEquals(kCFBooleanTrue, yes)
            assertEquals(kCFBooleanFalse, no)
            assertEquals(CFNumberGetTypeID(), CFGetTypeID(CFDictionaryGetValue(dictionary, kSecAttrKeySizeInBits)))
            assertEquals(CFStringGetTypeID(), CFGetTypeID(CFDictionaryGetValue(dictionary, kSecAttrService)))
        }
    }

    @Test
    fun dictionaryRetainsBorrowedDataAndReleasesItEvenWhenBlockThrows() {
        withCFData(byteArrayOf(0, 1, -1, 42)) { data ->
            val baseline = CFGetRetainCount(data)
            assertFailsWith<IllegalStateException> {
                withCFDictionary(kSecValueData to data) { dictionary ->
                    assertEquals(baseline + 1, CFGetRetainCount(data))
                    val stored: CFDataRef = CFDictionaryGetValue(dictionary, kSecValueData)!!.reinterpret()
                    assertContentEquals(byteArrayOf(0, 1, -1, 42), stored.copyBytes())
                    error("Exercise exceptional release")
                }
            }
            assertEquals(baseline, CFGetRetainCount(data))
            assertContentEquals(byteArrayOf(0, 1, -1, 42), data.copyBytes())
        }
    }

    @Test
    fun emptyCFDataDoesNotDereferenceElementZero() {
        withCFData(byteArrayOf()) { assertContentEquals(byteArrayOf(), it.copyBytes()) }
    }
}
