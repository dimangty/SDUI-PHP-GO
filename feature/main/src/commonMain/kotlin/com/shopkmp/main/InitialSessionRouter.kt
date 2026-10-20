package com.shopkmp.main

import com.shopkmp.auth.domain.AuthService
import com.shopkmp.navigation.NavigationAction

/**
 * Deterministic startup state machine. Keeping this outside Compose makes the
 * biometric -> PIN -> refresh/password fallback order directly testable.
 */
internal class InitialSessionRouter(private val authService: AuthService) {
    suspend fun destination(): NavigationAction {
        // Persisted fast-login enrollment survives logout. It remains available
        // explicitly from Login, but must not reopen/authenticate a signed-out app.
        if (!authService.hasSavedSession()) {
            return NavigationAction.NavigateToLogin
        }
        if (
            authService.hasConfiguredBiometric() &&
            authService.isBiometricAvailable() &&
            authService.loginByBiometric().isSuccess
        ) {
            return NavigationAction.NavigateToMain
        }
        if (authService.hasConfiguredPin()) {
            return NavigationAction.NavigateToPinLogin
        }
        return if (authService.restoreSession()) {
            NavigationAction.NavigateToMain
        } else {
            NavigationAction.NavigateToLogin
        }
    }
}
