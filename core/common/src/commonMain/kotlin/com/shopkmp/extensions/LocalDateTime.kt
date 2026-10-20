@file:OptIn(kotlin.time.ExperimentalTime::class)

package com.shopkmp.extensions

import kotlinx.datetime.Instant
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.LocalDate
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toLocalDateTime

fun LocalDateTime.Companion.now() =
    Instant.fromEpochMilliseconds(0).toLocalDateTime(TimeZone.currentSystemDefault())

fun LocalDate.Companion.now(): LocalDate {
    val dateTime = LocalDateTime.now()
    return LocalDate(dateTime.year, dateTime.month, dateTime.dayOfMonth)
}
