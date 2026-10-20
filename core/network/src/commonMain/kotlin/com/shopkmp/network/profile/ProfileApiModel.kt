package com.shopkmp.network.profile

import kotlinx.serialization.Serializable

@Serializable
data class UserDto(
    val id: String,
    val email: String,
    val fullName: String,
    val phone: String,
    val avatarUrl: String?,
    val defaultAddressId: String?,
    val dateOfBirth: String?,
    val createdAt: String,
)

@Serializable
data class UpdateProfileRequest(
    val fullName: String,
    val phone: String,
    val email: String? = null,
    val defaultAddressId: String? = null,
    val dateOfBirth: String? = null,
)

@Serializable
data class UpsertAddressRequest(
    val city: String,
    val street: String,
    val house: String,
    val apartment: String = "",
    val comment: String? = null,
)

@Serializable
data class AddressDto(
    val id: String,
    val userId: String,
    val city: String,
    val street: String,
    val house: String,
    val apartment: String,
    val comment: String?,
)

@Serializable
data class DevicePinDto(
    val deviceId: String,
    val deviceName: String,
    val createdAt: String,
    val lastUsedAt: String,
    val lockedUntil: String?,
)
