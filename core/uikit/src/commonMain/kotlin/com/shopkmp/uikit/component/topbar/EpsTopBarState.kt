package com.shopkmp.uikit.component.topbar

data class EpsTopBarState(
    val title: String = "",
    val isVisible: Boolean = true,
    val showBackButton: Boolean = false,
    val showNotificationsButton: Boolean = false,
    val onBackClick: (() -> Unit)? = null,
    val onNotificationsClick: (() -> Unit)? = null,
    val titleTestTag: String = "",
    val backTestTag: String = "",
    val actions: List<EpsTopBarActionState> = emptyList(),
) {
    // The original final callback can still be supplied as a trailing lambda.
    constructor(
        title: String = "",
        isVisible: Boolean = true,
        showBackButton: Boolean = false,
        showNotificationsButton: Boolean = false,
        onBackClick: (() -> Unit)? = null,
        onNotificationsClick: () -> Unit,
    ) : this(title, isVisible, showBackButton, showNotificationsButton, onBackClick,
        onNotificationsClick, "", "", emptyList())

    companion object {
        fun getMock() = EpsTopBarState(title = "Mock Title")
    }
}
