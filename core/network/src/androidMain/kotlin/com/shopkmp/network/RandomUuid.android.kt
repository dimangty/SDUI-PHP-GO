package com.shopkmp.network

import java.time.Instant
import java.util.UUID

internal actual fun randomUuid(): String = UUID.randomUUID().toString()

internal actual fun currentIsoTimestamp(): String = Instant.now().toString()
