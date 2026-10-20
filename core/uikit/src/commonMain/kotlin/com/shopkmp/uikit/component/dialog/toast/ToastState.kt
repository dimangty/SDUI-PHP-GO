package com.shopkmp.uikit.component.dialog.toast

import com.shopkmp.uikit.component.dialog.UiDialogEffect

enum class ToastType { Success, Error }

data class ToastState(
    val type: ToastType = ToastType.Success,
    val message: String = "",
) : UiDialogEffect
