package com.shopkmp.auth.data

import com.shopkmp.auth.domain.AuthError
import com.shopkmp.common.biometric.BiometricKeyService
import com.shopkmp.common.biometric.BiometricPublicKey
import com.shopkmp.network.BackendSession
import com.shopkmp.network.auth.AuthApi
import com.shopkmp.network.auth.BiometricChallengeRequest
import com.shopkmp.network.auth.BiometricChallengeResponse
import com.shopkmp.network.auth.BiometricEnrollRequest
import com.shopkmp.network.auth.BiometricEnrollResponse
import com.shopkmp.network.auth.BiometricLoginRequest
import com.shopkmp.network.auth.DevicePinResponse
import com.shopkmp.network.auth.DeviceSessionDto
import com.shopkmp.network.auth.LoginRequest
import com.shopkmp.network.auth.LogoutRequest
import com.shopkmp.network.auth.PinChangeRequest
import com.shopkmp.network.auth.PinLoginRequest
import com.shopkmp.network.auth.PinSetRequest
import com.shopkmp.network.auth.RefreshRequest
import com.shopkmp.network.auth.RegisterRequest
import com.shopkmp.network.auth.SessionResponse
import com.shopkmp.network.auth.TokenResponse
import com.shopkmp.network.profile.UserDto
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.settings.AppSettings
import com.russhwolf.settings.Settings
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.awaitCancellation
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runCurrent
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

class AuthServiceImplTest {

    private fun createBackendSession(
        accessToken: String = "",
        refreshToken: String = "",
        pinConfigured: Boolean = false,
    ): BackendSession {
        val store = mutableMapOf<String, String>()
        if (accessToken.isNotBlank()) store["backend_access_token"] = accessToken
        if (refreshToken.isNotBlank()) store["backend_refresh_token"] = refreshToken
        if (pinConfigured) store["backend_pin_configured"] = "true"
        store["backend_device_id"] = "11111111-1111-1111-1111-111111111111"
        return BackendSession(AppSettings(object : Settings {
            override fun getString(key: String, defaultValue: String): String = store[key] ?: defaultValue
            override fun putString(key: String, value: String) { store[key] = value }
            override fun remove(key: String) {
                @Suppress("DEPRECATION")
                store.remove(key)
            }
            override fun getInt(key: String, defaultValue: Int): Int = store[key]?.toIntOrNull() ?: defaultValue
            override fun putInt(key: String, value: Int) { store[key] = value.toString() }
            override fun getLong(key: String, defaultValue: Long): Long = store[key]?.toLongOrNull() ?: defaultValue
            override fun putLong(key: String, value: Long) { store[key] = value.toString() }
            override fun getFloat(key: String, defaultValue: Float): Float = store[key]?.toFloatOrNull() ?: defaultValue
            override fun putFloat(key: String, value: Float) { store[key] = value.toString() }
            override fun getDouble(key: String, defaultValue: Double): Double = store[key]?.toDoubleOrNull() ?: defaultValue
            override fun putDouble(key: String, value: Double) { store[key] = value.toString() }
            override fun getBoolean(key: String, defaultValue: Boolean): Boolean = store[key]?.toBoolean() ?: defaultValue
            override fun putBoolean(key: String, value: Boolean) { store[key] = value.toString() }
            override fun hasKey(key: String): Boolean = key in store
            override fun clear() { store.clear() }
            override fun getIntOrNull(key: String): Int? = store[key]?.toIntOrNull()
            override fun getLongOrNull(key: String): Long? = store[key]?.toLongOrNull()
            override fun getStringOrNull(key: String): String? = store[key]
            override fun getFloatOrNull(key: String): Float? = store[key]?.toFloatOrNull()
            override fun getDoubleOrNull(key: String): Double? = store[key]?.toDoubleOrNull()
            override fun getBooleanOrNull(key: String): Boolean? = store[key]?.toBoolean()
            override val keys: Set<String> get() = store.keys
            override val size: Int get() = store.size
        }))
    }

    private fun fakeAuthApi(
        getSessionResult: RemoteResult<SessionResponse>,
        loginResult: RemoteResult<TokenResponse>? = null,
        registerResult: RemoteResult<TokenResponse>? = null,
        createPinResult: RemoteResult<Unit>? = null,
        changePinResult: RemoteResult<Unit>? = null,
        pinLoginResult: RemoteResult<TokenResponse>? = null,
        getPinDevicesResult: RemoteResult<List<DevicePinResponse>>? = null,
        revokePinResult: RemoteResult<Unit>? = null,
        biometricChallengeResult: RemoteResult<BiometricChallengeResponse>? = null,
        biometricLoginResult: RemoteResult<TokenResponse>? = null,
        enrollBiometricResult: RemoteResult<BiometricEnrollResponse>? = null,
        deleteBiometricResult: RemoteResult<Unit>? = null,
        onBiometricChallenge: (BiometricChallengeRequest) -> Unit = {},
        onBiometricLogin: (BiometricLoginRequest) -> Unit = {},
        onLogout: suspend (LogoutRequest) -> Unit = {},
    ): AuthApi =
        object : AuthApi {
            override suspend fun getSession(deviceId: String): RemoteResult<SessionResponse> = getSessionResult
            override suspend fun login(request: LoginRequest): RemoteResult<TokenResponse> =
                loginResult ?: error("not used in tests")
            override suspend fun register(request: RegisterRequest): RemoteResult<TokenResponse> =
                registerResult ?: error("not used in tests")
            override suspend fun refresh(request: RefreshRequest): RemoteResult<TokenResponse> =
                error("not used in tests")
            override suspend fun createPin(request: PinSetRequest): RemoteResult<Unit> =
                createPinResult ?: error("not used in tests")
            override suspend fun changePin(request: PinChangeRequest): RemoteResult<Unit> =
                changePinResult ?: error("not used in tests")
            override suspend fun loginByPin(request: PinLoginRequest): RemoteResult<TokenResponse> =
                pinLoginResult ?: error("not used in tests")
            override suspend fun getPinDevices(): RemoteResult<List<DevicePinResponse>> =
                getPinDevicesResult ?: error("not used in tests")
            override suspend fun revokePinDevice(deviceId: String): RemoteResult<Unit> =
                revokePinResult ?: error("not used in tests")
            override suspend fun biometricChallenge(
                request: BiometricChallengeRequest,
            ): RemoteResult<BiometricChallengeResponse> =
                (biometricChallengeResult ?: error("not used in tests"))
                    .also { onBiometricChallenge(request) }
            override suspend fun loginByBiometric(
                request: BiometricLoginRequest,
            ): RemoteResult<TokenResponse> =
                (biometricLoginResult ?: error("not used in tests"))
                    .also { onBiometricLogin(request) }
            override suspend fun enrollBiometric(
                request: BiometricEnrollRequest,
            ): RemoteResult<BiometricEnrollResponse> =
                enrollBiometricResult ?: error("not used in tests")
            override suspend fun deleteBiometric(deviceId: String): RemoteResult<Unit> =
                deleteBiometricResult ?: error("not used in tests")
            override suspend fun logout(request: LogoutRequest): RemoteResult<Unit> =
                RemoteResult.Success(Unit).also { onLogout(request) }
        }

    private val sessionResponse = SessionResponse(
        user = UserDto(
            id = "u1",
            email = "test@example.com",
            fullName = "Test User",
            phone = "+79130000000",
            avatarUrl = null,
            defaultAddressId = null,
            dateOfBirth = null,
            createdAt = "2026-01-01T00:00:00",
        ),
        device = DeviceSessionDto(pinEnabled = false, biometricEnabled = false),
        cartItemsCount = 0,
    )

    @Test
    fun restoreSessionWithoutTokensReturnsFalse() = runTest {
        val session = createBackendSession()
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(sessionResponse)), session)
        assertFalse(service.restoreSession())
    }

    @Test
    fun restoreSessionSuccessReturnsTrue() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(sessionResponse)), session)
        assertTrue(service.restoreSession())
    }

    @Test
    fun restoreSessionMigratesServerBiometricCredentialId() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val response = sessionResponse.copy(
            device = DeviceSessionDto(
                pinEnabled = false,
                biometricEnabled = true,
                biometricCredentialId = "11111111-2222-4333-8444-555555555555",
            ),
        )
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(response)), session)

        assertTrue(service.restoreSession())
        assertEquals(
            "11111111-2222-4333-8444-555555555555",
            session.biometricCredentialId,
        )
        assertTrue(session.isBiometricConfigured)
    }

    @Test
    fun restoreSessionNetworkErrorKeepsSession() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Error.NetworkError), session)
        assertTrue(service.restoreSession(), "NetworkError should keep session (return true)")
        assertTrue(session.accessToken.isNotBlank(), "Tokens should not be cleared on NetworkError")
    }

    @Test
    fun restoreSessionTimeoutErrorKeepsSession() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Error.TimeoutError), session)
        assertTrue(service.restoreSession(), "TimeoutError should keep session (return true)")
    }

    @Test
    fun restoreSessionSerializationErrorKeepsSession() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Error.SerializationError), session)
        assertTrue(service.restoreSession(), "SerializationError should keep session (return true)")
    }

    @Test
    fun restoreSessionServerSignErrorReturnsFalse() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Error.ServerSignError), session)
        assertFalse(service.restoreSession(), "ServerSignError should force re-login (return false)")
    }

    @Test
    fun restoreSessionServerErrorReturnsFalse() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(
            fakeAuthApi(RemoteResult.Error.ServerError(code = 401, message = "Unauthorized")),
            session,
        )
        assertFalse(service.restoreSession(), "ServerError 401 should force re-login (return false)")
    }

    @Test
    fun hasSavedSessionWithTokens() {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(sessionResponse)), session)
        assertTrue(service.hasSavedSession())
    }

    @Test
    fun hasNoSavedSessionWithoutTokens() {
        val session = createBackendSession()
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(sessionResponse)), session)
        assertFalse(service.hasSavedSession())
    }

    @Test
    fun passwordLoginAndSignUpPersistReturnedTokens() = runTest {
        val loginTokens = TokenResponse("u-login", "access-login", "refresh-login", 300)
        val signUpTokens = TokenResponse("u-signup", "access-signup", "refresh-signup", 300)
        val loginSession = createBackendSession()
        val loginService = AuthServiceImpl(
            fakeAuthApi(
                RemoteResult.Success(sessionResponse),
                loginResult = RemoteResult.Success(loginTokens),
            ),
            loginSession,
        )
        assertTrue(loginService.login("user@example.com", "S3cret!pass").isSuccess)
        assertEquals("u-login", loginSession.userId)
        assertEquals("access-login", loginSession.accessToken)

        val signUpSession = createBackendSession()
        val signUpService = AuthServiceImpl(
            fakeAuthApi(
                RemoteResult.Success(sessionResponse),
                registerResult = RemoteResult.Success(signUpTokens),
            ),
            signUpSession,
        )
        assertTrue(
            signUpService.signUp(
                com.shopkmp.auth.domain.SignUpData(
                    "Иван Петров",
                    "new@example.com",
                    "+79130000000",
                    "S3cret!pass",
                ),
            ).isSuccess,
        )
        assertEquals("refresh-signup", signUpSession.refreshToken)
    }

    @Test
    fun invalidPasswordLoginMapsToTypedCredentialsError() = runTest {
        val service = AuthServiceImpl(
            fakeAuthApi(
                RemoteResult.Success(sessionResponse),
                loginResult = RemoteResult.Error.ServerError(
                    code = 401,
                    message = "invalid",
                    errorCode = "INVALID_CREDENTIALS",
                ),
            ),
            createBackendSession(),
        )

        assertIs<com.shopkmp.auth.domain.AuthError.InvalidCredentials>(
            service.login("user@example.com", "wrong").exceptionOrNull(),
        )
    }

    @Test
    fun pinLifecycleUpdatesLocalConfiguration() = runTest {
        val session = createBackendSession(accessToken = "access")
        val service = AuthServiceImpl(
            fakeAuthApi(
                RemoteResult.Success(sessionResponse),
                createPinResult = RemoteResult.Success(Unit),
                changePinResult = RemoteResult.Success(Unit),
                pinLoginResult = RemoteResult.Success(
                    TokenResponse("u1", "pin-access", "pin-refresh", 300),
                ),
            ),
            session,
        )

        assertTrue(service.shouldCreatePinAfterPasswordLogin())
        assertTrue(service.createPin("135790").isSuccess)
        assertTrue(service.hasConfiguredPin())
        assertTrue(service.changePin("135790", "246801").isSuccess)
        assertTrue(service.loginByPin("246801").isSuccess)
        assertEquals("pin-access", session.accessToken)
    }

    @Test
    fun disableBiometricDeletesPlatformKeyAndLocalBinding() = runTest {
        val session = createBackendSession(accessToken = "access")
        session.markBiometricConfigured("credential")
        val biometric = FakeBiometricKeyService()
        val service = AuthServiceImpl(
            fakeAuthApi(
                RemoteResult.Success(sessionResponse),
                deleteBiometricResult = RemoteResult.Success(Unit),
            ),
            session,
            biometric,
        )

        assertTrue(service.hasConfiguredBiometric())
        assertTrue(service.isBiometricAvailable())
        assertTrue(service.disableBiometric().isSuccess)
        assertFalse(service.hasConfiguredBiometric())
        assertTrue(biometric.deleted)
    }

    @Test
    fun clearTokensRemovesAllTokenData() {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt", pinConfigured = true)
        session.clearTokens()
        assertTrue(session.accessToken.isBlank())
        assertTrue(session.refreshToken.isBlank())
    }

    @Test
    fun pinNotSetClearsLocalPinAndRequiresPasswordLogin() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt", pinConfigured = true)
        val service = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                pinLoginResult = RemoteResult.Error.ServerError(
                    code = 404,
                    message = "PIN is not set",
                    errorCode = "PIN_NOT_SET",
                ),
            ),
            session,
        )

        val error = service.loginByPin("4821").exceptionOrNull()

        assertIs<AuthError.PinNotSet>(error)
        assertFalse(session.isPinConfigured)
    }

    @Test
    fun pin401And423MapToRetryAndLockStates() = runTest {
        val invalidSession = createBackendSession(pinConfigured = true)
        val invalidService = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                pinLoginResult = RemoteResult.Error.ServerError(
                    code = 401,
                    message = "Invalid PIN",
                    errorCode = "INVALID_PIN",
                    details = mapOf("attemptsLeft" to "2"),
                ),
            ),
            invalidSession,
        )
        val invalid = assertIs<AuthError.InvalidPinWithAttempts>(
            invalidService.loginByPin("000000").exceptionOrNull(),
        )
        assertEquals(2, invalid.attemptsLeft)
        assertTrue(invalidSession.isPinConfigured)

        val lockedSession = createBackendSession(pinConfigured = true)
        val lockedService = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                pinLoginResult = RemoteResult.Error.ServerError(
                    code = 423,
                    message = "PIN locked",
                    errorCode = "PIN_LOCKED",
                    details = mapOf("lockedUntil" to "2026-07-26T12:00:00Z"),
                ),
            ),
            lockedSession,
        )
        val locked = assertIs<AuthError.PinLocked>(
            lockedService.loginByPin("000000").exceptionOrNull(),
        )
        assertEquals("2026-07-26T12:00:00Z", locked.lockedUntil)
        assertTrue(lockedSession.isPinConfigured)
    }

    @Test
    fun biometricLoginSignsServerChallengeAndSavesTokens() = runTest {
        val session = createBackendSession()
        session.saveTokens(userId = "u1", accessToken = "expired", refreshToken = "")
        session.markBiometricConfigured("11111111-2222-4333-8444-555555555555")
        session.clearTokens()
        val keyService = FakeBiometricKeyService()
        var challengeRequest: BiometricChallengeRequest? = null
        var loginRequest: BiometricLoginRequest? = null
        val service = AuthServiceImpl(
            authApi = fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                biometricChallengeResult = RemoteResult.Success(
                    BiometricChallengeResponse(challenge = "server-nonce", expiresIn = 120),
                ),
                biometricLoginResult = RemoteResult.Success(
                    TokenResponse(
                        userId = "u1",
                        accessToken = "biometric-access",
                        refreshToken = "biometric-refresh",
                        expiresIn = 300,
                    ),
                ),
                onBiometricChallenge = { challengeRequest = it },
                onBiometricLogin = { loginRequest = it },
            ),
            backendSession = session,
            biometricKeyService = keyService,
        )

        assertTrue(service.loginByBiometric().isSuccess)
        assertEquals("server-nonce", keyService.lastChallenge)
        assertEquals("biometric-access", session.accessToken)
        assertTrue(session.isBiometricConfigured)
        assertEquals(
            "11111111-2222-4333-8444-555555555555",
            assertNotNull(challengeRequest).credentialId,
        )
        assertEquals(
            "11111111-2222-4333-8444-555555555555",
            assertNotNull(loginRequest).credentialId,
        )
    }

    @Test
    fun biometric401And410KeepBindingForRetry() = runTest {
        for ((status, errorCode) in listOf(401 to "INVALID_ASSERTION", 410 to "CHALLENGE_EXPIRED")) {
            val session = createBackendSession()
            session.markBiometricConfigured("11111111-2222-4333-8444-555555555555")
            val keyService = FakeBiometricKeyService()
            val service = AuthServiceImpl(
                authApi = fakeAuthApi(
                    getSessionResult = RemoteResult.Success(sessionResponse),
                    biometricChallengeResult = RemoteResult.Success(
                        BiometricChallengeResponse(challenge = "server-nonce", expiresIn = 120),
                    ),
                    biometricLoginResult = RemoteResult.Error.ServerError(
                        code = status,
                        message = errorCode,
                        errorCode = errorCode,
                    ),
                ),
                backendSession = session,
                biometricKeyService = keyService,
            )

            assertIs<AuthError.Remote>(service.loginByBiometric().exceptionOrNull())
            assertTrue(session.isBiometricConfigured)
            assertFalse(keyService.deleted)
        }
    }

    @Test
    fun biometric423MapsToLockedState() = runTest {
        val session = createBackendSession()
        session.markBiometricConfigured("11111111-2222-4333-8444-555555555555")
        val service = AuthServiceImpl(
            authApi = fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                biometricChallengeResult = RemoteResult.Error.ServerError(
                    code = 423,
                    message = "Biometric locked",
                    errorCode = "BIOMETRIC_LOCKED",
                    details = mapOf("lockedUntil" to "2026-07-26T12:00:00Z"),
                ),
            ),
            backendSession = session,
            biometricKeyService = FakeBiometricKeyService(),
        )

        val locked = assertIs<AuthError.BiometricLocked>(
            service.loginByBiometric().exceptionOrNull(),
        )
        assertEquals("2026-07-26T12:00:00Z", locked.lockedUntil)
        assertTrue(session.isBiometricConfigured)
    }

    @Test
    fun enableBiometricCreatesHardwareKeyAndRegistersPublicKey() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt")
        val keyService = FakeBiometricKeyService()
        val service = AuthServiceImpl(
            authApi = fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                enrollBiometricResult = RemoteResult.Success(
                    BiometricEnrollResponse("11111111-2222-4333-8444-555555555555"),
                ),
            ),
            backendSession = session,
            biometricKeyService = keyService,
        )

        assertTrue(service.enableBiometric().isSuccess)
        assertTrue(keyService.created)
        assertTrue(session.isBiometricConfigured)
        assertEquals(
            "11111111-2222-4333-8444-555555555555",
            session.biometricCredentialId,
        )
    }

    @Test
    fun logoutSendsCurrentDeviceSoServerCanRevokePin() = runTest {
        val session = createBackendSession(accessToken = "at", refreshToken = "rt", pinConfigured = true)
        var request: LogoutRequest? = null
        val service = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                onLogout = { request = it },
            ),
            session,
        )

        service.logout()

        val captured = assertNotNull(request)
        assertEquals("rt", captured.refreshToken)
        assertTrue(session.isPinConfigured)
        assertTrue(session.accessToken.isBlank())
    }

    @OptIn(kotlinx.coroutines.ExperimentalCoroutinesApi::class)
    @Test
    fun cancellingSuspendedLogoutStillClearsAllLocalSessionCredentials() = runTest {
        val session = createBackendSession().apply { saveTokens("u1", "access", "refresh") }
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(sessionResponse), onLogout = { awaitCancellation() }), session)
        val job = launch { service.logout() }
        runCurrent()
        job.cancelAndJoin()
        assertTrue(session.accessToken.isEmpty())
        assertTrue(session.refreshToken.isEmpty())
        assertTrue(session.userId.isEmpty())
    }

    @Test
    fun remoteLogoutCancellationStillClearsLocalSession() = runTest {
        val session = createBackendSession().apply { saveTokens("u1", "access", "refresh") }
        val service = AuthServiceImpl(fakeAuthApi(RemoteResult.Success(sessionResponse), onLogout = { throw CancellationException("remote cancelled") }), session)
        kotlin.test.assertFailsWith<CancellationException> { service.logout() }
        assertTrue(session.accessToken.isEmpty())
        assertTrue(session.refreshToken.isEmpty())
        assertTrue(session.userId.isEmpty())
    }

    @Test
    fun pinDevicesCanBeListedWhenCurrentDeviceHasNoPin() = runTest {
        val session = createBackendSession(accessToken = "at", pinConfigured = false)
        val service = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                getPinDevicesResult = RemoteResult.Success(
                    listOf(
                        DevicePinResponse(
                            deviceId = "22222222-2222-2222-2222-222222222222",
                            deviceName = "Lost phone",
                            createdAt = "2026-01-01T00:00:00Z",
                            lastUsedAt = "2026-01-02T00:00:00Z",
                            lockedUntil = null,
                        ),
                    ),
                ),
            ),
            session,
        )

        val devices = service.getPinDevices()

        assertEquals(1, devices.size)
        assertEquals("Lost phone", devices.single().name)
        assertFalse(devices.single().isCurrent)
    }

    @Test
    fun failedCurrentPinRevocationKeepsLocalPinFlag() = runTest {
        val session = createBackendSession(accessToken = "at", pinConfigured = true)
        val service = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                revokePinResult = RemoteResult.Error.NetworkError,
            ),
            session,
        )

        val result = service.revokePinDevice(session.ensureDeviceId())

        assertTrue(result.isFailure)
        assertTrue(session.isPinConfigured)
    }

    @Test
    fun successfulCurrentPinRevocationClearsLocalPinFlag() = runTest {
        val session = createBackendSession(accessToken = "at", pinConfigured = true)
        val service = AuthServiceImpl(
            fakeAuthApi(
                getSessionResult = RemoteResult.Success(sessionResponse),
                revokePinResult = RemoteResult.Success(Unit),
            ),
            session,
        )

        val result = service.revokePinDevice(session.ensureDeviceId())

        assertTrue(result.isSuccess)
        assertFalse(session.isPinConfigured)
    }

    @Test
    fun tokensForAnotherAccountClearPreviousPinBinding() {
        val session = createBackendSession()
        session.saveTokens(userId = "user-a", accessToken = "access-a", refreshToken = "refresh-a")
        session.markPinConfigured("Phone")
        assertTrue(session.isPinConfigured)

        session.saveTokens(userId = "user-b", accessToken = "access-b", refreshToken = "refresh-b")

        assertFalse(session.isPinConfigured)
        assertEquals("user-b", session.userId)
    }
}

private class FakeBiometricKeyService : BiometricKeyService {
    var created = false
    var deleted = false
    var lastChallenge: String? = null

    override fun isAvailable(): Boolean = true

    override suspend fun createKey(reason: String): BiometricPublicKey {
        created = true
        return BiometricPublicKey(spkiBase64 = "test-spki")
    }

    override suspend fun sign(challenge: String, reason: String): String {
        lastChallenge = challenge
        return "test-signature"
    }

    override fun deleteKey() {
        deleted = true
    }
}
