package com.shopkmp.uikit.component.dialog.alert

import com.shopkmp.uikit.component.dialog.UiDialogEffect

data class AlertDialogState(
    val title: String = "",
    val message: String = "",
    val confirmText: String = "OK",
    val dismissText: String? = null,
    val onConfirm: (() -> Unit)? = null,
    val onDismiss: (() -> Unit)? = null,
) : UiDialogEffect
