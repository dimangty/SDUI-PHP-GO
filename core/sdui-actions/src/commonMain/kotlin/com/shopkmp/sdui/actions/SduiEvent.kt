package com.shopkmp.sdui.actions

import com.shopkmp.sdui.model.ScreenDocument

sealed interface SduiEvent {
    data class Activate(val actionId: String) : SduiEvent
    data class FieldChanged(val actionId: String, val value: String) : SduiEvent
    data class QuantityChanged(val actionId: String, val value: Int) : SduiEvent
    data object Confirm : SduiEvent
    data object DismissConfirmation : SduiEvent
    data object Retry : SduiEvent
    data object Back : SduiEvent
    data class RecoverAdd(val intentKey: String) : SduiEvent
    data class DiscardAddRecovery(val intentKey: String) : SduiEvent
    data object ReviewCart : SduiEvent
}

/** All callbacks are constructed locally from typed actions. Unknown controls stay inert. */
class EventRegistry(private val screen: ScreenDocument, private val busy: Boolean, private val send: (SduiEvent) -> Unit) {
    fun enabled(id: String) = !busy && screen.actionEnabled(id)
    fun activate(id: String): (() -> Unit)? = if (enabled(id)) ({ send(SduiEvent.Activate(id)) }) else null
    fun field(id: String, value: String) { if (enabled(id)) send(SduiEvent.FieldChanged(id, value)) }
    fun quantity(id: String, value: Int) { if (enabled(id)) send(SduiEvent.QuantityChanged(id, value)) }
}
