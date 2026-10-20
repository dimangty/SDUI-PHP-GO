package com.shopkmp.mvvm.common

interface UiEvent

interface SingleClickUiEvent : UiEvent

sealed class DefaultUiEvent : UiEvent {
    data object OnScreenCreated : DefaultUiEvent()
    data object OnScreenStarted : DefaultUiEvent()
    data object OnScreenResumed : DefaultUiEvent()
    data object OnBackClicked : DefaultUiEvent(), SingleClickUiEvent
    data object OnNotificationsClicked : DefaultUiEvent(), SingleClickUiEvent
}
