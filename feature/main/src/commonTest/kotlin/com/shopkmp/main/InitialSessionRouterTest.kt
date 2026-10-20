package com.shopkmp.main

import com.shopkmp.auth.domain.AuthPinDevice
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.auth.domain.SignUpData
import com.shopkmp.navigation.NavigationAction
import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertEquals

class InitialSessionRouterTest {
    @Test
    fun biometricSuccessOpensMainWithoutPinOrRefresh() = runTest {
        val auth = StartupAuthService(
            biometricConfigured = true,
            biometricAvailable = true,
            biometricResult = Result.success(Unit),
            pinConfigured = true,
            savedSession = true,
        )

        assertEquals(NavigationAction.NavigateToMain, InitialSessionRouter(auth).destination())
        assertEquals(0, auth.restoreCalls)
    }

    @Test
    fun biometricFailureFallsBackToPin() = runTest {
        val auth = StartupAuthService(
            biometricConfigured = true,
            biometricAvailable = true,
            biometricResult = Result.failure(IllegalStateException("revoked")),
            pinConfigured = true,
            savedSession = true,
        )

        assertEquals(NavigationAction.NavigateToPinLogin, InitialSessionRouter(auth).destination())
        assertEquals(0, auth.restoreCalls)
    }

    @Test
    fun savedSessionUsesRefreshAndRoutesByResult() = runTest {
        val restored = StartupAuthService(savedSession = true, restoreResult = true)
        val expired = StartupAuthService(savedSession = true, restoreResult = false)

        assertEquals(NavigationAction.NavigateToMain, InitialSessionRouter(restored).destination())
        assertEquals(NavigationAction.NavigateToLogin, InitialSessionRouter(expired).destination())
        assertEquals(1, restored.restoreCalls)
        assertEquals(1, expired.restoreCalls)
    }

    @Test
    fun noFastLoginOrSavedSessionOpensLogin() = runTest {
        val auth = StartupAuthService()

        assertEquals(NavigationAction.NavigateToLogin, InitialSessionRouter(auth).destination())
        assertEquals(0, auth.restoreCalls)
    }

    @Test
    fun loggedOutSessionWithPersistedPinStartsAtLogin() = runTest {
        val auth = StartupAuthService(pinConfigured = true, savedSession = false)

        assertEquals(NavigationAction.NavigateToLogin, InitialSessionRouter(auth).destination())
        assertEquals(0, auth.restoreCalls)
    }

    @Test
    fun loggedOutSessionDoesNotAutomaticallyAuthenticateWithBiometrics() = runTest {
        val auth = StartupAuthService(
            biometricConfigured = true,
            biometricAvailable = true,
            biometricResult = Result.success(Unit),
            pinConfigured = true,
            savedSession = false,
        )

        assertEquals(NavigationAction.NavigateToLogin, InitialSessionRouter(auth).destination())
        assertEquals(0, auth.biometricCalls)
        assertEquals(0, auth.restoreCalls)
    }
}

private class StartupAuthService(
    private val biometricConfigured: Boolean = false,
    private val biometricAvailable: Boolean = false,
    private val biometricResult: Result<Unit> = Result.failure(IllegalStateException("unavailable")),
    private val pinConfigured: Boolean = false,
    private val savedSession: Boolean = false,
    private val restoreResult: Boolean = false,
) : AuthService {
    var restoreCalls = 0
    var biometricCalls = 0

    override fun hasConfiguredPin(): Boolean = pinConfigured
    override fun hasConfiguredBiometric(): Boolean = biometricConfigured
    override fun isBiometricAvailable(): Boolean = biometricAvailable
    override fun hasSavedSession(): Boolean = savedSession
    override suspend fun restoreSession(): Boolean {
        restoreCalls++
        return restoreResult
    }
    override suspend fun login(email: String, password: String): Result<Unit> = Result.success(Unit)
    override suspend fun signUp(data: SignUpData): Result<Unit> = Result.success(Unit)
    override fun shouldCreatePinAfterPasswordLogin(): Boolean = false
    override suspend fun createPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun changePin(currentPin: String, newPin: String): Result<Unit> = Result.success(Unit)
    override suspend fun loginByPin(pin: String): Result<Unit> = Result.success(Unit)
    override suspend fun loginByBiometric(): Result<Unit> {
        biometricCalls++
        return biometricResult
    }
    override suspend fun getPinDevices(): List<AuthPinDevice> = emptyList()
    override suspend fun revokePinDevice(deviceId: String): Result<Unit> = Result.success(Unit)
    override suspend fun logout() = Unit
}
