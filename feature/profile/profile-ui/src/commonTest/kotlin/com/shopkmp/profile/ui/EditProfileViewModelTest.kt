package com.shopkmp.profile.ui

import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.profile.domain.PinDevice
import com.shopkmp.profile.domain.ProfileRepository
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.withContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertTrue
import kotlin.test.assertFalse
import kotlin.test.assertNull

@OptIn(ExperimentalCoroutinesApi::class)
class EditProfileViewModelTest {
    @Test
    fun legacyLoadWithoutGlobalKoinCanEditAndSaveTheRepositoryProfile() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository()
            val vm = EditProfileViewModel(repo)
            var backs = 0
            var saved = 0
            vm.load({ backs++ }, { saved++ }); runCurrent()
            assertEquals("Покупатель", assertNotNull(vm.stateFlow.value.content).nameTextFieldState.value)
            vm.stateFlow.value.content!!.nameTextFieldState.onValueChange!!("Новое имя")
            vm.stateFlow.value.content!!.saveButtonState.onClick!!(); runCurrent()
            assertEquals(listOf(Triple("Новое имя", "+79991234567", "Омск, Мира, 5, 7")), repo.saves)
            assertEquals(1, saved)
            assertEquals(0, backs)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun loadingBackCallbackCanOnlyCancelItsOwnEditorLifetime() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository().apply { loadGate = CompletableDeferred() }
            val vm = EditProfileViewModel(repo)
            var firstBacks = 0
            var nextBacks = 0
            vm.openEditor({ firstBacks++ }, {}); runCurrent()
            val firstBack = vm.stateFlow.value.onBack
            firstBack()
            repo.loadGate!!.complete(repo.profile); runCurrent()
            assertEquals(1, firstBacks)
            assertNull(vm.stateFlow.value.content)
            repo.loadGate = null
            vm.openEditor({ nextBacks++ }, {}); runCurrent()
            firstBack()
            assertNotNull(vm.stateFlow.value.content)
            assertEquals(0, nextBacks)
            vm.stateFlow.value.onBack()
            assertEquals(1, nextBacks)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun sessionAccountChangeClearsAndCancelsTheEditorBeforeSaveCompletes() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val account = MutableStateFlow("first")
            val repo = EditorRepository().apply { saveGate = CompletableDeferred() }
            val vm = EditProfileViewModel(repo)
            var saved = 0
            vm.load({}, { saved++ }, "first", { account.value }, account); runCurrent()
            vm.stateFlow.value.content!!.saveButtonState.onClick!!(); runCurrent()
            account.value = "second"; runCurrent()
            val afterSwitch = vm.stateFlow.value
            repo.saveGate!!.complete(Result.success(repo.profile)); runCurrent()
            assertNull(afterSwitch.content)
            assertFalse(afterSwitch.loading)
            assertEquals(0, saved)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun unavailableRepositoryProfileCannotBecomeAnEditableBlankForm() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val vm = EditProfileViewModel(EditorRepository().apply { profile = CustomerProfile() })
            vm.openEditor({}, {}); runCurrent()
            assertNotNull(vm.stateFlow.value.error)
            assertNull(vm.stateFlow.value.content)
            assertFalse(vm.stateFlow.value.loading)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun loadPublishesTheRepositoryProfile() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val vm = EditProfileViewModel(EditorRepository())
            vm.openEditor({}, {})
            runCurrent()
            assertEquals("Покупатель", assertNotNull(vm.stateFlow.value.content).nameTextFieldState.value)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun duplicateSaveIsSingleFlightAndFailureKeepsTheDraftForRetry() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository().apply { saveGate = CompletableDeferred() }
            val vm = EditProfileViewModel(repo)
            vm.openEditor({}, {})
            runCurrent()
            vm.stateFlow.value.content!!.nameTextFieldState.onValueChange!!("Новое имя")
            val save = vm.stateFlow.value.content!!.saveButtonState.onClick!!
            save(); save(); runCurrent()
            assertEquals(listOf(Triple("Новое имя", "+79991234567", "Омск, Мира, 5, 7")), repo.saves)
            repo.saveGate!!.complete(Result.failure(IllegalArgumentException("Проверьте адрес")))
            runCurrent()
            assertEquals("Новое имя", vm.stateFlow.value.content!!.nameTextFieldState.value)
            assertEquals("Проверьте адрес", vm.stateFlow.value.error)
            assertTrue(vm.stateFlow.value.content!!.saveButtonState.enabled)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun obsoleteEditorControlsCannotMutateOrSaveTheNextEditor() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository()
            val vm = EditProfileViewModel(repo)
            var firstSaved = 0
            var nextSaved = 0
            vm.openEditor({}, { firstSaved++ }); runCurrent()
            val old = vm.stateFlow.value.content!!
            repo.profile = repo.profile.copy(fullName = "Другой покупатель")
            vm.openEditor({}, { nextSaved++ }); runCurrent()
            old.nameTextFieldState.onValueChange!!("Старый черновик")
            old.saveButtonState.onClick!!(); runCurrent()
            assertEquals("Другой покупатель", vm.stateFlow.value.content!!.nameTextFieldState.value)
            assertTrue(repo.saves.isEmpty())
            assertEquals(0, firstSaved + nextSaved)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun supersededLoadCannotReplaceTheNewEditor() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository().apply { loadGate = CompletableDeferred() }
            val gate = repo.loadGate!!
            val vm = EditProfileViewModel(repo)
            vm.openEditor({}, {}); runCurrent()
            repo.loadGate = null
            repo.profile = repo.profile.copy(fullName = "Новый профиль")
            vm.openEditor({}, {}); runCurrent()
            gate.complete(CustomerProfile(fullName = "Запоздавший профиль")); runCurrent()
            assertEquals("Новый профиль", vm.stateFlow.value.content!!.nameTextFieldState.value)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun readonlyEmailRemainsSelectableWithoutAnEditCallback() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val vm = EditProfileViewModel(EditorRepository())
            vm.openEditor({}, {}); runCurrent()
            val email = vm.stateFlow.value.content!!.emailTextFieldState
            assertTrue(email.readOnly)
            assertTrue(email.enabled)
            assertEquals(null, email.onValueChange)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun cancellingASuspendedSaveOnlyCallsBackAndIgnoresItsLateSuccess() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository().apply { saveGate = CompletableDeferred() }
            val vm = EditProfileViewModel(repo)
            var backs = 0
            var saved = 0
            vm.openEditor({ backs++ }, { saved++ }); runCurrent()
            val old = vm.stateFlow.value.content!!
            old.saveButtonState.onClick!!(); runCurrent()
            assertTrue(vm.stateFlow.value.loading)
            assertFalse(vm.stateFlow.value.content!!.saveButtonState.enabled)
            old.headerState.backButtonState!!.onClick!!()
            repo.saveGate!!.complete(Result.success(repo.profile)); runCurrent()
            old.saveButtonState.onClick!!(); runCurrent()
            assertEquals(1, backs)
            assertEquals(0, saved)
            assertEquals(1, repo.saves.size)
            assertNull(vm.stateFlow.value.content)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun successfulSaveCallsSavedOnceWithoutCallingBackOrSendingEmail() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository()
            val vm = EditProfileViewModel(repo)
            var backs = 0
            var saved = 0
            vm.openEditor({ backs++ }, { saved++ }); runCurrent()
            vm.stateFlow.value.content!!.addressTextFieldState.onValueChange!!("Томск, Ленина, 2, 10")
            val old = vm.stateFlow.value.content!!
            old.saveButtonState.onClick!!(); runCurrent()
            old.saveButtonState.onClick!!(); old.headerState.backButtonState!!.onClick!!(); runCurrent()
            assertEquals(listOf(Triple("Покупатель", "+79991234567", "Томск, Ленина, 2, 10")), repo.saves)
            assertEquals(1, saved)
            assertEquals(0, backs)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun accountSwitchBeforeQueuedSaveAndBeforeCompletionCannotPublishOrNavigate() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository().apply { saveGate = CompletableDeferred() }
            val vm = EditProfileViewModel(repo)
            var account = "first"
            var saved = 0
            vm.load({}, { saved++ }, "first", { account }); runCurrent()
            val staleSave = vm.stateFlow.value.content!!.saveButtonState.onClick!!
            staleSave()
            account = "second"
            runCurrent()
            assertTrue(repo.saves.isEmpty())
            vm.load({}, { saved++ }, "second", { account }); runCurrent()
            vm.stateFlow.value.content!!.saveButtonState.onClick!!(); runCurrent()
            account = "third"
            repo.saveGate!!.complete(Result.success(repo.profile)); runCurrent()
            staleSave(); runCurrent()
            assertEquals(1, repo.saves.size)
            assertEquals(0, saved)
            assertNull(vm.stateFlow.value.content)
        } finally {
            Dispatchers.resetMain()
        }
    }

    @Test
    fun loadFailureIsVisibleAndLeavingRejectsALateLoad() = runTest {
        Dispatchers.setMain(StandardTestDispatcher(testScheduler))
        try {
            val repo = EditorRepository().apply { loadGate = CompletableDeferred() }
            val vm = EditProfileViewModel(repo)
            vm.openEditor({}, {}); runCurrent()
            assertTrue(vm.stateFlow.value.loading)
            repo.loadGate!!.completeExceptionally(IllegalStateException("Загрузка не удалась")); runCurrent()
            assertEquals("Загрузка не удалась", vm.stateFlow.value.error)
            assertFalse(vm.stateFlow.value.loading)
            repo.loadGate = CompletableDeferred()
            vm.openEditor({}, {}); runCurrent()
            vm.leave()
            repo.loadGate!!.complete(repo.profile); runCurrent()
            assertNull(vm.stateFlow.value.content)
            assertNull(vm.stateFlow.value.error)
        } finally {
            Dispatchers.resetMain()
        }
    }
}

private fun EditProfileViewModel.openEditor(onBack: () -> Unit, onSaved: () -> Unit) =
    load(onBack, onSaved, accountKey = "account", currentAccount = { "account" })

private class EditorRepository : ProfileRepository {
    var profile = CustomerProfile("Покупатель", "buyer@example.com", "+79991234567", "Омск, Мира, 5, 7")
    var loadGate: CompletableDeferred<CustomerProfile>? = null
    var saveGate: CompletableDeferred<Result<CustomerProfile>>? = null
    val saves = mutableListOf<Triple<String, String, String>>()
    override suspend fun getProfile() = loadGate?.let { withContext(NonCancellable) { it.await() } } ?: profile
    override suspend fun updateProfile(fullName: String, phone: String, defaultAddress: String): Result<CustomerProfile> {
        saves += Triple(fullName, phone, defaultAddress)
        return saveGate?.let { withContext(NonCancellable) { it.await() } }
            ?: Result.success(profile.copy(fullName = fullName, phone = phone, defaultAddress = defaultAddress))
    }
    override suspend fun getPinDevices() = emptyList<PinDevice>()
}
