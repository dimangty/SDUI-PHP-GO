package com.shopkmp.profile.ui

import com.shopkmp.profile.domain.CustomerProfile
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

class ProfileStateBuilderTest {
    @Test
    fun biometricActionReflectsCurrentConfiguration() {
        val state = ProfileStateBuilder().build(
            profile = CustomerProfile(fullName = "Иван Иванов"),
            biometricAvailable = true,
            biometricConfigured = true,
            onEdit = {},
            onOrders = {},
            onDevices = {},
            onChangePin = {},
            onBiometric = {},
            onLogout = {},
        )
        assertEquals("ИИ", state.profileHeaderState.initials)
        assertTrue(state.actionsState.actions.any { it.title.startsWith("Отключить") })
    }
}
