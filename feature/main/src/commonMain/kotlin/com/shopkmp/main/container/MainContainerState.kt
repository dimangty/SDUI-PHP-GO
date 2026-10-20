package com.shopkmp.main.container

import com.shopkmp.uikit.component.navigationbar.EpsNavigationItemState
import com.shopkmp.uikit.component.screen.EpsScreenSurfaceState

enum class MainTab {
    Home,
    Catalog,
    Cart,
    Profile,
}

enum class ProfileDestination {
    Overview,
    Edit,
    Orders,
    Devices,
}

data class MainContainerState(
    val selectedTab: MainTab = MainTab.Home,
    val selectedProductId: String? = null,
    val catalogQuery: String = "",
    val catalogCategory: String? = null,
    val profileDestination: ProfileDestination = ProfileDestination.Overview,
    val screenSurfaceState: EpsScreenSurfaceState = EpsScreenSurfaceState(),
    val navigationItems: List<EpsNavigationItemState> = emptyList(),
)
