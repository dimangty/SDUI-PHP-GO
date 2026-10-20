package com.shopkmp.network.profile

import com.shopkmp.network.api.ApiRoute
import com.shopkmp.network.api.request.GetRequest
import com.shopkmp.network.api.request.PostRequest
import com.shopkmp.network.api.request.PutRequest
import com.shopkmp.remoteresult.RemoteResult

interface ProfileService {
    suspend fun getProfile(): RemoteResult<UserDto>
    suspend fun updateProfile(request: UpdateProfileRequest): RemoteResult<UserDto>
    suspend fun createAddress(request: UpsertAddressRequest): RemoteResult<AddressDto>
    suspend fun updateAddress(addressId: String, request: UpsertAddressRequest): RemoteResult<AddressDto>
    suspend fun getAddresses(): RemoteResult<List<AddressDto>>
    suspend fun getPinDevices(): RemoteResult<List<DevicePinDto>>
}

class ProfileApi internal constructor(
    private val getRequest: GetRequest,
    private val postRequest: PostRequest,
    private val putRequest: PutRequest,
) : ProfileService {
    override suspend fun getProfile(): RemoteResult<UserDto> =
        getRequest.get(path = ApiRoute.PROFILE, requiresAuth = true)

    override suspend fun updateProfile(request: UpdateProfileRequest): RemoteResult<UserDto> =
        putRequest.put(path = ApiRoute.PROFILE, body = request, requiresAuth = true)

    override suspend fun createAddress(request: UpsertAddressRequest): RemoteResult<AddressDto> =
        postRequest.post(path = ApiRoute.ADDRESSES, body = request, requiresAuth = true)

    override suspend fun updateAddress(
        addressId: String,
        request: UpsertAddressRequest,
    ): RemoteResult<AddressDto> =
        putRequest.put(path = "${ApiRoute.ADDRESSES}/$addressId", body = request, requiresAuth = true)

    override suspend fun getAddresses(): RemoteResult<List<AddressDto>> =
        getRequest.get(path = ApiRoute.ADDRESSES, requiresAuth = true)

    override suspend fun getPinDevices(): RemoteResult<List<DevicePinDto>> =
        getRequest.get(path = "${ApiRoute.AUTH}/pin/devices", requiresAuth = true)
}
