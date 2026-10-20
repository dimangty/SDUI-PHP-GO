package com.shopkmp.home.data.cache

import android.content.Context
import app.cash.sqldelight.driver.android.AndroidSqliteDriver
import app.cash.sqldelight.db.SqlDriver
import org.koin.dsl.module

internal actual val platformCatalogCacheModule = module {
    single<SqlDriver> {
        AndroidSqliteDriver(
            schema = CatalogDatabase.Schema,
            context = get<Context>(),
            name = "shopkmp-catalog.db",
        )
    }
}
