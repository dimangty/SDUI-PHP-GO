package com.shopkmp.uikit.component.dialog.bottomsheet

import com.shopkmp.uikit.component.dialog.UiDialogEffect

sealed class EpsBottomSheetState : UiDialogEffect {
    data class ApiErrorBottomSheet(
        val title: String = "",
        val subtitle: String = "",
        val bottomButtonTitle: String = "",
    ) : EpsBottomSheetState()

    data class GenericBottomSheet(
        val title: String = "",
        val items: List<String> = emptyList(),
    ) : EpsBottomSheetState()
}
