package com.shopkmp.uikit.component.navigationbar

data class EpsNavigationItemState(
    val text: String,
    val selected: Boolean,
    val testTag: String = "",
    val onClick: () -> Unit,
)
