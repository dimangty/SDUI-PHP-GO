package com.shopkmp.uikit.component.chip

data class EpsChipState(
    val text: String,
    val selected: Boolean = false,
    val onClick: (() -> Unit)? = null,
)
