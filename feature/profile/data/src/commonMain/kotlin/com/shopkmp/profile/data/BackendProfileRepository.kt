package com.shopkmp.profile.data

import com.shopkmp.network.BackendSession
import com.shopkmp.network.profile.AddressDto
import com.shopkmp.network.profile.ProfileService
import com.shopkmp.network.profile.UpdateProfileRequest
import com.shopkmp.network.profile.UpsertAddressRequest
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.profile.domain.PinDevice
import com.shopkmp.profile.domain.ProfileRepository
import com.shopkmp.remoteresult.RemoteResult

class BackendProfileRepository(
    private val profileApi: ProfileService,
    private val backendSession: BackendSession,
) : ProfileRepository {

    override suspend fun getProfile(): CustomerProfile {
        if (backendSession.accessToken.isBlank()) return CustomerProfile()

        val addressesById = loadAddresses().associate { it.id to it.toText() }
        return when (val response = profileApi.getProfile()) {
            is RemoteResult.Success ->
                CustomerProfile(
                    fullName = response.data.fullName,
                    email = response.data.email,
                    phone = response.data.phone,
                    defaultAddress = response.data.defaultAddressId?.let(addressesById::get).orEmpty(),
                )

            is RemoteResult.Error -> CustomerProfile()
        }
    }

    override suspend fun updateProfile(fullName: String, phone: String, defaultAddress: String): Result<CustomerProfile> {
        if (backendSession.accessToken.isBlank()) return profileUpdateFailure()

        val currentProfile = when (val profileResponse = profileApi.getProfile()) {
            is RemoteResult.Success -> profileResponse.data
            is RemoteResult.Error -> return profileUpdateFailure()
        }
        val currentDefaultAddressId = currentProfile.defaultAddressId
        val addressRequest = defaultAddress.toAddressRequestOrNull()
        val addressId = if (defaultAddress.isBlank()) {
            null
        } else if (addressRequest == null) {
            return Result.failure(IllegalArgumentException("Адрес должен содержать город, улицу и дом через запятую"))
        } else if (currentDefaultAddressId != null) {
            when (val addressResponse = profileApi.updateAddress(currentDefaultAddressId, addressRequest)) {
                is RemoteResult.Success -> addressResponse.data.id
                is RemoteResult.Error -> return profileUpdateFailure()
            }
        } else {
            when (val addressResponse = profileApi.createAddress(addressRequest)) {
                is RemoteResult.Success -> addressResponse.data.id
                is RemoteResult.Error -> return profileUpdateFailure()
            }
        }

        return when (
            val response = profileApi.updateProfile(
                UpdateProfileRequest(
                    fullName = fullName,
                    phone = phone,
                    defaultAddressId = addressId,
                ),
            )
        ) {
            is RemoteResult.Success ->
                Result.success(
                    CustomerProfile(
                        fullName = response.data.fullName,
                        email = response.data.email,
                        phone = response.data.phone,
                        defaultAddress = defaultAddress,
                    ),
                )

            is RemoteResult.Error -> profileUpdateFailure()
        }
    }

    override suspend fun getPinDevices(): List<PinDevice> {
        if (backendSession.accessToken.isBlank()) return emptyList()

        return when (val response = profileApi.getPinDevices()) {
            is RemoteResult.Success ->
                response.data.map { PinDevice(it.deviceId, it.deviceName, it.lastUsedAt) }

            is RemoteResult.Error -> emptyList()
        }
    }

    private suspend fun loadAddresses(): List<AddressDto> =
        when (val response = profileApi.getAddresses()) {
            is RemoteResult.Success -> response.data
            is RemoteResult.Error -> emptyList()
        }

    private fun AddressDto.toText(): String =
        listOf(city, street, house, apartment)
            .map { it.trim() }
            .filter { it.isNotBlank() }
            .joinToString(", ")
}

private fun profileUpdateFailure(): Result<CustomerProfile> =
    Result.failure(IllegalStateException("Не удалось сохранить профиль"))

internal fun String.toAddressRequestOrNull(): UpsertAddressRequest? {
    if (isBlank()) return null
    val parts = split(',').map(String::trim)
    if (parts.size < 3 || parts.take(3).any(String::isBlank)) return null
    return UpsertAddressRequest(
        city = parts[0],
        street = parts[1],
        house = parts[2],
        apartment = parts.drop(3).joinToString(", "),
    )
}
