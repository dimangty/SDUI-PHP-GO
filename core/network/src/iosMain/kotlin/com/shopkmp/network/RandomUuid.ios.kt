package com.shopkmp.network

import platform.Foundation.NSDate
import platform.Foundation.NSISO8601DateFormatter
import platform.Foundation.NSUUID

internal actual fun randomUuid(): String = NSUUID().UUIDString().lowercase()

internal actual fun currentIsoTimestamp(): String =
    NSISO8601DateFormatter().stringFromDate(NSDate())
