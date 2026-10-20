package com.shopkmp.common.device

expect class DeviceService {
    fun openUrl(url: String)
    fun isIOS(): Boolean
    val osType: OsType
}

enum class OsType { Android, IOS }
