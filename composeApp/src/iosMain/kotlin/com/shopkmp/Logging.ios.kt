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
            // C varargs require a UTF-8 char pointer, not an Objective-C object.
            // A constant format also keeps percent signs in JSON literal.
            memScoped { NSLog("%s", "[$tag] ${message.orEmpty()}".cstr.ptr) }
        } else {
            fallback.log(priority, tag, throwable, message)
        }
    }
}
