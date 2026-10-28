package com.shopkmp.main.container

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.AlertDialog
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.saveable.rememberSaveableStateHolder
import androidx.compose.ui.Modifier
import androidx.compose.ui.backhandler.BackHandler
import androidx.compose.ui.unit.dp
import com.shopkmp.main.sdui.ScreenPhase
import com.shopkmp.main.sdui.SduiViewModel
import com.shopkmp.main.sdui.PendingAddIntent
import com.shopkmp.network.NetworkSettings
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.profile.ui.EditProfileScreen
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.sdui.model.ScreenName
import com.shopkmp.sdui.network.SduiError
import com.shopkmp.sdui.renderer.SduiScreen
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.loader.EpsLoader
import com.shopkmp.uikit.component.navigationbar.EpsNavigationItemState
import com.shopkmp.uikit.component.screen.EpsNavigationScreen
import com.shopkmp.uikit.component.screen.EpsScreenSurfaceState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle
import org.koin.compose.koinInject
import org.koin.compose.viewmodel.koinViewModel
import kotlinx.serialization.json.Json

@Composable
@OptIn(androidx.compose.ui.ExperimentalComposeUiApi::class)
fun MainContainerScreen() {
    val viewModel = koinViewModel<SduiViewModel>()
    val settings = koinInject<NetworkSettings>()
    val state by viewModel.state.collectAsState()
    val screenStates = key(viewModel.stateOwner) { rememberSaveableStateHolder() }
    var saved by rememberSaveable { mutableStateOf("") }
    var restored by remember(viewModel) { mutableStateOf(false) }
    LaunchedEffect(viewModel) { viewModel.restore(saved); viewModel.start(); restored = true }
    SideEffect { if (restored) saved = viewModel.save() }
    DisposableEffect(viewModel) { onDispose { viewModel.leave() } }
    BackHandler(enabled = state.canGoBack || state.editProfile) { viewModel.back() }
    if (state.editProfile) {
        val owner = viewModel.stateOwner
        val editorId = state.profileEditorId
        // Outside Scaffold: this branch owns system insets once; the editor bar has zero insets.
        Column(Modifier.fillMaxSize().background(ShopTokens.Background).statusBarsPadding().navigationBarsPadding()) {
            key(owner, editorId) {
                EditProfileScreen(accountKey = owner,
                    onBack = { viewModel.nativeProfileCancelled(owner, editorId) },
                    onSaved = { viewModel.nativeProfileSaved(owner, editorId) })
            }
        }
    } else EpsNavigationScreen(EpsScreenSurfaceState(), listOf(
        ScreenName.home to "Главная", ScreenName.catalog to "Каталог", ScreenName.cart to "Корзина", ScreenName.profile to "Профиль",
    ).map { (screen, title) -> EpsNavigationItemState(title, state.selectedTab == screen, "nav.${screen.name}") { viewModel.selectTab(screen) } }) {
        Column(Modifier.fillMaxSize()) {
            val chrome = sduiChromePlan(state.document, state.destination.screen)
            SduiChrome(chrome, state.canGoBack, state.actionInProgress || state.phase == ScreenPhase.Loading || state.confirmation != null,
                viewModel::back, viewModel::event)
            state.notice?.let { EpsText(EpsTextState(it), Modifier.padding(16.dp)) }
            state.error?.let { error ->
                EpsErrorMessage(when (error) {
                    is SduiError.Http -> error.message
                    SduiError.InvalidDocument -> "Этот экран пока недоступен. Обновите приложение или повторите запрос."
                    SduiError.Network -> "Не удалось получить результат. Проверьте соединение и обновите экран."
                    SduiError.Unauthorized -> "Войдите в аккаунт"
                }, Modifier.padding(16.dp))
                EpsButton(EpsButtonState("Повторить загрузку", enabled = !state.actionInProgress, onClick = { viewModel.event(SduiEvent.Retry) }))
            }
            Box(Modifier.weight(1f)) {
                state.document?.let { document ->
                    screenStates.SaveableStateProvider(Json.encodeToString(state.destination)) {
                        SduiScreen(document, state.fields, state.fieldErrors, state.actionInProgress || state.confirmation != null,
                            settings.serverUrl,
                            leadingContent = if (state.pendingAdds.isEmpty()) null else ({ PendingAddRecoveries(state.pendingAdds, state.actionInProgress, viewModel::event) }),
                            chromeNodeIds = chrome.nodeIds,
                            biometricAvailable = state.biometricAvailable,
                            biometricConfigured = state.biometricConfigured,
                            onEvent = viewModel::event)
                    }
                }
                if (state.document == null && state.pendingAdds.isNotEmpty()) LazyColumn {
                    item { PendingAddRecoveries(state.pendingAdds, state.actionInProgress, viewModel::event) }
                }
                if (state.phase == ScreenPhase.Loading || state.actionInProgress) EpsLoader()
            }
        }
    }
    state.confirmation?.let { confirmation ->
        AlertDialog(
            onDismissRequest = { viewModel.event(SduiEvent.DismissConfirmation) },
            title = { EpsText(EpsTextState(confirmation.title, EpsTextStyle.Title)) },
            text = { EpsText(EpsTextState(confirmation.message)) },
            confirmButton = { EpsButton(EpsButtonState(confirmation.confirmLabel, onClick = { viewModel.event(SduiEvent.Confirm) })) },
            dismissButton = { EpsButton(EpsButtonState("Назад", EpsButtonType.Text, onClick = { viewModel.event(SduiEvent.DismissConfirmation) })) },
        )
    }
}

@Composable
private fun PendingAddRecoveries(intents: List<PendingAddIntent>, busy: Boolean, onEvent: (SduiEvent) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        EpsText(EpsTextState("Есть добавление с неизвестным результатом", EpsTextStyle.Title))
        EpsText(EpsTextState("Сначала проверьте корзину. Восстановление повторяет прежний запрос с тем же количеством и ключом. Новая покупка — отдельное добавление."))
        EpsButton(EpsButtonState("Проверить корзину", enabled = !busy, onClick = { onEvent(SduiEvent.ReviewCart) }))
        intents.forEach { intent ->
            EpsText(EpsTextState("Товар ${intent.input.productId.take(8)} · количество ${intent.input.quantity}"))
            EpsButton(EpsButtonState("Восстановить прежний запрос", enabled = !busy, onClick = { onEvent(SduiEvent.RecoverAdd(intent.key)) }))
            EpsButton(EpsButtonState("Больше не восстанавливать", EpsButtonType.Text, !busy, { onEvent(SduiEvent.DiscardAddRecovery(intent.key)) }))
        }
    }
}
