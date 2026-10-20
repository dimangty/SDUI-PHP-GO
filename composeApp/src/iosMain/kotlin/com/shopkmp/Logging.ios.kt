package com.shopkmp

import io.github.aakira.napier.DebugAntilog
import io.github.aakira.napier.Napier
import platform.Foundation.NSBundle

internal actual fun configureLogging() {
    val debugLogging = NSBundle.mainBundle.objectForInfoDictionaryKey("NetworkDebugLogging") as? Boolean ?: false
    if (debugLogging) Napier.base(DebugAntilog())
}
