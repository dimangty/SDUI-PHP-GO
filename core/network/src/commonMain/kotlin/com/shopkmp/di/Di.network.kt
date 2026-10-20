package com.shopkmp.di

import com.shopkmp.network.DateSerializer
import com.shopkmp.network.DateTimeSerializer
import com.shopkmp.network.BackendSession
import com.shopkmp.network.NetworkSettings
import com.shopkmp.network.auth.AuthApi
import com.shopkmp.network.auth.AuthApiImpl
import com.shopkmp.network.api.request.DeleteRequest
import com.shopkmp.network.api.request.GetRequest
import com.shopkmp.network.api.request.PatchRequest
import com.shopkmp.network.api.request.PostRequest
import com.shopkmp.network.api.request.PutRequest
import com.shopkmp.network.api.request.RequestExecutor
import com.shopkmp.network.profile.ProfileApi
import com.shopkmp.network.profile.ProfileService
import com.shopkmp.network.shop.ShopApi
import com.shopkmp.network.shop.ShopService
import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.json.Json
import kotlinx.serialization.modules.SerializersModule
import org.koin.core.module.Module
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.module

@OptIn(ExperimentalSerializationApi::class)
val moduleNetwork
    get() = module {
        includes(commonModule, platformModule)
    }

internal val commonModule = module {
    single {
        Json {
            encodeDefaults = false
            isLenient = true
            ignoreUnknownKeys = true
            explicitNulls = false
            prettyPrint = true
            serializersModule = SerializersModule {
                contextual(LocalDateTime::class, DateTimeSerializer)
                contextual(LocalDate::class, DateSerializer)
            }
        }
    }

    singleOf(::NetworkSettings)
    singleOf(::BackendSession)
    single {
        RequestExecutor(
            httpClient = get<com.shopkmp.network.api.ApiHttpClient>().client,
            json = get(),
            backendSession = get(),
            isDebugLoggingEnabled = get<NetworkSettings>().isDebugLoggingEnabled,
        )
    }
    singleOf(::GetRequest)
    single<com.shopkmp.network.sdui.SduiFacade> { com.shopkmp.network.sdui.ExecutorSduiFacade(get()) }
    singleOf(::PostRequest)
    singleOf(::PutRequest)
    singleOf(::PatchRequest)
    singleOf(::DeleteRequest)
    single<AuthApi> { AuthApiImpl(get(), get(), get(), get()) }
    singleOf(::ProfileApi)
    single<ProfileService> { get<ProfileApi>() }
    singleOf(::ShopApi)
    single<ShopService> { get<ShopApi>() }
}

internal expect val platformModule: Module
