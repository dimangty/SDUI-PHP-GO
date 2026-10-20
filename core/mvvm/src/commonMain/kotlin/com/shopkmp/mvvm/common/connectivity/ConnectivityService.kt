package com.shopkmp.mvvm.common.connectivity

interface ConnectivityService {
    suspend fun checkInternetAvailable(
        onAvailable: () -> Unit,
        onNotAvailable: () -> Unit,
    )
}
