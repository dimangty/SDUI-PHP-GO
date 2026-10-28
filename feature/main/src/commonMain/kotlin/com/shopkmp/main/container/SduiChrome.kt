package com.shopkmp.main.container

import androidx.compose.runtime.Composable
import com.shopkmp.sdui.model.Action
import com.shopkmp.sdui.model.NativeKind
import com.shopkmp.sdui.model.Node
import com.shopkmp.sdui.model.RouteKind
import com.shopkmp.sdui.model.ScreenDocument
import com.shopkmp.sdui.model.ScreenName
import com.shopkmp.sdui.model.TextStyle
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.topbar.EpsTopBar
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import com.shopkmp.uikit.component.topbar.EpsTopBarActionState

internal data class ChromeAction(val nodeId: String, val actionId: String, val icon: EpsIconName, val label: String, val enabled: Boolean)
internal data class SduiChromePlan(val title: String, val titleNodeId: String, val nodeIds: Set<String>, val actions: List<ChromeAction>)

/** Promote actual typed nodes only. Body titles (especially product names) stay in the body. */
internal fun sduiChromePlan(document: ScreenDocument?, screen: ScreenName): SduiChromePlan {
    val heading = document?.nodes()?.filterIsInstance<Node.Text>()?.firstOrNull {
        it.props.style == TextStyle.ScreenTitle && screen != ScreenName.product
    }
    val actions = mutableListOf<ChromeAction>()
    fun promote(icon: EpsIconName, label: String, matches: (Action) -> Boolean) {
        val button = document?.nodes()?.filterIsInstance<Node.Button>()?.firstOrNull { button ->
            document.actions.any { it.id == button.props.actionId && matches(it) }
        } ?: return
        actions += ChromeAction(button.id, button.props.actionId, icon, label, button.props.enabled)
    }
    when (screen) {
        ScreenName.home -> {
            promote(EpsIconName.Search, "Поиск") { it is Action.Route && it.type == RouteKind.Navigate && it.destination.screen == ScreenName.catalog }
            promote(EpsIconName.Profile, "Профиль") { it is Action.Route && it.type == RouteKind.Navigate && it.destination.screen == ScreenName.profile || it is Action.Native && it.type == NativeKind.Login }
        }
        ScreenName.profile -> promote(EpsIconName.Edit, "Редактировать профиль") { it is Action.Native && it.type == NativeKind.EditProfile }
        else -> Unit
    }
    val fallback = when (screen) {
        ScreenName.home -> "ShopKMP"; ScreenName.catalog -> "Каталог"; ScreenName.product -> "Товар"
        ScreenName.cart -> "Корзина"; ScreenName.orders -> "Мои заказы"; ScreenName.order -> "Заказ"
        ScreenName.profile -> "Профиль"; ScreenName.devices -> "Устройства"
    }
    val title = when (screen) { ScreenName.home, ScreenName.product -> fallback; else -> heading?.props?.text ?: document?.metadata?.title ?: fallback }
    return SduiChromePlan(title, heading?.id.orEmpty(), actions.map { it.nodeId }.toSet() + listOfNotNull(heading?.id), actions)
}

@Composable
internal fun SduiChrome(plan: SduiChromePlan, canGoBack: Boolean, busy: Boolean, onBack: () -> Unit, onEvent: (SduiEvent) -> Unit) {
    EpsTopBar(EpsTopBarState(
        title = plan.title, titleTestTag = plan.titleNodeId.ifEmpty { "sdui.chrome.title" },
        showBackButton = canGoBack, backTestTag = "nav.back", onBackClick = onBack,
        actions = plan.actions.map { action -> EpsTopBarActionState(action.icon, action.label, action.nodeId,
            enabled = !busy && action.enabled, onClick = { onEvent(SduiEvent.Activate(action.actionId)) }) } +
            EpsTopBarActionState(EpsIconName.Refresh, "Обновить", "nav.refresh", enabled = !busy, onClick = { onEvent(SduiEvent.Retry) }),
    ))
}
