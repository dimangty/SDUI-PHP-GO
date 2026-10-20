package com.shopkmp.uikit.component.checkbox

data class EpsCheckboxState(
    val label: String = "",
    val checked: Boolean = false,
    val onCheckedChange: ((Boolean) -> Unit)? = null,
)
