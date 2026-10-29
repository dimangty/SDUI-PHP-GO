package com.shopkmp

import io.github.aakira.napier.DebugAntilog
import io.github.aakira.napier.Antilog
import io.github.aakira.napier.LogLevel
import io.github.aakira.napier.Napier
import com.shopkmp.network.NetworkSettings
import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.cstr
import kotlinx.cinterop.memScoped
import platform.Foundation.NSLog

// Leave room for the tag and part number within iOS's per-entry log limit.
private const val IOS_LOG_CHUNK_BYTES = 800

internal actual fun configureLogging() {
    if (NetworkSettings().isDebugLoggingEnabled) {
        Napier.base(IosNetworkAntilog())
        Napier.i("Backend HTTP logging enabled (Debug)", tag = "HTTP")
    }
}

/** Network diagnostics must reach Xcode AND the iOS unified log, not stdout alone. */
@OptIn(ExperimentalForeignApi::class)
private class IosNetworkAntilog : Antilog() {
    private val fallback = DebugAntilog()

    override fun performLog(priority: LogLevel, tag: String?, throwable: Throwable?, message: String?) {
        if (tag == "HTTP" || tag == "SDUI") {
            val bytes = message.orEmpty().encodeToByteArray()
            var start = 0
            var part = 1
            do {
                var end = minOf(start + IOS_LOG_CHUNK_BYTES, bytes.size)
                // Keep multi-byte UTF-8 characters intact at chunk boundaries.
                while (end < bytes.size && bytes[end].toInt() and 0xC0 == 0x80) end--
                val chunk = bytes.decodeToString(start, end)
                val prefix = if (bytes.size > IOS_LOG_CHUNK_BYTES) "[$tag] [part ${part++}]" else "[$tag]"
                // C varargs require a UTF-8 pointer; a constant format keeps percent signs literal.
                memScoped { NSLog("%s", "$prefix $chunk".cstr.ptr) }
                start = end
            } while (start < bytes.size)
        } else {
            fallback.log(priority, tag, throwable, message)
        }
    }
}
