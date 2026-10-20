package com.shopkmp.uikit.component.topbar

data class EpsTopBarState(
    val title: String = "",
    val isVisible: Boolean = true,
    val showBackButton: Boolean = false,
    val showNotificationsButton: Boolean = false,
    val onBackClick: (() -> Unit)? = null,
    val onNotificationsClick: (() -> Unit)? = null,
) {
    companion object {
        fun getMock() = EpsTopBarState(title = "Mock Title")
    }
}
