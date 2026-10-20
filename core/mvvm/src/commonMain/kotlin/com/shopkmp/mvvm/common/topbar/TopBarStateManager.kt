package com.shopkmp.mvvm.common.topbar

import com.shopkmp.mvvm.common.DefaultUiEvent
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

class TopBarStateManager {
    private val _topBarState = MutableStateFlow(EpsTopBarState())
    val topBarState = _topBarState.asStateFlow()

    private val _defaultUiEvent = MutableSharedFlow<DefaultUiEvent>()
    val defaultUiEvent = _defaultUiEvent.asSharedFlow()

    suspend fun initTopBar(action: suspend () -> EpsTopBarState) {
        _topBarState.update { action() }
    }

    suspend fun updateTopBar(action: suspend EpsTopBarState.() -> EpsTopBarState) {
        _topBarState.update { action(topBarState.value) }
    }
}
