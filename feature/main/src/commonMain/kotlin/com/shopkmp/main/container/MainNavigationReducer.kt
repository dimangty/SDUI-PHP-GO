package com.shopkmp.main.container

internal class MainNavigationReducer {
    fun reduce(state: MainContainerState, event: MainContainerUiEvent): MainContainerState =
        when (event) {
            is MainContainerUiEvent.OnTabSelected -> state.copy(
                selectedTab = event.tab,
                selectedProductId = null,
                profileDestination = ProfileDestination.Overview,
            )
            is MainContainerUiEvent.OnProductSelected -> state.copy(
                selectedProductId = event.productId,
            )
            MainContainerUiEvent.OnProductBack -> state.copy(selectedProductId = null)
            MainContainerUiEvent.OnProductAddedToCart -> state.copy(
                selectedTab = MainTab.Cart,
                selectedProductId = null,
            )
            is MainContainerUiEvent.OnCatalogRequested -> state.copy(
                selectedTab = MainTab.Catalog,
                selectedProductId = null,
                catalogQuery = event.query,
                catalogCategory = event.category,
            )
            is MainContainerUiEvent.OnProfileDestination -> state.copy(
                selectedTab = MainTab.Profile,
                profileDestination = event.destination,
            )
            MainContainerUiEvent.OnChangePin,
            MainContainerUiEvent.OnLogoutCompleted,
            -> state
        }
}
