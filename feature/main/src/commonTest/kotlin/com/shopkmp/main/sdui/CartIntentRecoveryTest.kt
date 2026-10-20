package com.shopkmp.main.sdui

import com.shopkmp.sdui.actions.CheckoutInput
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.sdui.model.*
import com.shopkmp.sdui.network.*
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlin.test.*

@OptIn(ExperimentalCoroutinesApi::class)
class CartIntentRecoveryTest {
    @Test fun unknownCommittedAddThenReadRemoveAndFreshAddReallyAddsWithNewKey() = runTest {
        val repo = IntentCartRepository()
        val vm = SduiViewModel(repo, IntentNative(), backgroundScope)
        vm.open(Destination(ScreenName.product, id = intentProduct)); runCurrent()
        vm.event(SduiEvent.Activate("buy")); runCurrent()
        assertEquals(1, repo.cartQuantity) // The first write committed, but its acknowledgement was lost.
        vm.event(SduiEvent.ReviewCart); runCurrent()
        assertEquals(1, vm.state.value.document!!.state.cartItemsCount)
        vm.event(SduiEvent.Activate("remove")); runCurrent()
        assertEquals(0, repo.cartQuantity)
        vm.open(Destination(ScreenName.product, id = intentProduct)); runCurrent()
        vm.event(SduiEvent.Activate("buy"))
        assertNotNull(vm.state.value.confirmation)
        vm.event(SduiEvent.Confirm); runCurrent() // Explicitly choose a new purchase if recovery is outstanding.
        assertEquals(1, repo.cartQuantity)
        assertEquals(listOf("intent-1", "intent-2"), repo.addKeys)
        assertEquals(1, vm.state.value.document!!.state.cartItemsCount)
        val original = vm.state.value.pendingAdds.single()
        vm.event(SduiEvent.RecoverAdd(original.key)); runCurrent()
        assertEquals(listOf("intent-1", "intent-2", "intent-1"), repo.addKeys)
        assertEquals(1, repo.cartQuantity)
        assertTrue(vm.state.value.pendingAdds.isEmpty())
    }

    @Test fun chosenRecoveryRestoresItsOriginalPayloadAndKeyAfterQuantityEditAndRestoration() = runTest {
        val repo = IntentCartRepository()
        val native = IntentNative()
        val vm = SduiViewModel(repo, native, backgroundScope)
        vm.open(Destination(ScreenName.product, id = intentProduct)); runCurrent()
        vm.event(SduiEvent.Activate("buy")); runCurrent()
        vm.event(SduiEvent.FieldChanged("quantity", "5"))
        val saved = vm.save()
        val restored = SduiViewModel(repo, native, backgroundScope)
        restored.restore(saved); runCurrent()
        assertEquals("5", restored.state.value.fields["product.quantity"])
        restored.event(SduiEvent.Retry); runCurrent()
        assertEquals(listOf("intent-1"), repo.addKeys) // GET reconciliation cannot repeat the mutation.
        restored.event(SduiEvent.RecoverAdd(restored.state.value.pendingAdds.single().key)); runCurrent()
        assertEquals(listOf("intent-1", "intent-1"), repo.addKeys)
        assertEquals(listOf(AddInput(intentProduct, 1), AddInput(intentProduct, 1)), repo.addInputs)
        assertEquals(1, repo.cartQuantity)
        assertTrue(restored.state.value.pendingAdds.isEmpty())
    }

    @Test fun discardedRecoveryIsInertAndAnotherActivationBeginsAFreshIntent() = runTest {
        val repo = IntentCartRepository()
        val vm = SduiViewModel(repo, IntentNative(), backgroundScope)
        vm.open(Destination(ScreenName.product, id = intentProduct)); runCurrent()
        vm.event(SduiEvent.Activate("buy")); runCurrent()
        val previous = vm.state.value.pendingAdds.single().key
        vm.event(SduiEvent.DiscardAddRecovery(previous))
        vm.event(SduiEvent.RecoverAdd(previous)); runCurrent()
        assertEquals(listOf("intent-1"), repo.addKeys)
        vm.event(SduiEvent.Activate("buy")); runCurrent()
        assertEquals(listOf("intent-1", "intent-2"), repo.addKeys)
        assertEquals(2, repo.cartQuantity)
        assertTrue(vm.state.value.pendingAdds.isEmpty())
    }
}

private const val intentProduct = "22222222-2222-4222-8222-222222222222"
private class IntentCartRepository : SduiRepository {
    var cartQuantity = 0
    val addKeys = mutableListOf<String>()
    val addInputs = mutableListOf<AddInput>()
    private val committed = mutableMapOf<String, AddInput>()
    private var sequence = 0
    private var loseFirstAck = true
    override fun newIntentKey() = "intent-${++sequence}"
    override suspend fun load(destination: Destination, deviceId: String) = SduiResult.Success(ScreenDocument(
        1, destination.screen, "a".repeat(64), listOf(Node.Text("count", TextProps("$cartQuantity", TextStyle.Body))),
        listOf(Action.AddToCart("buy", intentProduct, "product.quantity"), Action.UpdateField("quantity", "product.quantity"),
            Action.Cart("remove", CartKind.RemoveFromCart, intentProduct)),
        WireState(true, mapOf("product.quantity" to "1"), cartQuantity), Metadata("Shop", "ru-RU", false, "account"),
    ))
    override suspend fun execute(command: Command, key: String?): SduiResult<Acknowledgement> {
        when (command) {
            is Command.AddToCart -> {
                require(key != null)
                addKeys += key; addInputs += command.input
                val previous = committed[key]
                if (previous == null) { cartQuantity += command.input.quantity; committed[key] = command.input }
                else require(previous == command.input) // Yii's replay/fingerprint semantics.
                if (loseFirstAck) { loseFirstAck = false; return SduiResult.Failure(SduiError.Network) }
            }
            is Command.RemoveFromCart -> cartQuantity = 0
            else -> error("Unexpected commerce command")
        }
        return SduiResult.Success(Acknowledgement(1, command.name, "applied", Destination(ScreenName.cart)))
    }
}
private class IntentNative : NativeSduiAdapter {
    override fun accountId() = "account"
    override fun deviceId() = "device"
    override suspend fun checkout(input: CheckoutInput) = error("Unexpected checkout")
    override suspend fun execute(kind: NativeKind) = error("Unexpected native action")
    override fun commandApplied(command: Command) = Unit
    override fun authenticationLost() = Unit
}
