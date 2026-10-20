package com.shopkmp.home.data.cache

import app.cash.sqldelight.driver.native.NativeSqliteDriver
import app.cash.sqldelight.db.SqlDriver
import org.koin.dsl.module

internal actual val platformCatalogCacheModule = module {
    single<SqlDriver> {
        NativeSqliteDriver(
            schema = CatalogDatabase.Schema,
            name = "shopkmp-catalog.db",
        )
    }
}
