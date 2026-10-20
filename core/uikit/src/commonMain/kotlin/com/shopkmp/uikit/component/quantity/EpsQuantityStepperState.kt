package com.shopkmp.uikit.component.quantity

data class EpsQuantityStepperState(
    val value: Int = 0,
    val decreaseEnabled: Boolean = true,
    val increaseEnabled: Boolean = true,
    val onDecrease: () -> Unit = {},
    val onIncrease: () -> Unit = {},
)
