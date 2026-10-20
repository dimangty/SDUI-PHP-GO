package com.shopkmp.network

import com.shopkmp.settings.AppSettings
import com.shopkmp.settings.MemorySettings
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse

class BackendSessionSecurityTest {
    @Test
    fun saveTokensNeverWritesCredentialsToInsecureSettings() {
        val diskSettings = MemorySettings()
        val secureMemory = MemorySettings()
        val session = BackendSession(
            AppSettings(
                settings = diskSettings,
                secureSettings = secureMemory,
                isSecure = false,
            ),
        )

        session.saveTokens("user-1", "access-secret", "refresh-secret")

        assertFalse(diskSettings.hasKey("backend_user_id"))
        assertFalse(diskSettings.hasKey("backend_access_token"))
        assertFalse(diskSettings.hasKey("backend_refresh_token"))
        assertEquals("user-1", session.userId)
        assertEquals("access-secret", session.accessToken)
        assertEquals("refresh-secret", session.refreshToken)
    }
}
