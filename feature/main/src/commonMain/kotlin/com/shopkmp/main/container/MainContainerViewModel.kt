package com.shopkmp.main.container

import com.shopkmp.mvvm.BaseViewModel
import com.shopkmp.mvvm.BaseViewModelDependencies
import com.shopkmp.mvvm.resolveBaseViewModelDependencies
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.uikit.component.navigationbar.EpsNavigationItemState

/**
 * Owns only application navigation and shared session transitions. Screen data,
 * actions, pagination, and presentation mapping live in their feature modules.
 */
class MainContainerViewModel(
    dependencies: BaseViewModelDependencies = resolveBaseViewModelDependencies(),
) : BaseViewModel<MainContainerUiEvent, MainContainerState>(
    MainContainerState(),
    dependencies,
) {
    private val reducer = MainNavigationReducer()

    init {
        updateState { withNavigation() }
    }

    override fun processUiEvent(event: MainContainerUiEvent) {
        when (event) {
            is MainContainerUiEvent.OnTabSelected,
            is MainContainerUiEvent.OnProductSelected,
            MainContainerUiEvent.OnProductBack,
            MainContainerUiEvent.OnProductAddedToCart,
            is MainContainerUiEvent.OnCatalogRequested,
            is MainContainerUiEvent.OnProfileDestination,
            -> updateState { reducer.reduce(this, event).withNavigation() }
            MainContainerUiEvent.OnChangePin -> {
                navigate(NavigationAction.NavigateToChangePin)
            }
            MainContainerUiEvent.OnLogoutCompleted -> {
                replaceRoot(NavigationAction.NavigateToLogin)
            }
        }
    }

    private fun MainContainerState.withNavigation() = copy(
        navigationItems = MainTab.entries.map { tab ->
            EpsNavigationItemState(
                text = tab.title,
                selected = selectedTab == tab,
                onClick = { onUiEvent(MainContainerUiEvent.OnTabSelected(tab)) },
            )
        },
    )

    private val MainTab.title: String
        get() = when (this) {
            MainTab.Home -> "Главная"
            MainTab.Catalog -> "Каталог"
            MainTab.Cart -> "Корзина"
            MainTab.Profile -> "Профиль"
        }
}
