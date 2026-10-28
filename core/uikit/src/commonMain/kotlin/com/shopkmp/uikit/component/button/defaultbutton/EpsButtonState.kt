package com.shopkmp.uikit.component.button.defaultbutton

data class EpsButtonState(
    val text: String = "",
    val type: EpsButtonType = EpsButtonType.Primary,
    val enabled: Boolean = true,
    val destructive: Boolean = false,
    val onClick: (() -> Unit)? = null,
) {
    // Preserve the original four-positional-argument constructor and trailing lambdas.
    constructor(text: String, type: EpsButtonType, enabled: Boolean, onClick: (() -> Unit)?) :
        this(text, type, enabled, false, onClick)

    companion object {
        fun getMock() = EpsButtonState(text = "Button")
    }
}
