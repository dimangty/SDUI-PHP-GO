package com.shopkmp.settings

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull

class AppSettingsTest {
    @Test
    fun insecureFallbackNeverWritesCredentialsToDiskSettings() {
        val diskSettings = MemorySettings()
        val memorySettings = MemorySettings()
        val appSettings = AppSettings(
            settings = diskSettings,
            secureSettings = memorySettings,
            isSecure = false,
        )

        appSettings.putString("theme", "dark")
        appSettings.putSecureString("backend_refresh_token", "secret")

        assertEquals("dark", diskSettings.getString("theme", ""))
        assertEquals("", diskSettings.getString("backend_refresh_token", ""))
        assertEquals("secret", memorySettings.getString("backend_refresh_token", ""))
        assertFalse(appSettings.isSecure)
    }

    @Test
    fun legacyMigrationSplitsPublicAndCredentialValuesAndClearsSource() {
        val legacy = MemorySettings().apply {
            putString("theme", "dark")
            putString("backend_access_token", "access")
            putString("backend_refresh_token", "refresh")
            putString("backend_biometric_credential_id", "credential")
        }
        val regular = MemorySettings()
        val secure = MemorySettings()

        migrateSettingsValues(legacy, regular, secure)

        assertEquals("dark", regular.getString("theme", ""))
        assertNull(regular.getStringOrNull("backend_access_token"))
        assertEquals("access", secure.getString("backend_access_token", ""))
        assertEquals("refresh", secure.getString("backend_refresh_token", ""))
        assertEquals("credential", secure.getString("backend_biometric_credential_id", ""))
        assertEquals(0, legacy.size)
    }

    @Test
    fun insecureRecoveryPurgesEveryCredentialFromDisk() {
        val disk = MemorySettings().apply {
            putString("theme", "light")
            secureSettingKeys.forEach { key -> putString(key, "secret") }
        }

        val recovered = insecureFallback(disk)

        assertFalse(recovered.isSecure)
        assertEquals("light", recovered.getString("theme"))
        secureSettingKeys.forEach { key ->
            assertNull(disk.getStringOrNull(key))
            assertEquals("", recovered.getSecureString(key))
        }
    }
}
