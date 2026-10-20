package com.shopkmp

import android.app.Application
import org.koin.android.ext.koin.androidContext
import org.koin.core.context.startKoin
import coil3.ImageLoader
import coil3.SingletonImageLoader
import coil3.disk.DiskCache
import coil3.memory.MemoryCache
import okio.Path.Companion.toOkioPath
import io.sentry.android.core.SentryAndroid

class ShopKMPApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        configureLogging()
        if (BuildConfig.SENTRY_DSN.isNotBlank()) {
            SentryAndroid.init(this) { options ->
                options.dsn = BuildConfig.SENTRY_DSN
                options.environment = if (BuildConfig.DEBUG) "debug" else "production"
                options.isSendDefaultPii = false
            }
        }
        SingletonImageLoader.setSafe { context ->
            ImageLoader.Builder(context)
                .memoryCache { MemoryCache.Builder().maxSizePercent(context, 0.25).build() }
                .diskCache {
                    DiskCache.Builder()
                        .directory(cacheDir.resolve("shopkmp-image-cache").toOkioPath())
                        .maxSizeBytes(100L * 1024 * 1024)
                        .build()
                }
                .build()
        }
        startKoin {
            androidContext(this@ShopKMPApplication)
            modules(appModules)
        }
    }
}
