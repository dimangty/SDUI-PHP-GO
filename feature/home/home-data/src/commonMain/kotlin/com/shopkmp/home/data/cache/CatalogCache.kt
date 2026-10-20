package com.shopkmp.home.data.cache

import app.cash.sqldelight.db.SqlDriver
import kotlinx.serialization.json.Json
import kotlin.time.Clock

/** Persistent serialized catalog cache shared by Android and iOS repositories. */
internal class CatalogCache(
    driver: SqlDriver,
    @PublishedApi internal val json: Json,
    @PublishedApi internal val nowMillis: () -> Long = { Clock.System.now().toEpochMilliseconds() },
) {
    @PublishedApi internal val database = CatalogDatabase(driver)

    internal inline fun <reified T> fresh(key: String, maxAgeMillis: Long = FRESH_TTL_MILLIS): T? =
        read(key, maxAgeMillis)

    internal inline fun <reified T> stale(key: String): T? = read(key, STALE_TTL_MILLIS)

    internal inline fun <reified T> read(key: String, maxAgeMillis: Long): T? {
        val cached = database.catalogCacheQueries.getCache(key).executeAsOneOrNull() ?: return null
        if (nowMillis() - cached.cached_at > maxAgeMillis) return null
        return runCatching { json.decodeFromString<T>(cached.payload) }.getOrNull()
    }

    internal inline fun <reified T> put(key: String, value: T) {
        database.catalogCacheQueries.upsertCache(key, json.encodeToString(value), nowMillis())
        database.catalogCacheQueries.deleteExpired(nowMillis() - STALE_TTL_MILLIS)
    }

    companion object {
        const val FRESH_TTL_MILLIS = 60_000L
        const val STALE_TTL_MILLIS = 7L * 24 * 60 * 60 * 1_000
    }
}
