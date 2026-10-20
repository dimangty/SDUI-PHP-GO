package com.shopkmp.devices.ui.list

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.auth.domain.AuthPinDevice
import com.shopkmp.auth.domain.AuthService
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

class DeviceListViewModel(
    private val authService: AuthService,
) : ViewModel() {
    private val mutableState = MutableStateFlow(DeviceListState())
    val stateFlow = mutableState.asStateFlow()
    private val builder = DeviceListStateBuilder()
    private var devices: List<AuthPinDevice> = emptyList()
    private var onBack: () -> Unit = {}

    fun load(onBack: () -> Unit) {
        this.onBack = onBack
        viewModelScope.launch {
            devices = authService.getPinDevices()
            publish()
        }
    }

    private fun revoke(deviceId: String) {
        viewModelScope.launch {
            authService.revokePinDevice(deviceId).onSuccess {
                devices = authService.getPinDevices()
                publish()
            }
        }
    }

    private fun publish() {
        mutableState.value = DeviceListState(
            contentState = builder.build(devices, onBack, ::revoke),
        )
    }
}
