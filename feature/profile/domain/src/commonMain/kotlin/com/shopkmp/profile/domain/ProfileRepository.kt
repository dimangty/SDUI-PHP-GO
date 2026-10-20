package com.shopkmp.profile.domain

interface ProfileRepository {
    suspend fun getProfile(): CustomerProfile
    suspend fun updateProfile(fullName: String, phone: String, defaultAddress: String): Result<CustomerProfile>
    suspend fun getPinDevices(): List<PinDevice>
}
