package com.shopkmp.main

import com.shopkmp.mvvm.common.UiEvent

sealed class MainUiEvent : UiEvent {
    data object OnAppUiStarted : MainUiEvent()
    data object OnAppUiStopped : MainUiEvent()
}
