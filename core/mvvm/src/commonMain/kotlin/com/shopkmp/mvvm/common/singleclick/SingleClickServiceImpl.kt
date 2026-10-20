package com.shopkmp.mvvm.common.singleclick

import com.shopkmp.mvvm.common.SingleClickUiEvent
import com.shopkmp.mvvm.common.UiEvent
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

class SingleClickServiceImpl : SingleClickService {
    private val mutex = Mutex()
    private var lastClickTime = 0L
    private val debounceMs = 300L

    override fun isClickAllowed(event: UiEvent): Boolean {
        if (event !is SingleClickUiEvent) return true
        val now = currentTimeMillis()
        if (now - lastClickTime < debounceMs) return false
        lastClickTime = now
        return true
    }
}

internal expect fun currentTimeMillis(): Long
