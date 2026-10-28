package com.shopkmp.uikit.component.topbar

import com.shopkmp.uikit.component.icon.EpsIconName

data class EpsTopBarActionState(
    val icon: EpsIconName,
    val contentDescription: String,
    val testTag: String,
    val enabled: Boolean = true,
    val onClick: () -> Unit,
)
