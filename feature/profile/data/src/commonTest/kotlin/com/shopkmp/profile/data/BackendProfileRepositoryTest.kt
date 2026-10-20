package com.shopkmp.profile.data

import com.shopkmp.network.BackendSession
import com.shopkmp.network.profile.AddressDto
import com.shopkmp.network.profile.DevicePinDto
import com.shopkmp.network.profile.ProfileService
import com.shopkmp.network.profile.UpdateProfileRequest
import com.shopkmp.network.profile.UpsertAddressRequest
import com.shopkmp.network.profile.UserDto
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.settings.AppSettings
import com.shopkmp.settings.MemorySettings
import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

class BackendProfileRepositoryTest {
    @Test
    fun anonymousSessionNeverCallsProfileApi() = runTest {
        val api = FakeProfileService()
        val repository = BackendProfileRepository(api, session())

        assertEquals("", repository.getProfile().email)
        assertTrue(repository.updateProfile("Name", "Phone", "").isFailure)
        assertEquals(emptyList(), repository.getPinDevices())
        assertEquals(0, api.calls)
    }

    @Test
    fun profileMapsDefaultAddressAndPinDevices() = runTest {
        val api = FakeProfileService().apply {
            addresses = RemoteResult.Success(
                listOf(AddressDto("a1", "u1", "Новосибирск", "Ленина", "1", "10", null)),
            )
            profile = RemoteResult.Success(user(defaultAddressId = "a1"))
            devices = RemoteResult.Success(
                listOf(DevicePinDto("d1", "Pixel", "created", "used", null)),
            )
        }
        val repository = BackendProfileRepository(api, session("token"))

        val profile = repository.getProfile()

        assertEquals("Новосибирск, Ленина, 1, 10", profile.defaultAddress)
        assertEquals("user@example.com", profile.email)
        assertEquals("d1", repository.getPinDevices().single().deviceId)
    }

    @Test
    fun updateCreatesNewDefaultAddress() = runTest {
        val api = FakeProfileService().apply {
            profile = RemoteResult.Success(user())
            createdAddress = RemoteResult.Success(
                AddressDto("new", "u1", "Омск", "Мира", "5", "7", null),
            )
            updatedProfile = RemoteResult.Success(user(defaultAddressId = "new"))
        }
        val repository = BackendProfileRepository(api, session("token"))

        val result = repository.updateProfile("Новое имя", "+79990000000", "Омск, Мира, 5, 7")

        assertTrue(result.isSuccess)
        assertEquals("new", api.lastProfileUpdate?.defaultAddressId)
        assertEquals("7", api.lastAddressRequest?.apartment)
    }

    @Test
    fun updateEditsExistingAddressAndHandlesFailures() = runTest {
        val api = FakeProfileService().apply {
            profile = RemoteResult.Success(user(defaultAddressId = "a1"))
            updatedAddress = RemoteResult.Success(
                AddressDto("a1", "u1", "Томск", "Советская", "2", "", null),
            )
            updatedProfile = RemoteResult.Success(user(defaultAddressId = "a1"))
        }
        val repository = BackendProfileRepository(api, session("token"))

        assertTrue(repository.updateProfile("Имя", "Телефон", "Томск, Советская, 2").isSuccess)
        assertEquals("a1", api.updatedAddressId)
        assertTrue(repository.updateProfile("Имя", "Телефон", "некорректно").isFailure)

        api.profile = RemoteResult.Error.NetworkError
        assertTrue(repository.updateProfile("Имя", "Телефон", "").isFailure)
        assertEquals("", repository.getProfile().email)
        api.devices = RemoteResult.Error.TimeoutError
        assertEquals(emptyList(), repository.getPinDevices())
    }
}

private class FakeProfileService : ProfileService {
    var calls = 0
    var profile: RemoteResult<UserDto> = RemoteResult.Error.NetworkError
    var updatedProfile: RemoteResult<UserDto> = RemoteResult.Error.NetworkError
    var addresses: RemoteResult<List<AddressDto>> = RemoteResult.Error.NetworkError
    var devices: RemoteResult<List<DevicePinDto>> = RemoteResult.Error.NetworkError
    var createdAddress: RemoteResult<AddressDto> = RemoteResult.Error.NetworkError
    var updatedAddress: RemoteResult<AddressDto> = RemoteResult.Error.NetworkError
    var lastProfileUpdate: UpdateProfileRequest? = null
    var lastAddressRequest: UpsertAddressRequest? = null
    var updatedAddressId: String? = null

    override suspend fun getProfile(): RemoteResult<UserDto> = profile.also { calls++ }
    override suspend fun updateProfile(request: UpdateProfileRequest): RemoteResult<UserDto> =
        updatedProfile.also {
            calls++
            lastProfileUpdate = request
        }
    override suspend fun createAddress(request: UpsertAddressRequest): RemoteResult<AddressDto> =
        createdAddress.also {
            calls++
            lastAddressRequest = request
        }
    override suspend fun updateAddress(
        addressId: String,
        request: UpsertAddressRequest,
    ): RemoteResult<AddressDto> = updatedAddress.also {
        calls++
        updatedAddressId = addressId
        lastAddressRequest = request
    }
    override suspend fun getAddresses(): RemoteResult<List<AddressDto>> = addresses.also { calls++ }
    override suspend fun getPinDevices(): RemoteResult<List<DevicePinDto>> = devices.also { calls++ }
}

private fun session(accessToken: String = ""): BackendSession {
    val memory = MemorySettings()
    val session = BackendSession(AppSettings(memory))
    if (accessToken.isNotBlank()) session.saveTokens("u1", accessToken, "refresh")
    return session
}

private fun user(defaultAddressId: String? = null) = UserDto(
    id = "u1",
    email = "user@example.com",
    fullName = "Пользователь",
    phone = "+79130000000",
    avatarUrl = null,
    defaultAddressId = defaultAddressId,
    dateOfBirth = null,
    createdAt = "now",
)
