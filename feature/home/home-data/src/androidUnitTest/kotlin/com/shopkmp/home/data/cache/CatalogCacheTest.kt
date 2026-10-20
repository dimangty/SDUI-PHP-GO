package com.shopkmp.home.data.cache

import app.cash.sqldelight.driver.jdbc.sqlite.JdbcSqliteDriver
import kotlinx.serialization.json.Json
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

class CatalogCacheTest {
    @Test
    fun sqlDelightCacheSeparatesFreshAndStaleWindows() {
        val driver = JdbcSqliteDriver(JdbcSqliteDriver.IN_MEMORY)
        CatalogDatabase.Schema.create(driver)
        var now = 1_000L
        val cache = CatalogCache(driver, Json, nowMillis = { now })

        cache.put("products:0", "catalog-v1")
        assertEquals("catalog-v1", cache.fresh<String>("products:0"))

        now += CatalogCache.FRESH_TTL_MILLIS + 1
        assertNull(cache.fresh<String>("products:0"))
        assertEquals("catalog-v1", cache.stale<String>("products:0"))

        now += CatalogCache.STALE_TTL_MILLIS + 1
        assertNull(cache.stale<String>("products:0"))
        driver.close()
    }
}
