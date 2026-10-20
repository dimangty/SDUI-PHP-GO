package com.shopkmp.mvvm.common.connectivity

class ConnectivityServiceImpl : ConnectivityService {
    override suspend fun checkInternetAvailable(
        onAvailable: () -> Unit,
        onNotAvailable: () -> Unit,
    ) {
        onAvailable()
    }
}
