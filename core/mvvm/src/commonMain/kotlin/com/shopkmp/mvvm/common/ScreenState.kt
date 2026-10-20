package com.shopkmp.mvvm.common

import com.shopkmp.common.device.OsType
import com.shopkmp.uikit.component.fullscreenerror.EpsFullscreenErrorState
import com.shopkmp.uikit.component.topbar.EpsTopBarState

data class ScreenState<T>(
    val state: T,
    val topBarState: EpsTopBarState = EpsTopBarState(),
    val lceState: LceState = LceState(),
)

data class LceState(
    val isUiInitialized: Boolean = false,
    val isLoading: Boolean = false,
    val isRootScreen: Boolean = false,
    val osType: OsType = OsType.Android,
    val errorState: EpsFullscreenErrorState? = null,
)
