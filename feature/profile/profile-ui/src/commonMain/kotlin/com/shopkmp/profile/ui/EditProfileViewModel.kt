package com.shopkmp.profile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.profile.domain.ProfileRepository
import com.shopkmp.uikit.component.shop.EpsEditProfileContentState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class EditProfileState(
    val content: EpsEditProfileContentState? = null,
    val error: String? = null,
)

class EditProfileViewModel(
    private val repository: ProfileRepository,
) : ViewModel() {
    private val mutableState = MutableStateFlow(EditProfileState())
    val stateFlow = mutableState.asStateFlow()
    private val builder = EditProfileStateBuilder()
    private var profile = CustomerProfile()
    private var name = ""
    private var phone = ""
    private var address = ""
    private var onBack: () -> Unit = {}
    private var onSaved: () -> Unit = {}

    fun load(onBack: () -> Unit, onSaved: () -> Unit) {
        this.onBack = onBack
        this.onSaved = onSaved
        viewModelScope.launch {
            profile = repository.getProfile()
            name = profile.fullName
            phone = profile.phone
            address = profile.defaultAddress
            publish()
        }
    }

    private fun save() {
        viewModelScope.launch {
            repository.updateProfile(name, phone, address).fold(
                onSuccess = {
                    profile = it
                    onSaved()
                },
                onFailure = {
                    mutableState.value = mutableState.value.copy(
                        error = it.message ?: "Не удалось сохранить профиль",
                    )
                },
            )
        }
    }

    private fun publish() {
        mutableState.value = EditProfileState(
            content = builder.build(
                profile = profile,
                name = name,
                phone = phone,
                address = address,
                onBack = onBack,
                onName = { name = it; publish() },
                onPhone = { phone = it; publish() },
                onAddress = { address = it; publish() },
                onSave = ::save,
            ),
        )
    }
}
