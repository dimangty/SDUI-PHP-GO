package com.shopkmp.main.container

import com.shopkmp.mvvm.common.UiEvent

sealed interface MainContainerUiEvent : UiEvent {
    data class OnTabSelected(val tab: MainTab) : MainContainerUiEvent
    data class OnProductSelected(val productId: String) : MainContainerUiEvent
    data object OnProductBack : MainContainerUiEvent
    data object OnProductAddedToCart : MainContainerUiEvent
    data class OnCatalogRequested(
        val query: String = "",
        val category: String? = null,
    ) : MainContainerUiEvent
    data class OnProfileDestination(val destination: ProfileDestination) : MainContainerUiEvent
    data object OnChangePin : MainContainerUiEvent
    data object OnLogoutCompleted : MainContainerUiEvent
}
