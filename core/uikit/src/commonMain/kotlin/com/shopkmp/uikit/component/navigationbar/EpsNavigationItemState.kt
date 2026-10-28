package com.shopkmp.uikit.component.navigationbar

import com.shopkmp.uikit.component.icon.EpsIconName

data class EpsNavigationItemState(
    val text: String,
    val selected: Boolean,
    val testTag: String = "",
    val icon: EpsIconName? = null,
    val onClick: () -> Unit,
) {
    constructor(text: String, selected: Boolean, testTag: String, onClick: () -> Unit) :
        this(text, selected, testTag, null, onClick)
}
