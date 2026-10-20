package com.shopkmp.network.auth

import com.shopkmp.network.api.request.DeleteRequest
import com.shopkmp.network.api.request.GetRequest
import com.shopkmp.network.api.request.PostRequest
import com.shopkmp.network.api.request.PutRequest
import com.shopkmp.network.api.ApiRoute
import com.shopkmp.network.profile.UserDto
import com.shopkmp.remoteresult.RemoteResult
import kotlinx.serialization.Serializable

@Serializable
data class SessionResponse(
    val user: UserDto,
    val device: DeviceSessionDto,
    val cartItemsCount: Int,
)

@Serializable
data class DeviceSessionDto(
    val pinEnabled: Boolean,
    val biometricEnabled: Boolean,
    val biometricCredentialId: String? = null,
)

interface AuthApi {
    suspend fun login(request: LoginRequest): RemoteResult<TokenResponse>
    suspend fun register(request: RegisterRequest): RemoteResult<TokenResponse>
    suspend fun refresh(request: RefreshRequest): RemoteResult<TokenResponse>
    suspend fun getSession(deviceId: String): RemoteResult<SessionResponse>
    suspend fun createPin(request: PinSetRequest): RemoteResult<Unit>
    suspend fun changePin(request: PinChangeRequest): RemoteResult<Unit>
    suspend fun loginByPin(request: PinLoginRequest): RemoteResult<TokenResponse>
    suspend fun getPinDevices(): RemoteResult<List<DevicePinResponse>>
    suspend fun revokePinDevice(deviceId: String): RemoteResult<Unit>
    suspend fun enrollBiometric(request: BiometricEnrollRequest): RemoteResult<BiometricEnrollResponse> =
        error("biometric API is not implemented")
    suspend fun biometricChallenge(request: BiometricChallengeRequest): RemoteResult<BiometricChallengeResponse> =
        error("biometric API is not implemented")
    suspend fun loginByBiometric(request: BiometricLoginRequest): RemoteResult<TokenResponse> =
        error("biometric API is not implemented")
    suspend fun getBiometricDevices(): RemoteResult<List<DeviceBiometricResponse>> =
        error("biometric API is not implemented")
    suspend fun deleteBiometric(deviceId: String): RemoteResult<Unit> =
        error("biometric API is not implemented")
    suspend fun revokeBiometricDevice(deviceId: String): RemoteResult<Unit> =
        error("biometric API is not implemented")
    suspend fun logout(request: LogoutRequest): RemoteResult<Unit>
}

internal class AuthApiImpl(
    private val getRequest: GetRequest,
    private val postRequest: PostRequest,
    private val putRequest: PutRequest,
    private val deleteRequest: DeleteRequest,
) : AuthApi {
    override suspend fun login(request: LoginRequest): RemoteResult<TokenResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/login", body = request)

    override suspend fun register(request: RegisterRequest): RemoteResult<TokenResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/register", body = request)

    override suspend fun refresh(request: RefreshRequest): RemoteResult<TokenResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/refresh", body = request)

    override suspend fun getSession(deviceId: String): RemoteResult<SessionResponse> =
        getRequest.get(path = "${ApiRoute.AUTH}/session?deviceId=$deviceId", requiresAuth = true)

    override suspend fun createPin(request: PinSetRequest): RemoteResult<Unit> =
        postRequest.post(path = "${ApiRoute.AUTH}/pin", body = request, requiresAuth = true)

    override suspend fun changePin(request: PinChangeRequest): RemoteResult<Unit> =
        putRequest.put(path = "${ApiRoute.AUTH}/pin", body = request, requiresAuth = true)

    override suspend fun loginByPin(request: PinLoginRequest): RemoteResult<TokenResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/pin/login", body = request)

    override suspend fun getPinDevices(): RemoteResult<List<DevicePinResponse>> =
        getRequest.get(path = "${ApiRoute.AUTH}/pin/devices", requiresAuth = true)

    override suspend fun revokePinDevice(deviceId: String): RemoteResult<Unit> =
        deleteRequest.delete(path = "${ApiRoute.AUTH}/pin/devices/$deviceId", requiresAuth = true)

    override suspend fun enrollBiometric(request: BiometricEnrollRequest): RemoteResult<BiometricEnrollResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/biometric", body = request, requiresAuth = true)

    override suspend fun biometricChallenge(
        request: BiometricChallengeRequest,
    ): RemoteResult<BiometricChallengeResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/biometric/challenge", body = request)

    override suspend fun loginByBiometric(request: BiometricLoginRequest): RemoteResult<TokenResponse> =
        postRequest.post(path = "${ApiRoute.AUTH}/biometric/login", body = request)

    override suspend fun getBiometricDevices(): RemoteResult<List<DeviceBiometricResponse>> =
        getRequest.get(path = "${ApiRoute.AUTH}/biometric/devices", requiresAuth = true)

    override suspend fun deleteBiometric(deviceId: String): RemoteResult<Unit> =
        deleteRequest.delete(
            path = "${ApiRoute.AUTH}/biometric?deviceId=$deviceId",
            requiresAuth = true,
        )

    override suspend fun revokeBiometricDevice(deviceId: String): RemoteResult<Unit> =
        deleteRequest.delete(path = "${ApiRoute.AUTH}/biometric/devices/$deviceId", requiresAuth = true)

    override suspend fun logout(request: LogoutRequest): RemoteResult<Unit> =
        postRequest.post(path = "${ApiRoute.AUTH}/logout", body = request, requiresAuth = true)
}
