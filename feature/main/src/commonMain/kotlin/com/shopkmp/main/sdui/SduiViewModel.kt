package com.shopkmp.main.sdui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.sdui.actions.CheckoutInput
import com.shopkmp.sdui.actions.Forms
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.sdui.model.Action
import com.shopkmp.sdui.model.AddInput
import com.shopkmp.sdui.model.CancelInput
import com.shopkmp.sdui.model.CartKind
import com.shopkmp.sdui.model.CollectionQuery
import com.shopkmp.sdui.model.Command
import com.shopkmp.sdui.model.Confirmation
import com.shopkmp.sdui.model.Destination
import com.shopkmp.sdui.model.FormId
import com.shopkmp.sdui.model.NativeKind
import com.shopkmp.sdui.model.Node
import com.shopkmp.sdui.model.ProductInput
import com.shopkmp.sdui.model.QuantityInput
import com.shopkmp.sdui.model.RevokeInput
import com.shopkmp.sdui.model.RouteKind
import com.shopkmp.sdui.model.ScreenDocument
import com.shopkmp.sdui.model.ScreenName
import com.shopkmp.sdui.model.StatusKind
import com.shopkmp.sdui.model.codePointLength
import com.shopkmp.sdui.model.fieldIds
import com.shopkmp.sdui.model.validateCommand
import com.shopkmp.sdui.model.validateDestination
import com.shopkmp.sdui.network.SduiError
import com.shopkmp.sdui.network.SduiRepository
import com.shopkmp.sdui.network.SduiResult
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.emptyFlow
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

enum class ScreenPhase { Loading, Content, Empty, Error }
data class HostState(
    val destination: Destination = Destination(ScreenName.home),
    val selectedTab: ScreenName = ScreenName.home,
    val phase: ScreenPhase = ScreenPhase.Loading,
    val document: ScreenDocument? = null,
    val fields: Map<String, String> = emptyMap(),
    val fieldErrors: Map<String, String> = emptyMap(),
    val error: SduiError? = null,
    val actionInProgress: Boolean = false,
    val confirmation: Confirmation? = null,
    val canGoBack: Boolean = false,
    val editProfile: Boolean = false,
    val notice: String? = null,
    val pendingAdds: List<PendingAddIntent> = emptyList(),
)
@Serializable data class PendingAddIntent(val key: String, val input: AddInput)
interface NativeSduiAdapter {
    val accountChanges: Flow<String> get() = emptyFlow()
    fun accountId(): String
    fun deviceId(): String
    suspend fun checkout(input: CheckoutInput): NativeResult
    suspend fun execute(kind: NativeKind): NativeResult
    fun commandApplied(command: Command)
    fun authenticationLost()
}
sealed interface NativeResult {
    data object Completed : NativeResult
    data object SignedOut : NativeResult
    data object EditProfile : NativeResult
    data class CheckoutComplete(val message: String) : NativeResult
    data class Failed(val message: String) : NativeResult
}
@Serializable private data class SavedHost(
    val account: String, val stack: List<Destination>, val tabs: Map<ScreenName, Destination>, val drafts: Map<String, FieldDraft>,
    val selectedTab: ScreenName = ScreenName.home,
    val pendingAdds: List<PendingAddIntent> = emptyList(),
)
@Serializable private data class FieldDraft(val edits: Map<String, String>, val errors: Map<String, String> = emptyMap())

/** One screen load and one mutation; every transition invalidates obsolete responses. */
class SduiViewModel(
    private val repository: SduiRepository,
    private val native: NativeSduiAdapter,
    scope: CoroutineScope? = null,
) : ViewModel() {
    private val scope = scope ?: viewModelScope
    private val mutableState = MutableStateFlow(HostState())
    val state: StateFlow<HostState> = mutableState.asStateFlow()
    private val json = Json { ignoreUnknownKeys = true }
    private var account = native.accountId()
    val stateOwner: String get() = account
    private var stack = listOf(Destination(ScreenName.home))
    private val tabs = mutableMapOf<ScreenName, Destination>()
    private val drafts = linkedMapOf<String, FieldDraft>()
    private var baseline = emptyMap<String, String>()
    private var nativeProfileError: SduiError? = null
    private val pendingAdds = linkedMapOf<String, PendingAddIntent>()
    private var loadJob: Job? = null
    private var mutationJob: Job? = null
    private var logoutJob: Job? = null
    private var signingOut = false
    private var generation = 0
    private var pending: Action? = null
    private var initialized = false
    private var selectedTab = ScreenName.home

    init {
        this.scope.launch {
            native.accountChanges.collect { changed ->
                if (signingOut) return@collect
                if (changed != account) {
                    clearAccount()
                    if (changed.isBlank()) native.authenticationLost() else start()
                }
            }
        }
    }

    fun start() { if (!initialized && !signingOut) { initialized = true; fetch(state.value.destination) } }
    fun open(destination: Destination) {
        if (signingOut) return
        validateDestination(destination)
        checkAccount()
        saveDraft()
        if (destination.screen in tabScreens) selectedTab = destination.screen
        else if (destination.screen in setOf(ScreenName.orders, ScreenName.order, ScreenName.devices)) selectedTab = ScreenName.profile
        if (destination != state.value.destination) {
            stack = if (destination.screen == state.value.destination.screen) stack.dropLast(1) + destination else (stack + destination).takeLast(32)
        }
        if (destination.screen in tabScreens) tabs[destination.screen] = destination
        transition(destination)
    }
    fun selectTab(screen: ScreenName) {
        if (signingOut) return
        require(screen in tabScreens)
        saveDraft()
        selectedTab = screen
        val destination = tabs[screen] ?: Destination(screen)
        stack = listOf(destination)
        transition(destination)
    }
    fun back() {
        if (signingOut) return
        if (state.value.editProfile) { nativeProfileCancelled(); return }
        if (stack.size > 1) {
            saveDraft(); stack = stack.dropLast(1)
            selectedTab = stack.lastOrNull { it.screen in tabScreens }?.screen ?: ScreenName.profile
            transition(stack.last())
        } else if (state.value.destination.screen !in tabScreens) {
            stack = listOf(Destination(ScreenName.profile)); transition(stack.last())
        }
    }
    private fun transition(destination: Destination) {
        leave()
        initialized = true
        baseline = emptyMap()
        val draft = drafts[key(destination)]
        mutableState.value = HostState(destination = destination, selectedTab = selectedTab, fields = draft?.edits.orEmpty(),
            fieldErrors = draft?.errors.orEmpty(), pendingAdds = pendingAdds.values.toList(), canGoBack = stack.size > 1 || destination.screen !in tabScreens)
        fetch(destination)
    }
    fun nativeProfileSaved() {
        if (!state.value.editProfile) return
        invalidateProfileDrafts()
        nativeProfileError = null
        mutableState.value = state.value.copy(editProfile = false, document = null,
            fields = state.value.fields.filterKeys { !it.startsWith("profile.") },
            fieldErrors = state.value.fieldErrors.filterKeys { !it.startsWith("profile.") }, error = null)
        fetch(state.value.destination)
    }
    private fun nativeProfileCancelled() {
        mutableState.value = state.value.copy(editProfile = false, error = nativeProfileError)
        nativeProfileError = null
        saveDraft()
        fetch(state.value.destination, preserveError = true)
    }
    fun leave() {
        initialized = false
        generation++
        loadJob?.cancel(); loadJob = null
        mutationJob?.cancel(); mutationJob = null
        logoutJob?.cancel()
        pending = null
        mutableState.value = state.value.copy(actionInProgress = false, confirmation = null)
    }
    fun event(event: SduiEvent) {
        if (signingOut) return
        if (checkAccount()) return
        when (event) {
            SduiEvent.Back -> back()
            SduiEvent.Retry -> if (!state.value.actionInProgress) fetch(state.value.destination)
            SduiEvent.ReviewCart -> if (!state.value.actionInProgress && pending == null) open(Destination(ScreenName.cart))
            is SduiEvent.RecoverAdd -> if (!state.value.actionInProgress && pending == null) {
                pendingAdds[event.intentKey]?.let { mutate(Command.AddToCart(it.input), it) }
            }
            is SduiEvent.DiscardAddRecovery -> if (!state.value.actionInProgress && pending == null) resolveAdd(event.intentKey)
            SduiEvent.DismissConfirmation -> { pending = null; mutableState.value = state.value.copy(confirmation = null) }
            SduiEvent.Confirm -> {
                val action = pending ?: return
                pending = null; mutableState.value = state.value.copy(confirmation = null)
                perform(action, confirmed = true)
            }
            else -> {
                if (state.value.actionInProgress || pending != null) return
                val id = when (event) {
                    is SduiEvent.Activate -> event.actionId
                    is SduiEvent.FieldChanged -> event.actionId
                    is SduiEvent.QuantityChanged -> event.actionId
                    else -> return
                }
                val action = state.value.document?.actions?.firstOrNull { it.id == id } ?: return
                when (event) {
                    is SduiEvent.FieldChanged -> if (action is Action.UpdateField) updateField(action.fieldId, event.value)
                    is SduiEvent.QuantityChanged -> when (action) {
                        is Action.UpdateField -> updateField(action.fieldId, event.value.toString())
                        is Action.Cart -> if (action.type == CartKind.UpdateQuantity) {
                            val item = state.value.document?.nodes()?.filterIsInstance<Node.CartItem>()?.firstOrNull { it.props.quantityAction == id }
                            if (item != null && event.value in 0..1_000_000 && (event.value <= item.props.quantity || event.value <= item.props.stock)) {
                                mutate(Command.UpdateQuantity(QuantityInput(action.productId, event.value)))
                            }
                        }
                        else -> Unit
                    }
                    else -> perform(action)
                }
            }
        }
    }

    private fun updateField(id: String, value: String) {
        if (id !in state.value.fields) return
        val node = state.value.document?.nodes()?.filterIsInstance<Node.Field>()?.firstOrNull { it.props.fieldId == id }
        if (node?.props?.readOnly == true) return
        val stepper = state.value.document?.nodes()?.filterIsInstance<Node.QuantityStepper>()?.firstOrNull { it.props.fieldId == id }
        val invalid = when {
            node != null -> value.codePointLength() > node.props.maxLength || node.props.maxUtf8Bytes?.let { value.encodeToByteArray().size > it } == true
            stepper != null -> value.toIntOrNull() !in stepper.props.min..stepper.props.max
            else -> value.codePointLength() > 2000
        }
        if (invalid) { mutableState.value = state.value.copy(fieldErrors = state.value.fieldErrors + (id to "Недопустимое значение")); saveDraft(); return }
        mutableState.value = state.value.copy(fields = state.value.fields + (id to value), fieldErrors = state.value.fieldErrors - id)
        saveDraft()
    }
    private fun perform(action: Action, confirmed: Boolean = false) {
        if (state.value.actionInProgress) return
        try {
            when (action) {
                is Action.Unknown, is Action.UpdateField -> Unit
                is Action.Route -> if (action.type == RouteKind.Navigate) open(action.destination) else fetch(state.value.destination)
                is Action.Product -> open(Destination(ScreenName.product, id = action.productId))
                is Action.Order -> open(Destination(ScreenName.order, id = action.orderId))
                is Action.SubmitForm -> {
                    validateForm(action.formId)
                    when (action.formId) {
                        FormId.catalog -> open(Forms.catalog(state.value.fields, state.value.document?.state?.collections?.categories?.let { CollectionQuery(it.page, it.size) } ?: CollectionQuery()))
                        FormId.orders -> open(Forms.orders(state.value.fields))
                        FormId.profile -> mutate(Command.UpdateProfile(Forms.profile(state.value.fields)))
                        FormId.checkout -> Unit
                    }
                }
                is Action.AddToCart -> {
                    val quantity = action.quantityField?.let { state.value.fields[it]?.toIntOrNull() ?: error("quantity") } ?: 1
                    if (pendingAdds.isNotEmpty() && !confirmed) {
                        confirm(action, Confirmation("Добавить как новую покупку?",
                            "Результат прежнего добавления неизвестен. Проверьте корзину. Для того же запроса выберите «Восстановить прежний запрос». Новая покупка получит новый ключ и может добавить товар ещё раз.",
                            "Новая покупка"))
                    } else mutate(Command.AddToCart(AddInput(action.productId, quantity)))
                }
                is Action.Cart -> if (action.type == CartKind.RemoveFromCart) mutate(Command.RemoveFromCart(ProductInput(action.productId)))
                is Action.CancelOrder -> if (confirmed) mutate(Command.CancelOrder(CancelInput(action.orderId, true))) else confirm(action, action.confirmation)
                is Action.RevokeDevice -> if (confirmed) mutate(Command.RevokeDevice(RevokeInput(action.deviceId, action.deviceKind, true))) else confirm(action, action.confirmation)
                is Action.Checkout -> {
                    validateForm(FormId.checkout)
                    val input = Forms.checkout(state.value.fields)
                    if (confirmed) nativeMutation { native.checkout(input) }
                    else confirm(action, Confirmation("Оформить заказ?", "Проверьте адрес доставки и способ оплаты. Итоговая стоимость подтверждается магазином.", "Оформить"))
                }
                is Action.Native -> {
                    if (action.type == NativeKind.EditProfile) { nativeProfileError = state.value.error; saveDraft() }
                    if (action.type == NativeKind.Logout) logout() else nativeMutation { native.execute(action.type) }
                }
            }
        } catch (_: IllegalArgumentException) { validationError()
        } catch (_: IllegalStateException) { validationError() }
    }
    private fun validateForm(formId: FormId) {
        val fields = state.value.document?.nodes()?.filterIsInstance<Node.Field>().orEmpty().filter { it.props.fieldId.startsWith("$formId.") }
        val errors = fields.filter { !Forms.validateField(it.props, state.value.fields[it.props.fieldId].orEmpty()) }
            .associate { it.props.fieldId to "Проверьте значение" }
        mutableState.value = state.value.copy(fieldErrors = errors)
        saveDraft()
        require(errors.isEmpty())
    }
    private fun validationError() { mutableState.value = state.value.copy(error = SduiError.Http(400, "LOCAL_VALIDATION", "Проверьте поля формы")) }
    private fun confirm(action: Action, confirmation: Confirmation) { pending = action; mutableState.value = state.value.copy(confirmation = confirmation) }

    private fun mutate(command: Command, recovery: PendingAddIntent? = null) {
        validateCommand(command)
        if (state.value.actionInProgress) return
        if (command is Command.AddToCart && recovery == null && pendingAdds.size >= 16) {
            mutableState.value = state.value.copy(notice = "Сначала проверьте корзину и завершите восстановление прежних добавлений.")
            return
        }
        val intent = if (command is Command.AddToCart) recovery ?: PendingAddIntent(repository.newIntentKey(), command.input) else null
        val key = intent?.key
        if (intent != null) pendingAdds[intent.key] = intent
        mutableState.value = state.value.copy(actionInProgress = true, error = null, pendingAdds = pendingAdds.values.toList())
        loadJob?.cancel()
        val token = ++generation
        val source = state.value.destination
        mutationJob = scope.launch {
            try {
                val result = repository.execute(command, key)
                if (token != generation || checkAccount()) return@launch
                mutationJob = null
                mutableState.value = state.value.copy(actionInProgress = false)
                when (result) {
                    is SduiResult.Success -> {
                        key?.let(::resolveAdd)
                        if (command is Command.UpdateProfile) {
                            invalidateProfileDrafts()
                            // The acknowledged submission is no longer an unsent edit.
                            baseline = baseline + state.value.fields.filterKeys { it.startsWith("profile.") }
                            mutableState.value = state.value.copy(fieldErrors = state.value.fieldErrors.filterKeys { !it.startsWith("profile.") })
                        }
                        runCatching { native.commandApplied(command) }
                        val target = if (source.screen == ScreenName.cart && result.value.refresh.screen == ScreenName.cart) source else result.value.refresh
                        open(target)
                    }
                    is SduiResult.Failure -> {
                        if ((result.error as? SduiError.Http)?.status in 400..499) key?.let(::resolveAdd)
                        fail(result.error)
                        if (result.error != SduiError.Unauthorized) fetch(source, preserveError = true)
                    }
                }
            } catch (e: CancellationException) { throw e
            } catch (_: Exception) { if (token == generation) fail(SduiError.Network)
            } finally { if (token == generation) mutableState.value = state.value.copy(actionInProgress = false) }
        }
    }
    private fun resolveAdd(key: String) {
        pendingAdds.remove(key)
        mutableState.value = state.value.copy(pendingAdds = pendingAdds.values.toList())
    }
    private fun nativeMutation(operation: suspend () -> NativeResult) {
        if (state.value.actionInProgress) return
        mutableState.value = state.value.copy(actionInProgress = true, error = null)
        val token = generation
        mutationJob = scope.launch {
            try {
                val result = operation()
                if (token != generation) return@launch
                mutationJob = null
                mutableState.value = state.value.copy(actionInProgress = false)
                when (result) {
                    NativeResult.SignedOut -> { clearAccount(); native.authenticationLost() }
                    NativeResult.EditProfile -> mutableState.value = state.value.copy(editProfile = true)
                    NativeResult.Completed -> fetch(state.value.destination)
                    is NativeResult.Failed -> mutableState.value = state.value.copy(notice = result.message)
                    is NativeResult.CheckoutComplete -> {
                        drafts.keys.filter { "\"cart\"" in it }.forEach(drafts::remove)
                        open(Destination(ScreenName.orders)); mutableState.value = state.value.copy(notice = result.message)
                    }
                }
            } catch (e: CancellationException) { throw e
            } catch (_: Exception) { if (token == generation) mutableState.value = state.value.copy(notice = "Не удалось завершить действие. Проверьте состояние перед повтором.")
            } finally { if (token == generation) mutableState.value = state.value.copy(actionInProgress = false) }
        }
    }
    private fun logout() {
        if (signingOut) return
        signingOut = true
        loadJob?.cancel()
        generation++
        mutableState.value = state.value.copy(actionInProgress = true, confirmation = null)
        // Enter AuthService immediately. Its local cleanup and this root transition
        // are finally blocks, independent of screen generation or scope cancellation.
        logoutJob = scope.launch(start = CoroutineStart.UNDISPATCHED) {
            try {
                native.execute(NativeKind.Logout)
            } catch (error: CancellationException) {
                throw error
            } catch (_: Exception) {
                // Remote revocation is best effort; local sign-out still completes.
            } finally {
                logoutJob = null
                clearAccount()
                signingOut = false
                native.authenticationLost()
            }
        }
    }
    private fun fetch(destination: Destination, preserveError: Boolean = false) {
        loadJob?.cancel()
        val token = ++generation
        mutableState.value = state.value.copy(phase = ScreenPhase.Loading, error = if (preserveError) state.value.error else null)
        loadJob = scope.launch {
            val result = repository.load(destination, native.deviceId())
            if (token != generation || checkAccount()) return@launch
            when (result) {
                is SduiResult.Success -> {
                    val screen = result.value
                    if (screen.metadata.accountId.orEmpty() != account) { fail(SduiError.Unauthorized); return@launch }
                    val effective = destination.copy(
                        categories = screen.state.collections?.categories?.let { CollectionQuery(it.page, it.size) } ?: destination.categories,
                        cart = screen.state.collections?.cart?.let { CollectionQuery(it.page, it.size) } ?: destination.cart,
                    )
                    baseline = screen.state.fields
                    val draft = drafts[key(destination)]
                    val fields = baseline + draft?.edits.orEmpty().filterKeys { it in baseline }
                    stack = stack.dropLast(1) + effective
                    mutableState.value = state.value.copy(destination = effective, document = screen, fields = fields,
                        fieldErrors = draft?.errors.orEmpty().filterKeys { it in baseline },
                        phase = if (screen.components.isEmpty() || screen.nodes().any { it is Node.Status && it.type == StatusKind.Empty }) ScreenPhase.Empty else ScreenPhase.Content)
                    saveDraft()
                }
                is SduiResult.Failure -> fail(result.error)
            }
        }
    }
    private fun fail(error: SduiError) {
        if (error == SduiError.Unauthorized) { clearAccount(); native.authenticationLost() }
        else mutableState.value = state.value.copy(error = error, phase = if (state.value.document == null) ScreenPhase.Error else state.value.phase.takeUnless { it == ScreenPhase.Loading } ?: ScreenPhase.Content)
    }
    private fun checkAccount(): Boolean {
        if (native.accountId() == account) return false
        clearAccount(); return true
    }
    private fun clearAccount() {
        leave(); account = native.accountId(); drafts.clear(); pendingAdds.clear(); tabs.clear()
        baseline = emptyMap(); nativeProfileError = null
        selectedTab = ScreenName.home
        stack = listOf(Destination(ScreenName.home)); mutableState.value = HostState()
    }
    private fun key(destination: Destination) = json.encodeToString(destination)
    private fun saveDraft() {
        if (state.value.destination.screen in tabScreens) tabs[state.value.destination.screen] = state.value.destination
        val edits = state.value.fields.filter { (id, value) -> value != baseline[id] }
        val destinationKey = key(state.value.destination)
        if (edits.isEmpty() && state.value.fieldErrors.isEmpty()) drafts.remove(destinationKey)
        else drafts[destinationKey] = FieldDraft(edits, state.value.fieldErrors)
        while (drafts.size > 32) drafts.remove(drafts.keys.first())
    }
    private fun invalidateProfileDrafts() {
        drafts.keys.toList().forEach { destination ->
            val draft = drafts.getValue(destination)
            val remaining = FieldDraft(draft.edits.filterKeys { !it.startsWith("profile.") }, draft.errors.filterKeys { !it.startsWith("profile.") })
            if (remaining.edits.isEmpty() && remaining.errors.isEmpty()) drafts.remove(destination) else drafts[destination] = remaining
        }
    }
    fun save(): String { saveDraft(); return json.encodeToString(SavedHost(account, stack, tabs, drafts, selectedTab, pendingAdds.values.toList())) }
    fun restore(value: String) {
        if (initialized || value.isBlank() || value.encodeToByteArray().size > 262144) return
        val saved = runCatching { json.decodeFromString<SavedHost>(value) }.getOrNull() ?: return
        if (saved.account != native.accountId() || saved.stack.isEmpty() || saved.stack.size > 32 || saved.drafts.size > 32 || saved.selectedTab !in tabScreens || saved.pendingAdds.size > 16) return
        if (runCatching {
                saved.stack.forEach(::validateDestination); saved.tabs.values.forEach(::validateDestination)
                require(saved.pendingAdds.map { it.key }.toSet().size == saved.pendingAdds.size)
                saved.pendingAdds.forEach { require(it.key.length in 1..255 && it.key.all { c -> c.code in 33..126 }); validateCommand(Command.AddToCart(it.input)) }
            }.isFailure) return
        account = saved.account; stack = saved.stack; selectedTab = saved.selectedTab; tabs.putAll(saved.tabs)
        pendingAdds.putAll(saved.pendingAdds.associateBy { it.key })
        drafts.putAll(saved.drafts.mapValues { (_, draft) -> FieldDraft(
            draft.edits.filter { it.key in fieldIds && it.value.codePointLength() <= 2000 },
            draft.errors.filter { it.key in fieldIds && it.value.codePointLength() <= 2000 },
        ) })
        transition(stack.last())
    }
    companion object { val tabScreens = setOf(ScreenName.home, ScreenName.catalog, ScreenName.cart, ScreenName.profile) }
}
