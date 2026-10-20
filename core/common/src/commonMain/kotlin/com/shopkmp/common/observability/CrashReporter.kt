package com.shopkmp.common.observability

fun interface CrashReporter {
    fun capture(error: Throwable)
}

object NoOpCrashReporter : CrashReporter {
    override fun capture(error: Throwable) = Unit
}
