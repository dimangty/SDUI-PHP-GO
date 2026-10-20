package com.shopkmp.profile.domain

data class CustomerProfile(
    val fullName: String = "",
    val email: String = "",
    val phone: String = "",
    val defaultAddress: String = "",
)

data class PinDevice(
    val deviceId: String,
    val deviceName: String,
    val lastUsedAt: String,
)
