package com.shopkmp.profile.ui

import androidx.lifecycle.viewModelScope
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.mvvm.BaseViewModel
import com.shopkmp.mvvm.BaseViewModelDependencies
import com.shopkmp.mvvm.common.UiEvent
import com.shopkmp.mvvm.resolveBaseViewModelDependencies
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.profile.domain.ProfileRepository
import com.shopkmp.uikit.component.shop.EpsProfileContentState
import kotlinx.coroutines.launch

data class ProfileState(val contentState: EpsProfileContentState? = null)

sealed interface ProfileUiEvent : UiEvent {
    data object OnBiometricToggleClicked : ProfileUiEvent
    data object OnLogoutClicked : ProfileUiEvent
}

@Suppress("LongParameterList")
class ProfileViewModel(
    private val authService: AuthService,
    private val profileRepository: ProfileRepository,
    dependencies: BaseViewModelDependencies = resolveBaseViewModelDependencies(),
) : BaseViewModel<ProfileUiEvent, ProfileState>(ProfileState(), dependencies) {
    private val builder = ProfileStateBuilder()
    private var profile = CustomerProfile()
    private var onEdit: () -> Unit = {}
    private var onOrders: () -> Unit = {}
    private var onDevices: () -> Unit = {}
    private var onChangePin: () -> Unit = {}
    private var onLogout: () -> Unit = {}

    fun load(
        onEdit: () -> Unit,
        onOrders: () -> Unit,
        onDevices: () -> Unit,
        onChangePin: () -> Unit,
        onLogout: () -> Unit,
    ) {
        this.onEdit = onEdit
        this.onOrders = onOrders
        this.onDevices = onDevices
        this.onChangePin = onChangePin
        this.onLogout = onLogout
        viewModelScope.launch {
            profile = profileRepository.getProfile()
            publish()
        }
    }

    override fun processUiEvent(event: ProfileUiEvent) {
        when (event) {
            ProfileUiEvent.OnBiometricToggleClicked -> toggleBiometric()
            ProfileUiEvent.OnLogoutClicked -> viewModelScope.launch {
                authService.logout()
                onLogout()
            }
        }
    }

    private fun toggleBiometric() {
        viewModelScope.launch {
            showLoading()
            val result = if (authService.hasConfiguredBiometric()) {
                authService.disableBiometric()
            } else {
                authService.enableBiometric()
            }
            result.onSuccess { publish() }.onFailure {
                showToast(
                    it.message ?: "Не удалось изменить вход по биометрии",
                    isErrorToast = true,
                )
            }
            hideLoading()
        }
    }

    private fun publish() {
        updateState {
            copy(
                contentState = builder.build(
                    profile = profile,
                    biometricAvailable = authService.isBiometricAvailable(),
                    biometricConfigured = authService.hasConfiguredBiometric(),
                    onEdit = onEdit,
                    onOrders = onOrders,
                    onDevices = onDevices,
                    onChangePin = onChangePin,
                    onBiometric = { onUiEvent(ProfileUiEvent.OnBiometricToggleClicked) },
                    onLogout = { onUiEvent(ProfileUiEvent.OnLogoutClicked) },
                ),
            )
        }
    }
}
