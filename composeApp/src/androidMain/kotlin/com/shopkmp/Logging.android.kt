package com.shopkmp

import io.github.aakira.napier.DebugAntilog
import io.github.aakira.napier.Napier

internal actual fun configureLogging() {
    if (BuildConfig.DEBUG) Napier.base(DebugAntilog())
}
