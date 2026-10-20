package com.shopkmp.main.sdui

import com.shopkmp.sdui.actions.*
import com.shopkmp.sdui.model.*
import com.shopkmp.sdui.network.*
import kotlinx.coroutines.*
import kotlinx.coroutines.test.*
import kotlin.test.*

@OptIn(ExperimentalCoroutinesApi::class)
class SduiViewModelTest {
    @Test fun nativeProfileSaveRebasesGenericFormBeforePhoneOnlySubmit() = runTest {
        var serverName = "Old name"
        val repo = FakeRepository().apply { transform = { profileDocument(it, serverName) } }
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.profile)); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.Activate("edit")); runCurrent()
        assertTrue(vm.state.value.editProfile)
        serverName = "Native saved name"
        vm.nativeProfileSaved(); advanceTimeBy(1001); runCurrent()
        assertEquals("Native saved name", vm.state.value.fields["profile.fullName"])
        vm.event(SduiEvent.FieldChanged("phone", "+79990000000"))
        vm.event(SduiEvent.Activate("save")); runCurrent()
        assertEquals(Command.UpdateProfile(ProfileInput("Native saved name", "+79990000000")), repo.commands.single())
    }

    @Test fun nativeProfileCancelAndFailedSubmitPreserveUnsentValuesAndErrors() = runTest {
        val repo = FakeRepository().apply { transform = { profileDocument(it, "Server name") } }
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.profile)); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.FieldChanged("name", "Unsent name"))
        vm.event(SduiEvent.FieldChanged("phone", "invalid"))
        vm.event(SduiEvent.Activate("save"))
        assertNotNull(vm.state.value.fieldErrors["profile.phone"])
        vm.event(SduiEvent.Activate("edit")); runCurrent()
        vm.back(); advanceTimeBy(1001); runCurrent()
        assertEquals("Unsent name", vm.state.value.fields["profile.fullName"])
        assertEquals("invalid", vm.state.value.fields["profile.phone"])
        assertNotNull(vm.state.value.fieldErrors["profile.phone"])
        assertNotNull(vm.state.value.error)
        assertEquals(0, repo.mutations)
    }

    @Test fun cleanRestoredProfileFieldsAdoptNewServerBaselineAndSuccessfulSubmitRebases() = runTest {
        var serverName = "Initial name"
        val repo = FakeRepository().apply {
            transform = { profileDocument(it, serverName) }
            acknowledgement = Acknowledgement(1, CommandName.UpdateProfile, "applied", Destination(ScreenName.profile))
        }
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.profile)); advanceTimeBy(1001); runCurrent()
        val saved = vm.save()
        serverName = "Updated elsewhere"
        val restored = SduiViewModel(repo, FakeNative(), backgroundScope)
        restored.restore(saved); advanceTimeBy(1001); runCurrent()
        assertEquals("Updated elsewhere", restored.state.value.fields["profile.fullName"])
        restored.event(SduiEvent.FieldChanged("name", "Submitted name"))
        restored.event(SduiEvent.Activate("save")); runCurrent()
        serverName = "Server normalized name"
        advanceTimeBy(2001); runCurrent()
        assertEquals("Server normalized name", restored.state.value.fields["profile.fullName"])
    }

    @Test fun profileNetworkFailureAndNativeCancelKeepTheUnsentDraftAndFailure() = runTest {
        val repo = FakeRepository().apply { transform = { profileDocument(it, "Server name") } }
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.profile)); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.FieldChanged("name", "Unsent name"))
        vm.event(SduiEvent.FieldChanged("phone", "+79990000000"))
        vm.event(SduiEvent.Activate("save")); advanceTimeBy(2001); runCurrent()
        assertEquals(SduiError.Network, vm.state.value.error)
        vm.event(SduiEvent.Activate("edit")); runCurrent()
        vm.back(); advanceTimeBy(1001); runCurrent()
        assertEquals("Unsent name", vm.state.value.fields["profile.fullName"])
        assertEquals("+79990000000", vm.state.value.fields["profile.phone"])
        assertEquals(SduiError.Network, vm.state.value.error)
        assertEquals(1, repo.mutations)
    }

    @Test fun supersedingAndLeavingCancelLoadsWithoutReloadLoops() = runTest {
        val repo = FakeRepository()
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.home)); runCurrent()
        vm.open(Destination(ScreenName.catalog)); runCurrent()
        assertEquals(1, repo.cancelled)
        advanceTimeBy(1001); runCurrent()
        assertEquals(ScreenName.catalog, vm.state.value.document!!.screenId)
        assertEquals(2, repo.loads)
        vm.open(Destination(ScreenName.product, id = productId)); runCurrent()
        vm.leave(); runCurrent()
        assertEquals(2, repo.cancelled)
    }

    @Test fun duplicateSubmitIsSingleFlightAndFailureRetainsInputAndIntentKey() = runTest {
        val repo = FakeRepository()
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.product, id = productId)); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.FieldChanged("quantity", "3"))
        vm.event(SduiEvent.Activate("buy")); vm.event(SduiEvent.Activate("buy")); runCurrent()
        assertEquals(1, repo.mutations)
        advanceTimeBy(1001); runCurrent()
        assertEquals("3", vm.state.value.fields["product.quantity"])
        assertNotNull(vm.state.value.error)
        vm.event(SduiEvent.RecoverAdd(vm.state.value.pendingAdds.single().key)); runCurrent()
        assertEquals(listOf<String?>("key-1", "key-1"), repo.keys)
        vm.leave()
    }

    @Test fun backAndSavedStateRestoreOrderProductAndAccountScopedForms() = runTest {
        val native = FakeNative()
        val vm = SduiViewModel(FakeRepository(), native, backgroundScope)
        vm.open(Destination(ScreenName.orders)); advanceTimeBy(1001); runCurrent()
        vm.open(Destination(ScreenName.order, id = orderId)); advanceTimeBy(1001); runCurrent()
        val snapshot = vm.save()
        val restored = SduiViewModel(FakeRepository(), native, backgroundScope)
        restored.restore(snapshot); runCurrent()
        assertEquals(orderId, restored.state.value.destination.id)
        restored.back(); runCurrent()
        assertEquals(ScreenName.orders, restored.state.value.destination.screen)
        native.account = "different"
        val other = SduiViewModel(FakeRepository(), native, backgroundScope)
        other.restore(snapshot)
        assertEquals(ScreenName.home, other.state.value.destination.screen)
    }

    @Test fun leavingAndReenteringResumesTheCancelledLoad() = runTest {
        val repo = FakeRepository()
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.start(); runCurrent(); vm.leave(); runCurrent()
        vm.start(); advanceTimeBy(1001); runCurrent()
        assertNotNull(vm.state.value.document)
        assertEquals(2, repo.loads)
    }

    @Test fun destructiveCommandsRequireLocalConfirmationAndDismissIsInert() = runTest {
        val repo = FakeRepository()
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.order, id = orderId)); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.Activate("cancel")); runCurrent()
        assertNotNull(vm.state.value.confirmation)
        assertEquals(0, repo.mutations)
        vm.event(SduiEvent.DismissConfirmation); vm.event(SduiEvent.Confirm); runCurrent()
        assertEquals(0, repo.mutations)
        vm.event(SduiEvent.Activate("cancel")); vm.event(SduiEvent.Confirm); runCurrent()
        assertEquals(1, repo.mutations)
        assertEquals(Command.CancelOrder(CancelInput(orderId, true)), repo.commands.single())
    }

    @Test fun unauthorizedClearsRetainedContentFormsAndRoutesToNativeAuth() = runTest {
        val repo = FakeRepository()
        val native = FakeNative()
        val vm = SduiViewModel(repo, native, backgroundScope)
        vm.open(Destination(ScreenName.product, id = productId)); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.FieldChanged("quantity", "3"))
        repo.failure = SduiError.Unauthorized
        vm.event(SduiEvent.Retry); advanceTimeBy(1001); runCurrent()
        assertNull(vm.state.value.document)
        assertTrue(vm.state.value.fields.isEmpty())
        assertEquals(1, native.authLost)
    }

    @Test fun accountChangeClearsContentBeforeAnotherUiEvent() = runTest {
        val native = FakeNative()
        val vm = SduiViewModel(FakeRepository(), native, backgroundScope)
        vm.start(); advanceTimeBy(1001); runCurrent()
        native.account = "other"; native.changes.value = "other"; runCurrent()
        assertNull(vm.state.value.document)
        assertTrue(vm.state.value.fields.isEmpty())
    }

    @Test fun productFromHomeKeepsHomeTabAndBackReturnsHome() = runTest {
        val vm = SduiViewModel(FakeRepository(), FakeNative(), backgroundScope)
        vm.start(); advanceTimeBy(1001); runCurrent()
        vm.open(Destination(ScreenName.product, id = productId)); runCurrent()
        assertEquals(ScreenName.home, vm.state.value.selectedTab)
        vm.back(); runCurrent()
        assertEquals(ScreenName.home, vm.state.value.destination.screen)
    }

    @Test fun cartMutationReloadsEffectiveSourcePageAndRetainsFields() = runTest {
        val repo = FakeRepository().apply {
            transform = { it.copy(state = it.state.copy(collections = Collections(cart = Pagination(2, 2, 3, 5)))) }
            acknowledgement = Acknowledgement(1, CommandName.RemoveFromCart, "applied", Destination(ScreenName.cart))
        }
        val vm = SduiViewModel(repo, FakeNative(), backgroundScope)
        vm.open(Destination(ScreenName.cart, cart = CollectionQuery(9, 2))); advanceTimeBy(1001); runCurrent()
        assertEquals(CollectionQuery(2, 2), vm.state.value.destination.cart)
        vm.event(SduiEvent.FieldChanged("quantity", "3"))
        vm.event(SduiEvent.Activate("remove")); advanceTimeBy(1001); runCurrent()
        assertEquals(Destination(ScreenName.cart, cart = CollectionQuery(2, 2)), repo.destinations.last())
        advanceTimeBy(1001); runCurrent()
        assertEquals("3", vm.state.value.fields["product.quantity"])
    }

    @Test fun logoutExecutesAuthBeforeStateClearingAndNativeRootTransition() = runTest {
        val native = FakeNative()
        val vm = SduiViewModel(FakeRepository(), native, backgroundScope)
        vm.start(); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.Activate("logout")); runCurrent()
        assertEquals(listOf("logout", "root"), native.transitions)
        assertNull(vm.state.value.document)
        assertTrue(vm.state.value.fields.isEmpty())
    }

    @Test fun tabSwitchCannotAbandonSuspendedLogoutAndLeavingStillEndsAtLogin() = runTest {
        val native = FakeNative().apply { logoutGate = CompletableDeferred() }
        val repo = FakeRepository()
        val vm = SduiViewModel(repo, native, backgroundScope)
        vm.start(); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.FieldChanged("quantity", "3"))
        vm.event(SduiEvent.Activate("logout")); runCurrent()
        vm.selectTab(ScreenName.cart); runCurrent()
        vm.leave(); runCurrent()
        assertEquals("", native.account)
        assertEquals(listOf("logout", "root"), native.transitions)
        assertNull(vm.state.value.document)
        assertTrue(vm.state.value.fields.isEmpty())
        assertFalse(vm.save().contains("product.quantity"))
    }

    @Test fun remoteLogoutCancellationStillClearsContentAndReplacesRoot() = runTest {
        val native = FakeNative().apply { logoutGate = CompletableDeferred() }
        val vm = SduiViewModel(FakeRepository(), native, backgroundScope)
        vm.start(); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.Activate("logout")); runCurrent()
        native.logoutGate!!.completeExceptionally(CancellationException("remote cancelled")); runCurrent()
        assertEquals(listOf("logout", "root"), native.transitions)
        assertNull(vm.state.value.document)
        assertTrue(vm.state.value.fields.isEmpty())
    }

    @Test fun cancellingHostLifetimeDuringLogoutStillFinishesNativeRootTransition() = runTest {
        val lifetime = SupervisorJob()
        val native = FakeNative().apply { logoutGate = CompletableDeferred() }
        val vm = SduiViewModel(FakeRepository(), native, CoroutineScope(lifetime + StandardTestDispatcher(testScheduler)))
        vm.start(); advanceTimeBy(1001); runCurrent()
        vm.event(SduiEvent.Activate("logout")); runCurrent()
        lifetime.cancel(); runCurrent()
        assertEquals("", native.account)
        assertEquals(listOf("logout", "root"), native.transitions)
        assertNull(vm.state.value.document)
        assertTrue(vm.state.value.fields.isEmpty())
    }
}

private const val productId = "22222222-2222-4222-8222-222222222222"
private const val orderId = "33333333-3333-4333-8333-333333333333"
private class FakeRepository : SduiRepository {
    var loads = 0; var cancelled = 0; var mutations = 0
    val keys = mutableListOf<String?>()
    val commands = mutableListOf<Command>()
    var failure: SduiError? = null
    var transform: (ScreenDocument) -> ScreenDocument = { it }
    var acknowledgement: Acknowledgement? = null
    val destinations = mutableListOf<Destination>()
    private var sequence = 0
    override fun newIntentKey() = "key-${++sequence}"
    override suspend fun load(destination: Destination, deviceId: String): SduiResult<ScreenDocument> {
        loads++; destinations += destination
        try { delay(1000) } catch (e: CancellationException) { cancelled++; throw e }
        failure?.let { return SduiResult.Failure(it) }
        return SduiResult.Success(transform(ScreenDocument(1, destination.screen, "a".repeat(64), emptyList(),
            listOf(Action.UpdateField("quantity", "product.quantity"), Action.AddToCart("buy", productId, "product.quantity"),
                Action.CancelOrder("cancel", orderId, Confirmation("Cancel?", "Message", "Cancel")),
                Action.Cart("remove", CartKind.RemoveFromCart, productId), Action.Native("logout", NativeKind.Logout)),
            WireState(true, mapOf("product.quantity" to "1"), 0), Metadata("Title", "ru-RU", false, "account"))))
    }
    override suspend fun execute(command: Command, key: String?): SduiResult<Acknowledgement> {
        mutations++; keys += key; commands += command; delay(1000)
        acknowledgement?.let { return SduiResult.Success(it) }
        return SduiResult.Failure(SduiError.Network)
    }
}
private class FakeNative : NativeSduiAdapter {
    var account = "account"
    val changes = kotlinx.coroutines.flow.MutableStateFlow("account")
    override val accountChanges get() = changes
    var authLost = 0
    val transitions = mutableListOf<String>()
    var logoutGate: CompletableDeferred<Unit>? = null
    override fun accountId() = account
    override fun deviceId() = "test-device"
    override suspend fun checkout(input: CheckoutInput) = NativeResult.Completed
    override fun commandApplied(command: Command) = Unit
    override suspend fun execute(kind: NativeKind): NativeResult {
        if (kind == NativeKind.EditProfile) return NativeResult.EditProfile
        if (kind == NativeKind.Logout) {
            transitions += "logout"
            try { logoutGate?.await(); return NativeResult.SignedOut }
            finally { account = ""; changes.value = "" }
        }
        return NativeResult.Completed
    }
    override fun authenticationLost() { authLost++; transitions += "root" }
}

private fun profileDocument(document: ScreenDocument, name: String) = document.copy(
    components = listOf(
        Node.ProfileHeader("header", ProfileProps(name, "buyer@example.com", "+79991234567", null)),
        Node.Field("name", FieldKind.FormField, FieldProps("profile.fullName", "Name", Input.Text, true, false, 255, "name")),
        Node.Field("phone", FieldKind.FormField, FieldProps("profile.phone", "Phone", Input.Phone, true, false, 16, "phone")),
    ),
    actions = listOf(Action.UpdateField("name", "profile.fullName"), Action.UpdateField("phone", "profile.phone"),
        Action.SubmitForm("save", FormId.profile), Action.Native("edit", NativeKind.EditProfile)),
    state = WireState(true, mapOf("profile.fullName" to name, "profile.phone" to "+79991234567"), 0),
)
