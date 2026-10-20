@file:OptIn(kotlinx.cinterop.ExperimentalForeignApi::class)

package com.shopkmp.common.security

import kotlinx.cinterop.CPointed
import kotlinx.cinterop.CPointer
import kotlinx.cinterop.addressOf
import kotlinx.cinterop.ptr
import kotlinx.cinterop.readBytes
import kotlinx.cinterop.reinterpret
import kotlinx.cinterop.usePinned
import platform.CoreFoundation.CFDataCreate
import platform.CoreFoundation.CFDataGetBytePtr
import platform.CoreFoundation.CFDataGetLength
import platform.CoreFoundation.CFDataRef
import platform.CoreFoundation.CFDictionaryCreateMutable
import platform.CoreFoundation.CFDictionaryRef
import platform.CoreFoundation.CFDictionarySetValue
import platform.CoreFoundation.CFRelease
import platform.CoreFoundation.CFStringRef
import platform.CoreFoundation.kCFTypeDictionaryKeyCallBacks
import platform.CoreFoundation.kCFTypeDictionaryValueCallBacks
import platform.CoreFoundation.kCFBooleanFalse
import platform.CoreFoundation.kCFBooleanTrue
import platform.Foundation.CFBridgingRetain

/** Consume a +1 Create/Copy/Retain reference, including on exceptional exits. */
inline fun <T : CPointed, R> CPointer<T>.useCF(block: (CPointer<T>) -> R): R =
    try {
        block(this)
    } finally {
        CFRelease(this)
    }

/**
 * A real CFDictionary (toll-free bridged with NSDictionary), never a Kotlin map
 * cast to CPointer. CF callbacks retain keys and values for the entire block.
 * Native pointers are borrowed; Objective-C values are bridged with a temporary
 * +1 retain, balanced after insertion. Nested dictionaries stay native too.
 */
fun <R> withCFDictionary(
    vararg entries: Pair<CFStringRef?, Any>,
    block: (CFDictionaryRef) -> R,
): R = checkNotNull(
    CFDictionaryCreateMutable(
        null,
        entries.size.toLong(),
        kCFTypeDictionaryKeyCallBacks.ptr,
        kCFTypeDictionaryValueCallBacks.ptr,
    ),
).useCF { dictionary ->
    entries.forEach { (key, value) ->
        requireNotNull(key)
        when (value) {
            is CPointer<*> -> CFDictionarySetValue(dictionary, key, value)
            // Kotlin Boolean bridges as NSNumber, but Security requires CFBoolean.
            is Boolean -> CFDictionarySetValue(dictionary, key, if (value) kCFBooleanTrue else kCFBooleanFalse)
            else -> checkNotNull(CFBridgingRetain(value)).useCF { nativeValue ->
                CFDictionarySetValue(dictionary, key, nativeValue)
            }
        }
    }
    block(dictionary)
}

fun <R> withCFData(bytes: ByteArray, block: (CFDataRef) -> R): R {
    val data = if (bytes.isEmpty()) {
        CFDataCreate(null, null, 0)
    } else {
        bytes.usePinned { CFDataCreate(null, it.addressOf(0).reinterpret(), bytes.size.toLong()) }
    }
    return checkNotNull(data).useCF(block)
}

/** Copies bytes while the caller still owns/borrows this CFData reference. */
fun CFDataRef.copyBytes(): ByteArray =
    CFDataGetBytePtr(this)?.readBytes(CFDataGetLength(this).toInt()) ?: byteArrayOf()
