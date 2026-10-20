package com.shopkmp.settings

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.shopkmp.common.observability.CrashReporter
import com.shopkmp.common.observability.NoOpCrashReporter
import com.russhwolf.settings.Settings
import com.russhwolf.settings.SharedPreferencesSettings
import io.github.aakira.napier.Napier
import java.security.KeyStore
import org.koin.android.ext.koin.androidContext
import org.koin.core.module.Module
import org.koin.dsl.module

actual val moduleSettings: Module = module {
    single {
        createAppSettings(androidContext(), getOrNull<CrashReporter>() ?: NoOpCrashReporter)
    }
}

// EncryptedSharedPreferences is retained only as a one-release migration reader.
@Suppress("DEPRECATION")
private fun createAppSettings(context: Context, crashReporter: CrashReporter): AppSettings {
    val settingsFactory = {
        val regular = SharedPreferencesSettings(
            context.getSharedPreferences(SETTINGS_NAME, Context.MODE_PRIVATE),
        )
        val secure = TinkSettings(context)
        migrateLegacySettings(context, regular, secure)
        AppSettings(settings = regular, secureSettings = secure)
    }
    return runCatching(settingsFactory)
        .recoverCatching {
            context.deleteSharedPreferences(TINK_KEYSET_NAME)
            context.deleteSharedPreferences(TINK_VALUES_NAME)
            runCatching {
                val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
                keyStore.deleteEntry(TINK_MASTER_KEY_ALIAS)
            }
            settingsFactory()
        }
        .fold(
            onSuccess = { it },
            onFailure = { error ->
                Napier.e("Encrypted settings are unavailable; credentials are memory-only", error)
                crashReporter.capture(error)
                val preferences = context.getSharedPreferences(SETTINGS_NAME, Context.MODE_PRIVATE)
                insecureFallback(SharedPreferencesSettings(preferences))
            },
        )
}

@Suppress("DEPRECATION")
private fun migrateLegacySettings(context: Context, regular: Settings, secure: Settings) {
    val rawLegacy = context.getSharedPreferences(LEGACY_SETTINGS_NAME, Context.MODE_PRIVATE)
    if (rawLegacy.all.isEmpty()) return
    val legacy = SharedPreferencesSettings(
        EncryptedSharedPreferences.create(
            context,
            LEGACY_SETTINGS_NAME,
            MasterKey.Builder(context)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build(),
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        ),
    )
    migrateSettingsValues(legacy, regular, secure)
    context.deleteSharedPreferences(LEGACY_SETTINGS_NAME)
}

private const val LEGACY_SETTINGS_NAME = "shopkmp_secure_settings"
private const val SETTINGS_NAME = "shopkmp_settings"
private const val TINK_KEYSET_NAME = "shopkmp_tink_keysets"
private const val TINK_VALUES_NAME = "shopkmp_tink_secure_values"
private const val TINK_MASTER_KEY_ALIAS = "shopkmp_tink_master_key"
