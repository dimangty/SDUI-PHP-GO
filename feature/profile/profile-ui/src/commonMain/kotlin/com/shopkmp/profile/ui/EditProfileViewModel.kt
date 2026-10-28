package com.shopkmp.profile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.profile.domain.ProfileRepository
import com.shopkmp.uikit.component.shop.EpsEditProfileContentState
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.emptyFlow
import kotlinx.coroutines.launch

data class EditProfileState(
    val content: EpsEditProfileContentState? = null,
    val error: String? = null,
    val loading: Boolean = true,
    val onBack: () -> Unit = {},
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
    private var revision = 0
    private var owner: String? = null
    private var currentAccount: (() -> String)? = null
    private var loadingJob: Job? = null
    private var savingJob: Job? = null
    private var accountJob: Job? = null
    private var saving = false

    /**
     * Without a live account source, legacy callers get editor-lifetime guards only.
     * Account-switching hosts must supply the lookup and matching change flow, as the screen does.
     */
    fun load(
        onBack: () -> Unit,
        onSaved: () -> Unit,
        accountKey: String = "",
        currentAccount: (() -> String)? = null,
        accountChanges: Flow<String> = emptyFlow(),
    ) {
        leave()
        this.currentAccount = currentAccount
        owner = accountKey.takeIf { it.isNotBlank() } ?: currentAccount?.invoke()
        this.onBack = onBack
        this.onSaved = onSaved
        val token = revision
        if (!isCurrent(token)) return
        mutableState.value = EditProfileState(onBack = { cancel(token) })
        // Assign ownership before collecting the StateFlow's immediate first emission.
        accountJob = viewModelScope.launch(start = CoroutineStart.LAZY) {
            accountChanges.collect { isCurrent(token) }
        }
        accountJob?.start()
        loadingJob = viewModelScope.launch {
            try {
                if (!isCurrent(token)) return@launch
                val loaded = repository.getProfile()
                if (!isCurrent(token)) return@launch
                // The repository's existing failure contract returns an empty profile.
                // Every authenticated customer has an email; do not expose a blank editor on failure.
                if (loaded.email.isBlank()) {
                    mutableState.value = EditProfileState(
                        error = "Не удалось загрузить профиль", loading = false, onBack = { cancel(token) },
                    )
                    return@launch
                }
                profile = loaded
                name = loaded.fullName
                phone = loaded.phone
                address = loaded.defaultAddress
                publish(token)
            } catch (error: CancellationException) {
                throw error
            } catch (error: Exception) {
                if (isCurrent(token)) mutableState.value = EditProfileState(
                    error = error.message ?: "Не удалось загрузить профиль",
                    loading = false,
                    onBack = { cancel(token) },
                )
            }
        }
    }

    /** The screen owns this editor lifetime, even when Koin retains its ViewModel. */
    fun leave() {
        revision++
        owner = null
        loadingJob?.cancel(); loadingJob = null
        savingJob?.cancel(); savingJob = null
        accountJob?.cancel(); accountJob = null
        saving = false
        onBack = {}
        onSaved = {}
        profile = CustomerProfile()
        name = ""; phone = ""; address = ""
        mutableState.value = EditProfileState(loading = false)
    }

    private fun isCurrent(token: Int): Boolean {
        if (token != revision) return false
        val liveAccount = currentAccount?.invoke() ?: return true
        if (liveAccount.isBlank() || liveAccount != owner) {
            leave()
            return false
        }
        return true
    }

    private fun cancel(token: Int) {
        if (!isCurrent(token)) return
        val callback = onBack
        leave()
        callback()
    }

    private fun save(token: Int) {
        if (!isCurrent(token) || saving || mutableState.value.content == null) return
        saving = true
        publish(token)
        val savedName = name
        val savedPhone = phone
        val savedAddress = address
        savingJob = viewModelScope.launch {
            try {
                if (!isCurrent(token)) return@launch
                val result = repository.updateProfile(savedName, savedPhone, savedAddress)
                if (!isCurrent(token)) return@launch
                result.fold(
                    onSuccess = {
                        val callback = onSaved
                        leave()
                        callback()
                    },
                    onFailure = {
                        saving = false
                        publish(token, it.message ?: "Не удалось сохранить профиль")
                    },
                )
            } catch (error: CancellationException) {
                if (isCurrent(token)) {
                    saving = false
                    publish(token)
                }
                throw error
            } catch (error: Exception) {
                if (isCurrent(token)) {
                    saving = false
                    publish(token, error.message ?: "Не удалось сохранить профиль")
                }
            }
        }
    }

    private fun publish(token: Int, error: String? = null) {
        if (!isCurrent(token)) return
        mutableState.value = EditProfileState(
            loading = saving,
            error = error,
            onBack = { cancel(token) },
            content = builder.build(
                profile = profile,
                name = name,
                phone = phone,
                address = address,
                onBack = { cancel(token) },
                onName = { if (isCurrent(token) && !saving) { name = it; publish(token) } },
                onPhone = { if (isCurrent(token) && !saving) { phone = it; publish(token) } },
                onAddress = { if (isCurrent(token) && !saving) { address = it; publish(token) } },
                onSave = { save(token) },
                saving = saving,
            ),
        )
    }
}
