package com.shopkmp.home.data

import com.shopkmp.home.domain.ShopRepository
import com.shopkmp.network.NetworkSettings
import com.shopkmp.home.data.cache.CatalogCache
import com.shopkmp.home.data.cache.platformCatalogCacheModule
import app.cash.sqldelight.db.SqlDriver
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.bind
import org.koin.dsl.module

val moduleHomeData = module {
    includes(platformCatalogCacheModule)
    single { CatalogCache(get<SqlDriver>(), get()) }
    single {
        BackendShopRepository(
            shopApi = get(),
            networkBaseUrl = get<NetworkSettings>().serverUrl,
            catalogCache = get(),
        )
    } bind ShopRepository::class
}
