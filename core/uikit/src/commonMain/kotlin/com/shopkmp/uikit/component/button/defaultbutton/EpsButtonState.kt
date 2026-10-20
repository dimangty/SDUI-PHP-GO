package com.shopkmp.uikit.component.button.defaultbutton

data class EpsButtonState(
    val text: String = "",
    val type: EpsButtonType = EpsButtonType.Primary,
    val enabled: Boolean = true,
    val onClick: (() -> Unit)? = null,
) {
    companion object {
        fun getMock() = EpsButtonState(text = "Button")
    }
}
